<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Exceptions\ActivityClosedException;
use App\Exceptions\ActivityFullException;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'host_id',
    'sport_id',
    'district_id',
    'title',
    'description',
    'location',
    'starts_at',
    'ends_at',
    'capacity',
])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * Seat changes take the activity row's write lock first, so they queue
     * rather than deadlock. This is belt and braces for anything that order
     * does not already cover: Laravel replays the transaction when the
     * database reports a deadlock or lock timeout.
     */
    private const TRANSACTION_ATTEMPTS = 3;

    protected $table = 'activities';

    /**
     * joined_count also defaults to 0 in the schema; repeating it here means a
     * newly created activity reports a seat count without a re-read.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'joined_count' => 0,
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    /**
     * Users currently holding a seat.
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'activity_registrations')
            ->withPivot(['status', 'joined_at'])
            ->wherePivot('status', RegistrationStatus::Confirmed->value);
    }

    /**
     * Register a user, claiming one seat.
     *
     * Idempotent: replaying the request returns the existing registration
     * without claiming a second seat. Throws ActivityFullException when the
     * last seat is taken and ActivityClosedException once the activity has
     * started.
     *
     * The seat count is changed in the database, not on this instance:
     * refresh() before reading joined_count.
     */
    public function join(User $user): ActivityRegistration
    {
        if (! $this->isOpenForRegistration()) {
            throw new ActivityClosedException;
        }

        $registration = $this->registrationFor($user);

        // Already holding a seat: the caller is retrying, so report the same
        // result rather than touching the counter again.
        if ($registration?->isConfirmed()) {
            return $registration;
        }

        return $registration === null
            ? $this->createRegistration($user)
            : $this->reconfirmRegistration($registration);
    }

    /**
     * Give up a seat. Idempotent: cancelling a registration that is already
     * cancelled (or was never made) releases nothing.
     *
     * Returns the cancelled registration, or null when the user never joined.
     * As with join(), refresh() before reading joined_count.
     */
    public function cancel(User $user): ?ActivityRegistration
    {
        $registration = $this->registrationFor($user);

        if ($registration === null) {
            return null;
        }

        return DB::transaction(function () use ($registration) {
            $this->lockSeats();

            // Conditional on the row still being confirmed: a replayed cancel
            // (or one racing another) matches nothing, so the seat goes back
            // exactly once.
            $cancelled = ActivityRegistration::whereKey($registration->id)
                ->confirmed()
                ->update([
                    'status' => RegistrationStatus::Cancelled,
                    'cancelled_at' => now(),
                ]);

            if ($cancelled === 1) {
                $this->releaseSeat();
            }

            return $registration->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * The user's registration for this activity, in any status.
     */
    public function registrationFor(User $user): ?ActivityRegistration
    {
        return $this->registrations()->where('user_id', $user->id)->first();
    }

    /**
     * Registration closes when the activity starts.
     */
    public function isOpenForRegistration(): bool
    {
        return $this->starts_at->isFuture();
    }

    public function isFull(): bool
    {
        return $this->joined_count >= $this->capacity;
    }

    public function remainingSeats(): int
    {
        return max(0, $this->capacity - $this->joined_count);
    }

    /**
     * Activities that have not started yet, soonest first.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('starts_at', '>', now())->orderBy('starts_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'joined_count' => 'integer',
        ];
    }

    /**
     * First-time registration.
     *
     * The seat is claimed before the row is inserted, and the order matters:
     * inserting takes a shared lock on the parent activity row to check the
     * foreign key, and a transaction that then tries to upgrade that to an
     * exclusive lock deadlocks against every other joiner doing the same.
     * Claiming first takes the exclusive lock up front, which serialises
     * joiners on the activity row - exactly what a counter needs anyway.
     *
     * The insert still carries the idempotency: a duplicate request violates
     * unique(activity_id, user_id) and rolls the whole transaction back, seat
     * included.
     */
    private function createRegistration(User $user): ActivityRegistration
    {
        try {
            return DB::transaction(function () use ($user) {
                $this->claimSeat();

                return ActivityRegistration::create([
                    'activity_id' => $this->id,
                    'user_id' => $user->id,
                    'status' => RegistrationStatus::Confirmed,
                    'joined_at' => now(),
                ]);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request for the same user got there first. Because
            // both serialised on the activity row, its outcome is already
            // settled: either its row is there to return, or it rolled back
            // because the activity filled up.
            return $this->registrationFor($user) ?? throw new ActivityFullException;
        }
    }

    /**
     * A user who cancelled is joining again, reusing their existing row.
     */
    private function reconfirmRegistration(ActivityRegistration $registration): ActivityRegistration
    {
        return DB::transaction(function () use ($registration) {
            $this->lockSeats();

            // Conditional on the row still being cancelled, for the same
            // reason cancel() is conditional on it being confirmed: only the
            // request that actually flips the status may move the counter.
            $reconfirmed = ActivityRegistration::whereKey($registration->id)
                ->where('status', RegistrationStatus::Cancelled)
                ->update([
                    'status' => RegistrationStatus::Confirmed,
                    'joined_at' => now(),
                    'cancelled_at' => null,
                ]);

            // Matched nothing: a concurrent rejoin for this same user already
            // flipped it and claimed the seat, so this call just reports it.
            if ($reconfirmed === 1) {
                $this->claimSeat();
            }

            return $registration->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Take the activity row's write lock without changing anything.
     *
     * Every path that moves the counter locks this row before touching
     * activity_registrations, so concurrent joins and cancels always queue in
     * the same order and cannot deadlock against each other. claimSeat() takes
     * the same lock implicitly; paths that may not reach it take it here.
     */
    private function lockSeats(): void
    {
        static::whereKey($this->id)->lockForUpdate()->first();
    }

    /**
     * Take one seat, or fail if there is none left.
     *
     * The guard lives in the WHERE clause so the read and the write are a
     * single atomic statement: two requests competing for the last seat cannot
     * both see it as free.
     */
    private function claimSeat(): void
    {
        $claimed = static::whereKey($this->id)
            ->whereColumn('joined_count', '<', 'capacity')
            ->increment('joined_count');

        if ($claimed === 0) {
            throw new ActivityFullException;
        }
    }

    /**
     * Hand one seat back. The `> 0` guard is a safety net: it cannot trip
     * while every release is paired with a claim, but it keeps a bug from
     * underflowing the unsigned column.
     */
    private function releaseSeat(): void
    {
        static::whereKey($this->id)
            ->where('joined_count', '>', 0)
            ->decrement('joined_count');
    }
}
