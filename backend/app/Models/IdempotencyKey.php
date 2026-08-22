<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 被記錄下來的寫入操作，讓重試時重播第一次的回應，而不是執行第二次。
 *
 * 這張表是 DatabaseIdempotencyStore 的儲存，另一個實作 RedisIdempotencyStore 靠
 * TTL 自動過期。兩者的差別不是效能，而是「紀錄會不會無聲消失」：快取與 Redis 都
 * 會被例行清除或在記憶體壓力下驅逐，這張表不會。
 *
 * 因此沒有天然唯一鍵可以兜底的寫入（建立活動）一律綁在這裡 —— 對它來說冪等碼是
 * 唯一的保證。分級見 routes/api.php。
 */
#[Fillable(['user_id', 'key_hash', 'fingerprint', 'status', 'body', 'content_type', 'expires_at'])]
class IdempotencyKey extends Model
{
    use MassPrunable;

    protected $table = 'idempotency_keys';

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 過期的紀錄已無任何意義，因此直接整批刪除而不採軟刪除。排程設定於
     * routes/console.php。
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
