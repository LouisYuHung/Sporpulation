<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

/**
 * 量測 POST /activities/{id}/registration 的延遲分佈。
 *
 * --sync 會把佇列連線切成 sync，讓確認信在請求路徑上同步寄出 —— 也就是「如果沒有
 * 佇列會怎樣」的對照組。兩次跑的差額就是非同步邊界買到的東西。
 *
 * 每一輪都先 POST（計時）再 DELETE（不計時），讓每次 POST 都是真的在佔名額，而不是
 * 撞上 join() 對已確認報名的短路。
 */
class CheckRegistrationLatency extends Command
{
    protected $signature = 'registration:latency
                            {--samples=100 : 送出幾個請求}
                            {--sync : 改成同步寄信，取得沒有佇列時的對照組}';

    protected $description = 'Measure p50/p95/p99 of the registration endpoint';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('This command writes throwaway records. Not in production.');

            return self::FAILURE;
        }

        $samples = (int) $this->option('samples');
        $sync = (bool) $this->option('sync');

        if ($sync) {
            // 讓 dispatch() 當場執行，而不是推進 Redis。
            config(['queue.default' => 'sync']);
        }

        // 限流是 5 次/分鐘，會把量測變成量 429 的速度。
        config(['rate_limits.registration.limit' => PHP_INT_MAX]);

        $user = User::query()->firstOrFail();
        $token = $user->createToken('latency-check')->plainTextToken;
        $activity = Activity::query()->firstOrFail();

        $timings = [];

        for ($i = 0; $i < $samples; $i++) {
            $started = hrtime(true);
            $this->call2('POST', $token, $activity->id);
            $timings[] = (hrtime(true) - $started) / 1_000_000;   // 毫秒

            // 不計時：讓下一次 POST 仍然是真的在佔名額。
            $this->call2('DELETE', $token, $activity->id);
        }

        sort($timings);

        $this->line(sprintf(
            '寄信=%-4s samples=%d  p50=%.1fms  p95=%.1fms  p99=%.1fms  max=%.1fms',
            $sync ? '同步' : '佇列',
            $samples,
            $this->percentile($timings, 50),
            $this->percentile($timings, 95),
            $this->percentile($timings, 99),
            end($timings),
        ));

        return self::SUCCESS;
    }

    private function call2(string $method, string $token, int $activityId): int
    {
        $request = Request::create(
            "/api/activities/{$activityId}/registration",
            $method,
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ],
        );

        return app(HttpKernel::class)->handle($request)->getStatusCode();
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $index = (int) ceil($p / 100 * count($sorted)) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }
}
