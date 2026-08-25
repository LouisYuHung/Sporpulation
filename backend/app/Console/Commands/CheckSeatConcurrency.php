<?php

namespace App\Console\Commands;

use App\Enums\RegistrationStatus;
use App\Exceptions\ConflictException;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\District;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * 驗證在請求真的重疊時，名額的帳仍然算得準。
 *
 * 測試套件跑在 sqlite 上、一次只處理一個請求，因此它能驗證邏輯，卻永遠驗不到並行：
 * 它抓不到鎖順序造成的死結，也抓不到兩個請求同時取走最後一個名額。這個指令會針對
 * 實際設定的資料庫 fork 出真正的行程 - 請指向 MySQL 使用。
 *
 * 它會寫入用完即棄的資料，因此只能對測試用資料庫執行。
 */
class CheckSeatConcurrency extends Command
{
    protected $signature = 'activities:check-concurrency
                            {--capacity=5 : Seats on the contested activity}
                            {--racers=40 : Processes stampeding it}
                            {--url= : 打進這個位址（例如 http://localhost:8080）而不是直接呼叫模型}';

    private bool $failed = false;

    /**
     * 空字串代表行程內模式：直接呼叫 $activity->join()，完全不經過 HTTP。
     *
     * 兩種模式證明的是不同的東西。行程內模式隔離出資料庫的保證 —— 沒有 middleware、
     * 沒有限流、沒有負載平衡的變數。--url 模式打進 lb，才是真正的跨節點併發，但它
     * 量到的是整條路徑，包括限流會（正確地）擋掉一部分重試。
     */
    private string $url = '';

    public function handle(): int
    {
        $this->url = rtrim((string) $this->option('url'), '/');

        $this->warn('Writing throwaway records to the '.DB::getDefaultConnection().' connection.');
        $this->line($this->url === ''
            ? 'MODE  in-process（直接呼叫模型，隔離資料庫的保證）'
            : "MODE  http → {$this->url}（跨節點，含完整 middleware 堆疊）");

        if (! function_exists('pcntl_fork')) {
            $this->error('This command needs the pcntl extension.');

            return self::FAILURE;
        }

        if (app()->isProduction()) {
            $this->error('This command writes throwaway records. Not in production.');

            return self::FAILURE;
        }

        $sport = Sport::factory()->create();
        $district = District::factory()->create();
        $seed = ['sport_id' => $sport->id, 'district_id' => $district->id];

        $this->stampede($seed);
        $this->retryStorm($seed);
        $this->churn($seed);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 大量不同的使用者，搶遠遠不夠的名額。
     */
    private function stampede(array $seed): void
    {
        $capacity = (int) $this->option('capacity');
        $racers = (int) $this->option('racers');

        $activity = Activity::factory()->withCapacity($capacity)->create($seed);
        $users = User::factory()->count($racers)->create();

        $tokens = $this->tokensFor($users);
        $codes = $this->race($racers, fn (int $i) => $this->attemptJoin($activity, $users[$i], $tokens[$i] ?? null));

        $granted = count(array_keys($codes, 0));
        $rejected = count(array_keys($codes, 1));
        $errored = count(array_keys($codes, 2));

        $this->check('seats granted equals capacity', $granted === $capacity, "granted={$granted} capacity={$capacity}");
        $this->check('losers got a clean 409, not an error', $rejected === $racers - $capacity && $errored === 0, "rejected={$rejected} errors={$errored}");
        $this->check('counter agrees with confirmed rows', $this->seatCount($activity) === $this->confirmedRows($activity), $this->tally($activity));

        $this->reportPerformance($racers);
    }

    /**
     * 一位使用者、一個活動、同時湧入大量重試 - 例如按鈕被連點兩下，或用戶端重試
     * 一個逾時的請求。
     */
    private function retryStorm(array $seed): void
    {
        $activity = Activity::factory()->withCapacity(10)->create($seed);
        $user = User::factory()->create();

        $tokens = $this->tokensFor(collect([$user]));
        $codes = $this->race(20, fn () => $this->attemptJoin($activity, $user, $tokens[0] ?? null));

        $this->check('no retry errored', count(array_keys($codes, 2)) === 0, 'errors='.count(array_keys($codes, 2)));
        $this->check('the retries took exactly one seat', $this->seatCount($activity) === 1, $this->tally($activity));
        $this->check('the retries wrote exactly one row', $activity->registrations()->count() === 1, 'rows='.$activity->registrations()->count());
    }

    /**
     * 一邊反覆報名與取消，一邊與其他人爭搶同一批名額，用來抓出名額被重複釋出或
     * 重複佔用的情況。
     */
    private function churn(array $seed): void
    {
        $activity = Activity::factory()->withCapacity(3)->create($seed);
        $users = User::factory()->count(12)->create();

        $tokens = $this->tokensFor($users);
        $codes = $this->race(12, function (int $i) use ($activity, $users, $tokens) {
            for ($n = 0; $n < 5; $n++) {
                if ($this->attemptJoin($activity, $users[$i], $tokens[$i] ?? null) === 2) {
                    return 2;
                }
                if ($this->attemptCancel($activity, $users[$i], $tokens[$i] ?? null) === 2) {
                    return 2;
                }
            }

            return 0;
        });

        $this->check('no churn worker errored', count(array_keys($codes, 2)) === 0, 'errors='.count(array_keys($codes, 2)));
        $this->check('counter never drifted from reality', $this->seatCount($activity) === $this->confirmedRows($activity), $this->tally($activity));
        $this->check('counter stayed within capacity', $this->seatCount($activity) <= 3, $this->tally($activity));
    }

    /**
     * @return int 0 報名成功、1 被拒絕、2 非預期的失敗
     */
    private function attemptJoin(Activity $activity, User $user, ?string $token = null): int
    {
        if ($this->url !== '') {
            return $this->httpAttempt('POST', $activity, $token);
        }

        try {
            $activity->fresh()->join($user);

            return 0;
        } catch (ConflictException) {
            return 1;
        } catch (Throwable $e) {
            $this->reportUnexpected($e);

            return 2;
        }
    }

    private function attemptCancel(Activity $activity, User $user, ?string $token = null): int
    {
        if ($this->url !== '') {
            return $this->httpAttempt('DELETE', $activity, $token);
        }

        try {
            $activity->fresh()->cancel($user);

            return 0;
        } catch (Throwable $e) {
            $this->reportUnexpected($e);

            return 2;
        }
    }

    /**
     * 最近一次 race 的每個請求耗時（毫秒），以及從對齊時間點算起的實際牆鐘秒數。
     *
     * 用屬性而不是回傳值，是為了不動 race() 既有的簽章（三個情境都在用）。這是
     * 一次性的診斷指令，不是常駐服務，所以這點可變狀態是可以接受的。
     *
     * @var list<float>
     */
    private array $timings = [];

    private float $elapsedSeconds = 0.0;

    private function race(int $count, callable $work): array
    {
        $startAt = microtime(true) + 1.0;
        $pids = [];

        // 子行程無法用 exit code 回傳浮點數（只有 0-255），因此耗時走 Redis 交回來。
        $metrics = 'race:timings:'.Str::random();
        Redis::connection('idempotency')->del($metrics);

        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();
                Redis::purge('idempotency');

                usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

                // 計時從對齊之後才開始 —— 那一秒的等待不屬於請求的延遲。
                $began = hrtime(true);
                $code = $work($i);
                $elapsed = (hrtime(true) - $began) / 1_000_000;

                Redis::connection('idempotency')->rpush($metrics, (string) $elapsed);

                exit($code);
            }

            $pids[] = $pid;
        }

        $codes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }

        // 吞吐的分母是「所有請求同時開始」到「最後一個結束」，不含 fork 的成本。
        $this->elapsedSeconds = microtime(true) - $startAt;

        $redis = Redis::connection('idempotency');
        $this->timings = array_map('floatval', $redis->lrange($metrics, 0, -1));
        $redis->del($metrics);

        return $codes;
    }

    private function seatCount(Activity $activity): int
    {
        return $activity->fresh()->joined_count;
    }

    private function confirmedRows(Activity $activity): int
    {
        return ActivityRegistration::where('activity_id', $activity->id)
            ->where('status', RegistrationStatus::Confirmed)
            ->count();
    }

    private function tally(Activity $activity): string
    {
        return "joined_count={$this->seatCount($activity)} confirmed_rows={$this->confirmedRows($activity)}";
    }

    private function check(string $label, bool $passed, string $detail): void
    {
        $this->line(sprintf(
            '%s %s <fg=gray>(%s)</>',
            $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
            $label,
            $detail,
        ));

        $this->failed = $this->failed || ! $passed;
    }

    private function reportUnexpected(Throwable $e): void
    {
        fwrite(STDERR, 'unexpected: '.$e::class.': '.$e->getMessage()."\n");
    }

    /**
     * 走完整的 HTTP 路徑：負載平衡 → 某個 app 節點 → middleware → controller。
     *
     * 429 算成「乾淨的拒絕」而不是錯誤，跟 409 同一類 —— 兩者都代表請求被明確擋下、
     * 而且什麼都沒壞。限流在這條路徑上是系統的一部分，不是干擾。
     */
    private function httpAttempt(string $method, Activity $activity, string $token): int
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->send($method, "{$this->url}/api/activities/{$activity->id}/registration");

            return match ($response->status()) {
                200, 201 => 0,
                409, 429 => 1,
                default => 2,
            };
        } catch (Throwable $e) {
            $this->reportUnexpected($e);

            return 2;
        }
    }

    /**
     * @return list<string> 與 $users 同索引；行程內模式回傳空陣列
     */
    private function tokensFor(Collection $users): array
    {
        return $this->url === ''
            ? []
            : $users->map(fn (User $u) => $u->createToken('concurrency-check')->plainTextToken)->all();
    }

    private function reportPerformance(int $requests): void
    {
        if ($this->timings === []) {
            return;
        }

        sort($this->timings);

        $this->line(sprintf(
            'THROUGHPUT  %.0f req/s   （%d 個請求 / %.2f 秒）',
            $requests / max($this->elapsedSeconds, 0.001),
            $requests,
            $this->elapsedSeconds,
        ));

        $this->line(sprintf(
            'LATENCY     p50=%.1fms  p95=%.1fms  p99=%.1fms  max=%.1fms',
            $this->percentile(50),
            $this->percentile(95),
            $this->percentile(99),
            end($this->timings),
        ));
    }

    private function percentile(float $p): float
    {
        $index = (int) ceil($p / 100 * count($this->timings)) - 1;

        return $this->timings[max(0, min($index, count($this->timings) - 1))];
    }
}
