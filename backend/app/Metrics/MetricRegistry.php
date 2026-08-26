<?php

namespace App\Metrics;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use InvalidArgumentException;
use Throwable;

/**
 * 存在 Redis 的指標登記處，並且能把內容輸出成 Prometheus 的文字格式。
 *
 * 為什麼不用行程記憶體：php-fpm 每個請求都是獨立行程，靜態計數器加到 1 就隨請求
 * 死掉。為什麼不用 APCu：那是節點內共享，而 queue-worker 是另一個容器、沒有 HTTP
 * 埠可以被 scrape，它的計數會完全沒有出口。
 *
 * 因此所有節點讀到的都是「整個叢集的總和」。這件事決定了 Prometheus 只能 scrape
 * 一個目標 —— 同時抓三個節點會把每個數字乘以三。
 */
class MetricRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $definitions;

    private string $connection;

    private string $namespace;

    /** @var array<string, array{help: string, resolver: Closure}> */
    private array $gauges = [];

    public function __construct(
        private readonly RedisFactory $redis,
        ?array $definitions = null,
        ?string $connection = null,
        ?string $namespace = null,
    ) {
        $this->definitions = $definitions ?? config('metrics.definitions', []);
        $this->connection = $connection ?? config('metrics.connection', 'metrics');
        $this->namespace = $namespace ?? config('metrics.namespace', 'app');
    }

    /**
     * 計數器 +1。
     *
     * 寫入失敗不會往外丟：指標壞掉不該讓報名失敗。這跟限流那裡的 fail-open 是同一個
     * 立場 —— 觀測是為了了解系統，不是系統的一部分。代價是 Redis 掛掉時圖表會是平的，
     * 而平的圖表看起來像「沒有流量」，不像「量測壞了」。/metrics 那端因此刻意讓錯誤
     * 冒出來（見 render），讓 Prometheus 把它記成 up=0。
     */
    public function increment(string $name, array $labels = [], int $by = 1): void
    {
        $field = $this->fieldFor($name, 'counter', $labels);

        $this->quietly(fn () => $this->conn()->hincrby($this->key('c', $name), $field, $by));
    }

    /**
     * 記一次觀測值（秒）。
     */
    public function observe(string $name, array $labels, float $value): void
    {
        $field = $this->fieldFor($name, 'histogram', $labels);
        $bucket = $this->bucketFor($name, $value);

        $this->quietly(function () use ($name, $field, $bucket, $value) {
            // 三個獨立的 HINCRBY，用 pipeline 併成一次來回。
            //
            // 不需要原子性：三者之間沒有必須同時成立的不變量，而 Prometheus 讀到
            // 「桶已加、count 還沒加」的瞬間狀態，下一次 scrape 就會自己修正。
            // 這裡省的是網路來回，不是正確性。
            $this->conn()->pipeline(function ($pipe) use ($name, $field, $bucket, $value) {
                $pipe->hincrby($this->key('hb', $name), $field."\0".$bucket, 1);
                $pipe->hincrbyfloat($this->key('hs', $name), $field, $value);
                $pipe->hincrby($this->key('hc', $name), $field, 1);
            });
        });
    }

    /**
     * 註冊一個「抓取當下才計算」的量測值，例如佇列積壓長度。
     *
     * 這種值不該用計數器累加：它描述的是此刻的狀態，不是發生過幾次。累加會讓它
     * 只增不減，而「積壓長度只增不減」是一句沒有意義的話。
     */
    public function gauge(string $name, string $help, Closure $resolver): void
    {
        $this->gauges[$name] = ['help' => $help, 'resolver' => $resolver];
    }

    /**
     * 輸出成 Prometheus 的文字格式。
     *
     * 這裡刻意不吞例外。回傳一份殘缺的指標並附上 200，會讓 Prometheus 認為抓取成功、
     * 而圖表上只是數字變小 —— 那是最難察覺的一種故障。讓它 500，Prometheus 就會把
     * 這次抓取記成 up=0，那是一個可以告警的訊號。
     */
    public function render(): string
    {
        $lines = [];

        foreach ($this->definitions as $name => $definition) {
            $lines[] = match ($definition['type']) {
                'counter' => $this->renderCounter($name, $definition),
                'histogram' => $this->renderHistogram($name, $definition),
                default => throw new InvalidArgumentException(
                    "指標 {$name} 的型別 {$definition['type']} 不支援。"
                ),
            };
        }

        foreach ($this->gauges as $name => $gauge) {
            $lines[] = $this->renderGauge($name, $gauge);
        }

        return implode('', array_filter($lines));
    }

    // --- 輸出 -----------------------------------------------------------------

    private function renderCounter(string $name, array $definition): string
    {
        $full = $this->namespace.'_'.$name;
        $values = $this->conn()->hgetall($this->key('c', $name));

        if ($values === []) {
            return '';
        }

        $out = $this->headers($full, $definition['help'], 'counter');

        foreach ($values as $field => $value) {
            $out .= $full.$this->labelsOf($field).' '.$this->num((int) $value)."\n";
        }

        return $out;
    }

    private function renderHistogram(string $name, array $definition): string
    {
        $full = $this->namespace.'_'.$name;
        $counts = $this->conn()->hgetall($this->key('hc', $name));

        if ($counts === []) {
            return '';
        }

        $sums = $this->conn()->hgetall($this->key('hs', $name));
        $buckets = $this->conn()->hgetall($this->key('hb', $name));

        $out = $this->headers($full, $definition['help'], 'histogram');

        foreach ($counts as $field => $count) {
            $labels = $this->labelsOf($field);

            // 儲存時每次觀測只加「它落在的那一格」，這裡才累加成 Prometheus 要的
            // 累積分佈。反過來做（存的時候就加進所有更大的桶）會讓每次觀測變成
            // 十幾次寫入，而那是在請求路徑上。
            $running = 0;

            foreach ($definition['buckets'] as $le) {
                $running += (int) ($buckets[$field."\0".$this->num($le)] ?? 0);
                $out .= $full.'_bucket'.$this->labelsOf($field, ['le' => $this->num($le)])
                    .' '.$running."\n";
            }

            $running += (int) ($buckets[$field."\0+Inf"] ?? 0);
            $out .= $full.'_bucket'.$this->labelsOf($field, ['le' => '+Inf']).' '.$running."\n";
            $out .= $full.'_sum'.$labels.' '.$this->num((float) ($sums[$field] ?? 0))."\n";
            $out .= $full.'_count'.$labels.' '.$this->num((int) $count)."\n";
        }

        return $out;
    }

    private function renderGauge(string $name, array $gauge): string
    {
        $full = $this->namespace.'_'.$name;

        return $this->headers($full, $gauge['help'], 'gauge')
            .$full.' '.$this->num(($gauge['resolver'])())."\n";
    }

    private function headers(string $full, string $help, string $type): string
    {
        // HELP 的內容會原樣出現在 Prometheus 與 Grafana 的介面上，換行會破壞格式。
        $help = str_replace(['\\', "\n"], ['\\\\', ' '], $help);

        return "# HELP {$full} {$help}\n# TYPE {$full} {$type}\n";
    }

    /**
     * 把儲存用的欄位（JSON）還原成 Prometheus 的標籤語法。
     */
    private function labelsOf(string $field, array $extra = []): string
    {
        $labels = array_merge(json_decode($field, true) ?: [], $extra);

        if ($labels === []) {
            return '';
        }

        $parts = [];

        foreach ($labels as $key => $value) {
            $escaped = str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], (string) $value);
            $parts[] = $key.'="'.$escaped.'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    // --- 內部 -----------------------------------------------------------------

    /**
     * 檢查指標存在、型別相符、而且標籤集完全吻合宣告。
     *
     * 標籤那一項是重點：Prometheus 不會因為你少帶一個標籤而報錯，它只會安靜地開一條
     * 新的時間序列。圖表上的症狀是「數字突然掉了一半」，而原因是三個月前有人打錯了
     * 一個字。與其事後查，不如寫入當下就炸掉 —— 這是程式錯誤，不是執行期故障，
     * 所以這裡不 fail-open。
     */
    private function fieldFor(string $name, string $expectedType, array $labels): string
    {
        $definition = $this->definitions[$name] ?? throw new InvalidArgumentException(
            "指標 {$name} 沒有在 config/metrics.php 裡宣告。"
        );

        if ($definition['type'] !== $expectedType) {
            throw new InvalidArgumentException(
                "指標 {$name} 是 {$definition['type']}，不能當成 {$expectedType} 使用。"
            );
        }

        $declared = $definition['labels'] ?? [];
        sort($declared);
        ksort($labels);

        if (array_keys($labels) !== $declared) {
            throw new InvalidArgumentException(sprintf(
                '指標 %s 的標籤應該是 [%s]，收到 [%s]。',
                $name,
                implode(', ', $declared),
                implode(', ', array_keys($labels)),
            ));
        }

        return json_encode(array_map(strval(...), $labels), JSON_UNESCAPED_UNICODE);
    }

    private function bucketFor(string $name, float $value): string
    {
        foreach ($this->definitions[$name]['buckets'] as $le) {
            if ($value <= $le) {
                return $this->num($le);
            }
        }

        return '+Inf';
    }

    private function key(string $kind, string $name): string
    {
        return "m:{$kind}:{$name}";
    }

    private function conn()
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * @param  Closure(): mixed  $operation
     */
    private function quietly(Closure $operation): void
    {
        try {
            $operation();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Prometheus 只認 . 當小數點，而 PHP 的字串轉換會跟著 locale 走。
     */
    private function num(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
