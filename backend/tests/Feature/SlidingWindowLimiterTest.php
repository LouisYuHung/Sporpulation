<?php

namespace Tests\Feature;

use App\RateLimiting\SlidingWindowLimiter;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class SlidingWindowLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();      // ← 忘了這行的話 Laravel 沒啟動，什麼都不能用

        // 這個限流器需要真的 Redis（ZSET，1.4 之後還有 Lua），array cache
        // driver 做不到。連不上就跳過，讓沒開 Docker 的人跑 php artisan test
        // 仍然全綠。
        try {
            Redis::connection('idempotency')->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }


        // 每個測試都從乾淨狀態開始，否則前一個測試留下的紀錄會讓後一個莫名失敗。
        // db 15 由 phpunit.xml 的 REDIS_IDEMPOTENCY_DB 指定 - 指錯就會清掉開發
        // 用的資料。
        Redis::connection('idempotency')->flushdb();
    }

    #[Test]
    public function it_allows_up_to_the_limit_then_blocks(): void
    {
        $limiter = $this->limiter(limit: 3);

        $decisions = array_map(
            fn() => $limiter->attempt('user:1'),
            range(1, 4),
        );

        $this->assertTrue($decisions[0]->allowed);
        $this->assertTrue($decisions[1]->allowed);
        $this->assertTrue($decisions[2]->allowed);
        $this->assertFalse($decisions[3]->allowed, '第 4 次應該被擋下');

        // remaining 一路遞減到 0。
        $this->assertSame(2, $decisions[0]->remaining);
        $this->assertSame(0, $decisions[2]->remaining);
    }

    #[Test]
    public function separate_scopes_do_not_share_a_budget(): void
    {
        $limiter = $this->limiter(limit: 3);

        $decisionUserOne = array_map(
            fn() => $limiter->attempt('user:1'),
            range(1, 4),
        );

        // 先確立前提：user:1 的額度已經用光。
        $this->assertFalse($decisionUserOne[3]->allowed);
        // user:2 完全不受影響 —— key 組錯的話這裡就會紅。
        $this->assertTrue($limiter->attempt('user:2')->allowed);
    }

    #[Test]
    public function the_window_slides_so_old_attempts_stop_counting(): void
    {
        $limiter = $this->limiter(limit: 3, windowMs: 60_000);

        $decisions = array_map(
            fn() => $limiter->attempt('user:1'),
            range(1, 4),
        );

        $this->assertTrue($decisions[0]->allowed);
        $this->assertTrue($decisions[1]->allowed);
        $this->assertTrue($decisions[2]->allowed);
        $this->assertFalse($decisions[3]->allowed, '第 4 次應該被擋下');

        $this->travel(61)->seconds();

        // 三次舊的嘗試都離開視窗了，額度應該完全恢復。
        $this->assertTrue($limiter->attempt('user:1')->allowed);
    }

    #[Test]
    public function a_blocked_attempt_reports_when_to_retry(): void
    {
        // 凍結時鐘，讓「20 秒」真的是 20 秒。不凍結的話第一次 attempt 用的是
        // 一直在走的真實時間，跟 travel() 的起點差幾毫秒 - 大多數時候會被
        // math.ceil 吸收掉，但那種「大多數時候會過」的測試就是 flaky test。
        $this->freezeTime();

        $limiter = $this->limiter(limit: 1, windowMs: 60_000);

        $limiter->attempt('user:1');        // t=0 進入視窗，t=60 才離開

        $this->travel(20)->seconds();

        $decision = $limiter->attempt('user:1');

        $this->assertFalse($decision->allowed);

        // 從視窗裡最舊那筆算出來的，不是笨笨地回一個視窗長度 - 回 60 這裡就紅。
        $this->assertSame(40, $decision->retryAfter);
    }

    private function limiter(int $limit = 5, int $windowMs = 60_000): SlidingWindowLimiter
    {
        return new SlidingWindowLimiter(
            name: 'test',
            connection: 'idempotency',
            limit: $limit,
            windowMs: $windowMs,
        );
    }
}
