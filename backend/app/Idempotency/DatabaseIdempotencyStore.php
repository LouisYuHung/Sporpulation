<?php

namespace App\Idempotency;

use App\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * 把冪等紀錄存在 idempotency_keys 資料表。
 *
 * 原子性由 unique(user_id, key_hash) 提供：並行的 INSERT 中只有一個能成功，其餘
 * 撞上唯一鍵。這與名額計數器用條件式 UPDATE 是同一招 - 由資料庫裁決，而不是在
 * PHP 裡先讀再寫。
 *
 * 限制：user_id 有外鍵約束，因此 $scope 實際上必須是一個存在的使用者 id，不能是
 * 任意字串。介面宣稱 scope 是不透明的命名空間，這個實作偷偷加了條件 - 別拿它存
 * per-IP 之類的紀錄。
 */
class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function claim(string $scope, string $key, string $fingerprint): bool
    {
        try {
            IdempotencyKey::create([
                'user_id' => $scope,
                'key_hash' => $this->hash($key),
                'fingerprint' => $fingerprint,
                'expires_at' => now()->addSeconds(config('idempotency.ttl')),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // 已過期的紀錄在清理排程執行前仍佔著這把 key，因此先把它清掉再試一次，
            // 之後才放棄佔位。

            // 這段程式碼的存在本身就是資料庫的特徵：它沒有 TTL，所以「過期」必須
            // 由應用程式碼自己判斷（hasExpired()）、自己清理（model:prune 排程）。
            // 換成 Redis 之後這整段會消失 - 那邊的過期是資料儲存本身在執行的。
            $stale = $this->row($scope, $key);

            if ($stale !== null && $stale->hasExpired()) {
                $stale->delete();

                return $this->claim($scope, $key, $fingerprint);
            }

            return false;
        }
    }

    public function find(string $scope, string $key): ?IdempotencyRecord
    {
        $row = $this->row($scope, $key);

        if ($row === null) {
            return null;
        }

        return new IdempotencyRecord(
            fingerprint: $row->fingerprint,
            status: $row->status,
            body: $row->body,
            contentType: $row->content_type,
        );
    }

    public function complete(string $scope, string $key, int $status, ?string $body, ?string $contentType): void
    {
        // UPDATE 不會動到 expires_at，因此紀錄仍在「第一次請求之後 24 小時」到期，
        // 而不是從這一刻重新計算 - 見介面的約定。
        $this->query($scope, $key)->update([
            'status' => $status,
            'body' => $body,
            'content_type' => $contentType,
        ]);
    }

    public function release(string $scope, string $key): void
    {
        $this->query($scope, $key)->delete();
    }

    /**
     * 內部才拿得到 Eloquent 模型：claim() 需要 hasExpired() 與 delete()。介面回傳的
     * 一律是 IdempotencyRecord。
     */
    private function row(string $scope, string $key): ?IdempotencyKey
    {
        return $this->query($scope, $key)->first();
    }

    /**
     * @return Builder<IdempotencyKey>
     */
    private function query(string $scope, string $key)
    {
        return IdempotencyKey::where('user_id', $scope)
            ->where('key_hash', $this->hash($key));
    }

    /**
     * 用戶端的 key 會先雜湊再存，而不是原樣保存：它是一個不透明的權杖，這裡沒有
     * 任何地方需要把它讀回來。
     */
    private function hash(string $value): string
    {
        return hash('xxh128', $value);
    }
}
