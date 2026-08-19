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
        // 時間由 PHP 傳入而不是問 Redis - 這樣測試才能用 travel() 撥動時鐘。
        $now = now()->getTimestampMs();

        $redis = Redis::connection($this->connection);
        $key = $this->key($scope);

        // ⚠️ 這一版刻意是非原子的：三次獨立往返，中間留著空隙。Step 1.3 會用
        //    併發把它打破，1.4 才換成 Lua。
        $redis->zremrangebyscore($key, 0, $now - $this->windowMs);

        $used = (int) $redis->zcard($key);

        if ($used >= $this->limit) {
            // 丟棄式程式碼，不必算精確的 retryAfter - 1.4 的 Lua 會做對。
            return new LimiterDecision(false, 0, (int) ($this->windowMs / 1000));
        }

        // member 必須唯一：拿 $now 當 member 的話，同一毫秒的兩次嘗試會互相覆蓋
        // （ZSET 的 member 唯一），額度就少算了。
        $redis->zadd($key, $now, (string) Str::uuid());
        $redis->pexpire($key, $this->windowMs);

        return new LimiterDecision(true, $this->limit - $used - 1, 0);
    }

    private function key(string $scope): string
    {
        return "throttle:{$this->name}:{$scope}";
    }
}
