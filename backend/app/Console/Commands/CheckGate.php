<?php

namespace App\Console\Commands;

use App\Gate\SeatGate;
use App\Metrics\MetricRegistry;
use App\Models\Activity;
use App\Models\District;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 量測入場閘門到底替資料庫擋下了多少工作。
 *
 * 情境刻意是「開賣」：遠遠不夠的名額，一大群不同的使用者同時湧入。每個參賽者都是
 * 不同的使用者，所以限流不會介入（它是每人每分鐘 5 次）—— 量到的差異才歸因得到
 * 閘門身上。
 *
 * 跑三種設定才看得出全貌：
 *
 *   --off      沒有閘門，所有請求都打到 MySQL
 *   （預設）   閘門是冷的 —— 開賣那一瞬間它還不存在
 *   --warm     閘門已經建好 —— 也就是開賣前先預熱過
 *
 * 中間那一種是這支指令最想講的事：懶惰建立的閘門在「開賣瞬間」正好是冷的，而那
 * 剛好是它最該發揮作用的時刻。
 */
class CheckGate extends Command
{
    protected $signature = 'gate:check
                            {--capacity=10 : 這場開賣有幾個名額}
                            {--racers=200 : 同時湧入的請求數（每個都是不同的使用者）}
                            {--off : 關掉入場閘門，取得對照組}
                            {--warm : 開賣前先把閘門建好}';

    protected $description = 'Measure how much database work the admission gate keeps away';

    public function handle(): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('This command needs the pcntl extension.');

            return self::FAILURE;
        }

        if (app()->isProduction()) {
            $this->error('This command writes throwaway records. Not in production.');

            return self::FAILURE;
        }

        $capacity = (int) $this->option('capacity');
        $racers = (int) $this->option('racers');
        $off = (bool) $this->option('off');

        if ($off) {
            // 在 fork 之前改，子行程才會繼承到。
            config(['gate.enabled' => false]);
        }

        $this->warn('Writing throwaway records to the '.DB::getDefaultConnection().' connection.');

        $activity = Activity::factory()->withCapacity($capacity)->create([
            'sport_id' => Sport::factory()->create()->id,
            'district_id' => District::factory()->create()->id,
        ]);

        $tokens = User::factory()->count($racers)->create()
            ->map(fn (User $user) => $user->createToken('gate-check')->plainTextToken)
            ->all();

        if ($this->option('warm')) {
            // 取一個再馬上還回去：淨效果是零，但閘門會被建起來。這正是正式環境
            // 該做的事 —— 開賣前預熱，而不是讓第一波流量替你建。
            $gate = app(SeatGate::class);
            $gate->release($activity);
            $gate->admit($activity);
            $gate->release($activity);
        }

        $shedBefore = $this->shedCount();
        $before = $this->snapshot();
        $startedAt = microtime(true);

        $codes = $this->race($racers, fn (int $i) => $this->attempt($tokens[$i], $activity->id));

        $elapsed = microtime(true) - $startedAt;
        $after = $this->snapshot();
        $shedAfter = $this->shedCount();

        $this->line(sprintf(
            '閘門=%-11s racers=%-4d 名額=%-3d  201=%-3d 409=%-4d 其他=%-3d  削掉=%d',
            $off ? 'off' : ($this->option('warm') ? 'on（預熱）' : 'on（冷啟動）'),
            $racers,
            $capacity,
            count(array_keys($codes, 0)),
            count(array_keys($codes, 1)),
            count(array_keys($codes, 2)),
            $shedAfter - $shedBefore,
        ));

        $this->line(sprintf(
            '  DB 語句=%-5d 列鎖等待次數=%-5d 列鎖等待總時間=%-6s 整場耗時=%s',
            $after['statements'] - $before['statements'],
            $after['lock_waits'] - $before['lock_waits'],
            ($after['lock_time'] - $before['lock_time']).'ms',
            round($elapsed * 1000).'ms',
        ));

        $granted = $activity->fresh()->joined_count;
        $errored = count(array_keys($codes, 2));

        if ($granted !== $capacity) {
            $this->error("FAIL 發出的名額不等於容量（{$granted} / {$capacity}）");

            return self::FAILURE;
        }

        // 崩掉的子行程會讓「DB 語句數」完全失去意義：兩組設定之間的差異會被
        // 「這次有幾個行程活下來」蓋過去，而那個數字每次都不一樣。
        //
        // racers 超過 MySQL 的 max_connections（預設 151）時就會發生。這個檢查存在
        // 是因為第一次量的時候差點把它當成閘門的效果 —— 冷啟動 701 句、預熱 736 句，
        // 看起來像「預熱更糟」，實際上 35 句剛好等於多活下來的 9 個請求。
        if ($errored > 0) {
            $this->error("FAIL {$errored} 個行程沒有完成，這次的語句數不可引用");

            return self::FAILURE;
        }

        $this->info("PASS 發出的名額剛好等於容量（{$granted}），沒有行程崩潰");

        return self::SUCCESS;
    }

    /**
     * 送出一個真正的請求，把狀態碼壓成結束代碼。
     */
    private function attempt(string $token, int $activityId): int
    {
        try {
            $request = Request::create(
                "/api/activities/{$activityId}/registration",
                'POST',
                server: [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                ],
            );

            return match (app(HttpKernel::class)->handle($request)->getStatusCode()) {
                201 => 0,
                409 => 1,
                default => 2,
            };
        } catch (Throwable) {
            return 2;
        }
    }

    /**
     * 從指標端點本身讀「被削掉幾個」。
     *
     * 刻意不另外開一個讀取 API：這個數字對外的樣子就是 /metrics 那一行，量測工具
     * 讀的應該是跟 Prometheus 一樣的東西。多開一條路徑就多一個會跟正式輸出不一致
     * 的地方。
     */
    private function shedCount(): int
    {
        $rendered = app(MetricRegistry::class)->render();

        preg_match('/^sporpulation_gate_decisions_total\{decision="shed"\} (\d+)$/m', $rendered, $matches);

        return (int) ($matches[1] ?? 0);
    }

    /**
     * MySQL 的全域計數器快照。前提是這個資料庫此刻沒有其他流量。
     *
     * 語句數只講了一半的故事：被削掉的請求省下的是「動到 joined_count 的那兩句」，
     * 而其餘幾句是 auth:sanctum 與 route model binding 的索引查找 —— 那些便宜得多，
     * 而且不會互相排隊。
     *
     * 真正的成本在列鎖：所有報名者都要在活動那一列上排隊，那是唯一無法平行化的
     * 地方。Innodb_row_lock_* 量的正是這個，也是閘門真正在保護的東西。
     *
     * @return array{statements: int, lock_waits: int, lock_time: int}
     */
    private function snapshot(): array
    {
        $statements = 0;

        foreach (['Com_select', 'Com_insert', 'Com_update'] as $counter) {
            $statements += (int) $this->status($counter);
        }

        return [
            'statements' => $statements,
            'lock_waits' => (int) $this->status('Innodb_row_lock_waits'),
            'lock_time' => (int) $this->status('Innodb_row_lock_time'),
        ];
    }

    private function status(string $counter): string
    {
        return DB::selectOne("SHOW GLOBAL STATUS LIKE '{$counter}'")->Value;
    }

    /**
     * 在同一個實際時間點同時執行 $count 份 $work，並收集它們的結束代碼。
     *
     * @param  callable(int): int  $work
     * @return list<int>
     */
    private function race(int $count, callable $work): array
    {
        $startAt = microtime(true) + 1.0;
        $pids = [];

        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // fork 會把已開啟的 socket 複製給每個子行程，多個行程共用同一條
                // 連線會讀到彼此的回應。這條路徑會用到四個 Redis 連線，一個都
                // 不能漏 —— 漏掉的那個症狀是隨機的、看起來像併發 bug。
                DB::purge();

                foreach (['default', 'idempotency', 'metrics', 'gate'] as $connection) {
                    Redis::purge($connection);
                }

                usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

                exit($work($i));
            }

            $pids[] = $pid;
        }

        $codes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }

        return $codes;
    }
}
