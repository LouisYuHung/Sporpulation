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
 * 刻意存在資料庫而非快取：快取會被例行清除（`cache:clear`、記憶體不足時被驅逐），
 * 而這些紀錄一旦遺失，保護就會無聲無息地消失。建立活動沒有天然的唯一鍵可以退而
 * 求其次，因此這張資料表是它唯一的保證。
 */
#[Fillable(['user_id', 'key_hash', 'fingerprint', 'status', 'body', 'content_type', 'expires_at'])]
class IdempotencyKey extends Model
{
    use MassPrunable;

    protected $table = 'idempotency_keys';

    /**
     * 沒有 status 的紀錄，代表這是一個請求尚未完成的佔位。
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
