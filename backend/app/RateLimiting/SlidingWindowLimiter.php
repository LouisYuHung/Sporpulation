<?php

namespace App\RateLimiting;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * 以 Redis ZSET 實作的 sliding window log 限流器。
 *
 * 視窗裡每一次通過的嘗試都是一個 member，score 是它發生的毫秒。判斷額度就是
 * 「掃掉離開視窗的、數剩下幾個」—— 因此視窗是連續滑動的，沒有固定視窗那種
 * 「跨越邊界就重置」的突刺。
 */
class SlidingWindowLimiter
{
    /**
     * @param  string  $name  用在 key 上，讓不同用途的限流器不會互相干擾。
     * @param  int  $windowMs  視窗長度（毫秒）。設定檔用秒，見 fromConfig()。
     */
    public function __construct(
        private string $name,
        private string $connection,
        private int $limit,
        private int $windowMs,
    ) {}

    public static function fromConfig(string $name): self
    {
        $config = config("rate_limits.{$name}");

        return new self(
            name: $name,
            connection: $config['connection'],
            limit: $config['limit'],
            windowMs: $config['window'] * 1000
        );
    }

    public function attempt(string $scope): LimiterDecision
    {
        // 時間由 PHP 傳入而不是在 Lua 裡問 Redis - 這樣測試才能用 travel() 撥動時鐘。
        $now = now()->getTimestampMs();

        // 判斷與遞減包在同一段 script 裡：Redis 單執行緒執行 Lua，因此整段不會被
        // 別的用戶端插隊。這跟名額用條件式 UPDATE 是同一招 - 由資料儲存本身裁決，
        // 而不是在 PHP 裡先讀再寫。
        [$allowed, $remaining, $retryAfter] = Redis::connection($this->connection)->eval(
            $this->script(),
            1,                          // KEYS 的數量
            $this->key($scope),         // KEYS[1]
            $now,                       // ARGV[1]
            $this->windowMs,            // ARGV[2]
            $this->limit,               // ARGV[3]
            (string) Str::uuid(),       // ARGV[4]
        );

        return new LimiterDecision((bool) $allowed, (int) $remaining, (int) $retryAfter);
    }

    /**
     * KEYS[1] - 這個 scope 的視窗
     * ARGV[1] - 現在（毫秒）
     * ARGV[2] - 視窗長度（毫秒）
     * ARGV[3] - 上限
     * ARGV[4] - 這次嘗試的唯一 member
     *
     * 回傳 { 是否放行, 剩餘額度, 還要等幾秒 }。Redis 協定沒有布林值 - Lua 回
     * false 會變成 nil 而不是 0，所以一律回 0/1 讓 PHP 端自己轉。
     */
    private function script(): string
    {
        return <<<'LUA'
        local now, window, limit = tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3])

        redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, now - window)

        local used = redis.call('ZCARD', KEYS[1])

        if used >= limit then
            local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
            return { 0, 0, math.ceil((tonumber(oldest[2]) + window - now) / 1000) }
        end

        redis.call('ZADD', KEYS[1], now, ARGV[4])
        redis.call('PEXPIRE', KEYS[1], window)

        return { 1, limit - used - 1, 0 }
        LUA;
    }

    private function key(string $scope): string
    {
        return "throttle:{$this->name}:{$scope}";
    }
}
