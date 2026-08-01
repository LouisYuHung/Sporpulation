<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remembered write, so retrying it replays the first answer instead of
 * acting twice.
 *
 * Kept in the database rather than the cache on purpose: the cache is cleared
 * routinely (`cache:clear`, eviction under memory pressure) and losing these
 * records silently removes the protection. Creating an activity has no natural
 * unique key to fall back on, so this table is its only guarantee.
 */
#[Fillable(['user_id', 'key_hash', 'fingerprint', 'status', 'body', 'content_type', 'expires_at'])]
class IdempotencyKey extends Model
{
    use MassPrunable;

    protected $table = 'idempotency_keys';

    /**
     * A record with no status is a claim whose request has not finished.
     */
    public function isInProgress(): bool
    {
        return $this->status === null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Expired records carry no meaning, so they are deleted wholesale rather
     * than soft deleted. Scheduled in routes/console.php.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
