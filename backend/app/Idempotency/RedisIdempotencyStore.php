<?php

namespace App\Idempotency;

use Illuminate\Support\Facades\Redis;

/**
 * 把冪等紀錄存在 Redis，以 HASH 儲存、由 TTL 自動過期。
 *
 * 原子性由 Lua 提供：Redis 單執行緒執行 script，整段不會被別的用戶端插隊。這與
 * DatabaseIdempotencyStore 靠 unique(user_id, key_hash) 是同一個精神 - 由資料儲存
 * 本身裁決，而不是在 PHP 裡先讀再寫。
 *
 * 換來的代價是持久性：Redis 預設只做快照，崩潰可能丟掉最後幾十秒的紀錄；記憶體
 * 壓力下還可能在到期前被驅逐。因此這個後端只適合「還有第二層防線」的寫入 -
 * 報名有 unique(activity_id, user_id) 兜底，開團沒有。
 */
class RedisIdempotencyStore implements IdempotencyStore
{
    public function claim(string $scope, string $key, string $fingerprint): bool
    {
        return (bool) $this->redis()->eval(
            $this->claimScript(),
            1,
            $this->key($scope, $key),
            $fingerprint,
            config('idempotency.ttl'),
        );
    }

    public function find(string $scope, string $key): ?IdempotencyRecord
    {
        $fields = $this->redis()->hgetall($this->key($scope, $key));

        if ($fields === []) {
            return null;
        }

        return new IdempotencyRecord(
            fingerprint: $fields['fingerprint'],

            // 佔位時只寫了 fingerprint，因此缺少 status 就代表請求仍在進行中 -
            // 對應 DB 版那個 nullable 的 status 欄位。
            status: isset($fields['status']) ? (int) $fields['status'] : null,
            body: $fields['body'] ?? null,
            contentType: $fields['content_type'] ?? null,
        );
    }

    public function complete(string $scope, string $key, int $status, ?string $body, ?string $contentType): void
    {
        // HSET 對既有的 key 只是新增欄位，不會重設 TTL - 紀錄仍在「第一次請求之後
        // 24 小時」到期，而不是從這一刻重算。這正是 DB 版 UPDATE 不動 expires_at
        // 的對應物。
        //
        // null 的欄位乾脆不寫：Redis 沒有 null，寫進去會變成空字串，find() 就分不出
        // 「沒有 Content-Type」和「Content-Type 是空字串」。
        $this->redis()->hmset($this->key($scope, $key), array_filter([
            'status' => $status,
            'body' => $body,
            'content_type' => $contentType,
        ], fn ($value) => $value !== null));
    }

    public function release(string $scope, string $key): void
    {
        $this->redis()->del($this->key($scope, $key));
    }

    /**
     * KEYS[1] - 這筆紀錄的 key
     * ARGV[1] - 請求指紋
     * ARGV[2] - 存活秒數
     *
     * 為什麼需要 Lua：HSETNX 加 EXPIRE 是兩個指令，行程若死在中間，就會留下一個
     * 沒有 TTL 的 key 永久佔著這把冪等碼。單一指令表達不了「不存在才建立，而且
     * 建立時一定要有存活時間」。
     */
    private function claimScript(): string
    {
        return <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 1 then
            return 0
        end

        redis.call('HSET', KEYS[1], 'fingerprint', ARGV[1])
        redis.call('EXPIRE', KEYS[1], ARGV[2])

        return 1
        LUA;
    }

    private function key(string $scope, string $key): string
    {
        return config('idempotency.redis.prefix')."{$scope}:".$this->hash($key);
    }

    private function redis()
    {
        return Redis::connection(config('idempotency.redis.connection'));
    }

    /**
     * 與 DatabaseIdempotencyStore 那個一模一樣，但理由不同：那邊是為了塞進 32 字元
     * 的欄位，這邊沒有長度限制，純粹是不想把用戶端的權杖原樣留在儲存裡。
     */
    private function hash(string $value): string
    {
        return hash('xxh128', $value);
    }
}
