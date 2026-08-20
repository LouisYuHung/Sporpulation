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
use Illuminate\Support\Facades\DB;
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
                            {--racers=40 : Processes stampeding it}';

    protected $description = 'Fork overlapping join/cancel requests and verify no seat is double-sold';

    private bool $failed = false;

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

        $this->warn('Writing throwaway records to the ' . DB::getDefaultConnection() . ' connection.');

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

        $codes = $this->race(
            $racers,
            fn(int $i) => $this->attemptJoin($activity, $users[$i])
        );

        $granted = count(array_keys($codes, 0));
        $rejected = count(array_keys($codes, 1));
        $errored = count(array_keys($codes, 2));

        $this->check('seats granted equals capacity', $granted === $capacity, "granted={$granted} capacity={$capacity}");
        $this->check('losers got a clean 409, not an error', $rejected === $racers - $capacity && $errored === 0, "rejected={$rejected} errors={$errored}");
        $this->check('counter agrees with confirmed rows', $this->seatCount($activity) === $this->confirmedRows($activity), $this->tally($activity));
    }

    /**
     * 一位使用者、一個活動、同時湧入大量重試 - 例如按鈕被連點兩下，或用戶端重試
     * 一個逾時的請求。
     */
    private function retryStorm(array $seed): void
    {
        $activity = Activity::factory()->withCapacity(10)->create($seed);
        $user = User::factory()->create();

        $codes = $this->race(20, fn() => $this->attemptJoin($activity, $user));

        $this->check('no retry errored', count(array_keys($codes, 2)) === 0, 'errors=' . count(array_keys($codes, 2)));
        $this->check('the retries took exactly one seat', $this->seatCount($activity) === 1, $this->tally($activity));
        $this->check('the retries wrote exactly one row', $activity->registrations()->count() === 1, 'rows=' . $activity->registrations()->count());
    }

    /**
     * 一邊反覆報名與取消，一邊與其他人爭搶同一批名額，用來抓出名額被重複釋出或
     * 重複佔用的情況。
     */
    private function churn(array $seed): void
    {
        $activity = Activity::factory()->withCapacity(3)->create($seed);
        $users = User::factory()->count(12)->create();

        $codes = $this->race(12, function (int $i) use ($activity, $users) {
            for ($n = 0; $n < 5; $n++) {
                try {
                    $fresh = $activity->fresh();
                    $fresh->join($users[$i]);
                    $fresh->cancel($users[$i]);
                } catch (ConflictException) {
                    // 此刻剛好額滿；這是合理的結果。
                } catch (Throwable $e) {
                    $this->reportUnexpected($e);

                    return 2;
                }
            }

            return 0;
        });

        $this->check('no churn worker errored', count(array_keys($codes, 2)) === 0, 'errors=' . count(array_keys($codes, 2)));
        $this->check('counter never drifted from reality', $this->seatCount($activity) === $this->confirmedRows($activity), $this->tally($activity));
        $this->check('counter stayed within capacity', $this->seatCount($activity) <= 3, $this->tally($activity));
    }

    /**
     * @return int 0 報名成功、1 被拒絕、2 非預期的失敗
     */
    private function attemptJoin(Activity $activity, User $user): int
    {
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
                // 繼承而來的連線會與所有兄弟行程共用，因此每個子行程在碰資料庫
                // 之前都先開自己的連線。
                DB::purge();

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
        fwrite(STDERR, 'unexpected: ' . $e::class . ': ' . $e->getMessage() . "\n");
    }
}
