<?php

namespace App\Console\Commands;

use App\RateLimiting\SlidingWindowLimiter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * 讓限流器的「檢查 + 遞減」在真正併發下現形。
 *
 * 單元測試跑在單一行程裡，ZCARD 與 ZADD 之間不可能被插隊，因此它驗證得了額度
 * 的算術，卻永遠驗不到原子性。這個指令 fork 出真正同時抵達的嘗試 - 這是唯一
 * 能區分「正確的實作」與「剛好沒被撞到的實作」的方法。
 */
class RaceLimiter extends Command
{
    protected $signature = 'limiter:race
                            {--limit=5 : 視窗內允許的次數}
                            {--racers=100 : 同時打進來的行程數}
                            {--naive : 改用非原子的三段式實作，示範它為什麼不行}';

    protected $description = 'Fork simultaneous attempts and count how many the limiter really let through';

    public function handle(): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('This command needs the pcntl extension.');

            return self::FAILURE;
        }

        // 每次用新的 scope，否則上一輪的紀錄還在視窗裡，第二次跑就不準了。
        $scope = 'race:'.Str::random();

        $limit = (int) $this->option('limit');
        $racers = (int) $this->option('racers');

        $limiter = new SlidingWindowLimiter(
            name: 'race',
            connection: 'idempotency',
            limit: $limit,
            windowMs: 60_000,
        );

        $naive = (bool) $this->option('naive');

        // 兩個實作做的事完全一樣，差別只在「判斷與遞減是不是同一個原子動作」。
        $attempt = $naive
            ? fn () => $this->naiveAttempt($scope, $limit, 60_000)
            : fn () => $limiter->attempt($scope)->allowed;

        $codes = $this->race($racers, fn () => $attempt() ? 0 : 1);

        $allowed = count(array_filter($codes, fn ($c) => $c === 0));

        $this->line(sprintf(
            '%s  limit=%d  racers=%d  放行=%d  擋下=%d',
            $naive ? '非原子' : ' Lua  ',
            $limit,
            $racers,
            $allowed,
            $racers - $allowed,
        ));

        return self::SUCCESS;
    }

    /**
     * 刻意非原子的實作，只為了讓那個空隙可以被觀察。
     *
     * 與 SlidingWindowLimiter 唯一的差別是：判斷與遞減拆成三次獨立往返，中間
     * 隔著網路延遲。兩個同時抵達的嘗試會讀到同一個 ZCARD 計數、各自認為還有
     * 額度。它在單一行程下完全正確 - 這正是它危險的地方。
     */
    private function naiveAttempt(string $scope, int $limit, int $windowMs): bool
    {
        $now = now()->getTimestampMs();

        // 跟 SlidingWindowLimiter 一樣在方法內部才取連線 - fork 出來的子行程
        // 必須各自開連線，見 race()。
        $redis = Redis::connection('idempotency');

        $key = "throttle:naive:{$scope}";

        $redis->zremrangebyscore($key, 0, $now - $windowMs);

        $used = (int) $redis->zcard($key);

        if ($used >= $limit) {
            return false;
        }

        $redis->zadd($key, $now, (string) Str::uuid());
        $redis->pexpire($key, $windowMs);

        return true;
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
                // 你原本是 DB::purge()。Redis 同理：fork 會把已開啟的 socket 複製給每個
                // 子行程，多個行程往同一條連線讀寫會讀到彼此的回應 - 症狀是各種莫名其妙
                // 的型別錯誤，而不是乾脆的連線失敗。
                Redis::purge('idempotency');

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
