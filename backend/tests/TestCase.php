<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase 會把資料庫 rollback 回乾淨狀態，但框架不知道 Redis 存在 ——
     * 限流的視窗、冪等的紀錄都會一路累積到下一個測試。
     *
     * 更糟的是 RefreshDatabase 連 auto-increment 都還原了，所以每個測試的
     * User::factory() 都拿到 id = 1；它們自認是不同的使用者，限流卻算成同一個
     * scope，於是整個套件共用一份額度。
     *
     * 放在 base class 而不是各個測試檔：「忘記加」就是這個 bug 的成因。
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection('idempotency')->flushdb();
            Redis::connection('metrics')->flushdb();
            Redis::connection('gate')->flushdb();
        } catch (Throwable) {
            // 沒有 Redis 也要能跑其他測試。需要 Redis 的測試各自會 skip。
        }
    }
}
