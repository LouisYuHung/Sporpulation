<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use Database\Factories\ActivityRegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['activity_id', 'user_id', 'status', 'joined_at', 'cancelled_at'])]
class ActivityRegistration extends Model
{
    /** @use HasFactory<ActivityRegistrationFactory> */
    use HasFactory;

    protected $table = 'activity_registrations';

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 目前持有名額的報名紀錄。
     *
     * @param  Builder<self>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', RegistrationStatus::Confirmed);
    }

    public function isConfirmed(): bool
    {
        return $this->status === RegistrationStatus::Confirmed;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'joined_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
