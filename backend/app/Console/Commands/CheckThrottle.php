<?php

namespace App\Console\Commands;

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
 * 量測限流到底替資料庫擋下了多少工作。
 *
 * 這個指令發的是真正的 HTTP 請求（走完整的 middleware 堆疊），因此量得到
 * CheckSeatConcurrency 量不到的東西 - 後者直接呼叫 $activity->join()，
 * middleware 一個都沒跑。
 *
 * 請求刻意不帶 Idempotency-Key：這樣擋在用戶端與資料庫之間的就只有限流一層，
 * 量出來的差異才歸因得清楚。
 */
class CheckThrottle extends Command
{
    protected $signature = 'throttle:check
                            {--racers=100 : 同一個使用者同時發出的請求數}
                            {--off : 關掉限流，取得對照組}';

    protected $description = 'Measure how much database work the rate limiter keeps away';

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

        $racers = (int) $this->option('racers');
        $off = (bool) $this->option('off');

        if ($off) {
            // 在 fork 之前改，子行程才會繼承到。
            config(['rate_limits.registration.limit' => PHP_INT_MAX]);
        }

        $this->warn('Writing throwaway records to the '.DB::getDefaultConnection().' connection.');

        $user = User::factory()->create();
        $token = $user->createToken('throttle-check')->plainTextToken;

        $activity = Activity::factory()->withCapacity($racers)->create([
            'sport_id' => Sport::factory()->create()->id,
            'district_id' => District::factory()->create()->id,
        ]);

        // 限流的紀錄留在 Redis 裡，跑第二次會被第一次的視窗影響。
        Redis::connection('idempotency')->flushdb();

        $before = $this->statements();

        $codes = $this->race(
            $racers,
            fn () => $this->attempt($token, $activity->id),
        );

        $after = $this->statements();

        $this->line(sprintf(
            '限流=%-3s racers=%d  201=%d  409=%d  429=%d  其他=%d   DB 語句=%d',
            $off ? 'off' : 'on',
            $racers,
            count(array_keys($codes, 0)),
            count(array_keys($codes, 1)),
            count(array_keys($codes, 2)),
            count(array_keys($codes, 3)),
            $after - $before,
        ));

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

            $status = app(HttpKernel::class)->handle($request)->getStatusCode();

            return match ($status) {
                201 => 0,
                409 => 1,
                429 => 2,
                default => 3,
            };
        } catch (Throwable) {
            return 3;
        }
    }

    /**
     * MySQL 執行過的寫入語句數。
     *
     * 用全域計數器而不是數列數：被唯一鍵擋下、整筆 rollback 的請求「沒有留下列」，
     * 但它確確實實佔用了一次連線、一次交易、一次索引查找 —— 那正是限流省下來的
     * 成本。前提是這個資料庫此刻沒有其他流量。
     */
    private function statements(): int
    {
        $total = 0;

        foreach (['Com_select', 'Com_insert', 'Com_update'] as $counter) {
            $total += (int) DB::selectOne("SHOW GLOBAL STATUS LIKE '{$counter}'")->Value;
        }

        return $total;
    }

    /**
     * 在同一個實際時間點同時執行 $count 份 $work，並收集它們的結束代碼。
     *
     * @param  callable(int): int  $work
     * @return list<int>
     */
    private function race(int $count, callable $work): array
    {
        // 照抄 RaceIdempotency::race()，一模一樣（含子行程裡的 DB::purge() 與
        // Redis::purge('idempotency')）。
        $startAt = microtime(true) + 1.0;
        $pids = [];

        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // fork 會把已開啟的 socket 複製給每個子行程，多個行程共用同一條
                // 連線會讀到彼此的回應。兩個後端都跑得到，所以兩個都清掉。
                DB::purge();
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
