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
 * Proves the seat accounting holds when requests actually overlap.
 *
 * The test suite runs on sqlite, one request at a time, so it can check the
 * logic but never the concurrency: it cannot catch a lock-ordering deadlock or
 * two requests both taking the last seat. This forks real processes against
 * whatever database is configured - point it at MySQL.
 *
 * It writes throwaway records, so run it against a scratch database only.
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

        $this->warn('Writing throwaway records to the '.DB::getDefaultConnection().' connection.');

        $sport = Sport::factory()->create();
        $district = District::factory()->create();
        $seed = ['sport_id' => $sport->id, 'district_id' => $district->id];

        $this->stampede($seed);
        $this->retryStorm($seed);
        $this->churn($seed);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Many different users, far too few seats.
     */
    private function stampede(array $seed): void
    {
        $capacity = (int) $this->option('capacity');
        $racers = (int) $this->option('racers');

        $activity = Activity::factory()->withCapacity($capacity)->create($seed);
        $users = User::factory()->count($racers)->create();

        $codes = $this->race(
            $racers,
            fn (int $i) => $this->attemptJoin($activity, $users[$i])
        );

        $granted = count(array_keys($codes, 0));
        $rejected = count(array_keys($codes, 1));
        $errored = count(array_keys($codes, 2));

        $this->check('seats granted equals capacity', $granted === $capacity, "granted={$granted} capacity={$capacity}");
        $this->check('losers got a clean 409, not an error', $rejected === $racers - $capacity && $errored === 0, "rejected={$rejected} errors={$errored}");
        $this->check('counter agrees with confirmed rows', $this->seatCount($activity) === $this->confirmedRows($activity), $this->tally($activity));
    }

    /**
     * One user, one activity, many simultaneous retries - a double-tapped
     * button or a client retrying a request that timed out.
     */
    private function retryStorm(array $seed): void
    {
        $activity = Activity::factory()->withCapacity(10)->create($seed);
        $user = User::factory()->create();

        $codes = $this->race(20, fn () => $this->attemptJoin($activity, $user));

        $this->check('no retry errored', count(array_keys($codes, 2)) === 0, 'errors='.count(array_keys($codes, 2)));
        $this->check('the retries took exactly one seat', $this->seatCount($activity) === 1, $this->tally($activity));
        $this->check('the retries wrote exactly one row', $activity->registrations()->count() === 1, 'rows='.$activity->registrations()->count());
    }

    /**
     * Joining and cancelling in a loop while others compete for the same
     * seats, to catch a seat released or claimed twice.
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
                    // Full at this instant; that is a legitimate outcome.
                } catch (Throwable $e) {
                    $this->reportUnexpected($e);

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
     * @return int 0 joined, 1 turned away, 2 unexpected failure
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
     * Run $count copies of $work at the same wall-clock instant and collect
     * their exit codes.
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
                // The inherited connection is shared with every sibling, so
                // each child opens its own before touching the database.
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
        fwrite(STDERR, 'unexpected: '.$e::class.': '.$e->getMessage()."\n");
    }
}
