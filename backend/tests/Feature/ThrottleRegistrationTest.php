<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class ThrottleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'b7e2f4a1-3c85-4d92-8e10-6f9a2b5c7d31';

    protected function setUp(): void
    {
        parent::setUp();

        // 限流是 fail open 的：Redis 不可達時 middleware 直接放行。所以少了 Redis
        // 這些測試不會失敗，而是會「通過但什麼都沒測到」—— 那比紅字更糟。
        try {
            Redis::connection('idempotency')->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis unavailable: '.$e->getMessage());
        }
    }

    #[Test]
    public function attempts_beyond_the_limit_are_rejected(): void
    {
        // 明確覆寫設定，測試才不會依賴 config 預設值 —— 而且只打 3 次比打 6 次快。
        // fromConfig() 是每個請求呼叫一次，所以這裡覆寫得到。
        config(['rate_limits.registration.limit' => 2]);

        $user = User::factory()->create();
        $activity = Activity::factory()->withCapacity(10)->create();

        $this->join($activity, $user)->assertCreated();

        // 第二次會撞 unique(activity_id, user_id) 而回 409 —— 但它仍然消耗了一格額度，
        // 因為限流在領域邏輯之前。這正是重點：擋的是流量，不是「成功的報名」。
        $this->join($activity, $user);

        $this->join($activity, $user)->assertStatus(429);
    }

    #[Test]
    public function a_rejected_attempt_says_when_to_retry(): void
    {
        $this->freezeTime();

        config([
            'rate_limits.registration.limit' => 1,
            'rate_limits.registration.window' => 60,
        ]);

        $activity = Activity::factory()->withCapacity(10)->create();
        $user = User::factory()->create();

        $this->join($activity, $user)->assertCreated();

        $response = $this->join($activity, $user);

        $response->assertStatus(429)
            // 唯一那次嘗試在 t=0 進入視窗，t=60 才離開；現在是 t=0，所以要等滿 60 秒。
            ->assertHeader('Retry-After', '60')
            // code 不隨語系改變，用戶端可以直接依它分支 —— 跟你 409 那組同一個約定。
            ->assertJsonPath('code', 'too_many_requests');
    }

    #[Test]
    public function separate_users_do_not_share_a_budget(): void
    {
        config(['rate_limits.registration.limit' => 1]);

        $activity = Activity::factory()->withCapacity(10)->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->join($activity, $alice)->assertCreated();
        $this->join($activity, $alice)->assertStatus(429);   // 先確立 alice 的額度用光了

        // 測試環境下兩人的 IP 都是 127.0.0.1 —— 如果 scope() 退回了 IP，或 auth 排在
        // 限流之後導致 user() 是 null，這一行就會拿到 429。
        $this->join($activity, $bob)->assertCreated();
    }

    #[Test]
    public function a_throttled_request_never_reaches_the_idempotency_store(): void
    {
        config(['rate_limits.registration.limit' => 1]);

        $activity = Activity::factory()->withCapacity(10)->create();
        $user = User::factory()->create();

        $this->join($activity, $user, self::KEY)->assertCreated();

        $queries = [];
        // 注意是 function () use (&$queries)，不能用 fn () —— 箭頭函式是值捕獲，
        // 在裡面 append 不會影響外面的陣列。
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        // 一定要帶 Idempotency-Key：沒帶的話 EnsureIdempotentRequest 會直接放行，
        // 不管順序如何都不會碰資料表，這個測試就變成永遠通過。
        $this->join($activity, $user, self::KEY.'-second')->assertStatus(429);

        $touched = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'idempotency_keys'),
        ));

        $this->assertSame([], $touched, '限流必須擋在冪等之前，否則被擋的請求仍會佔位再釋放');
    }

    private function join(Activity $activity, User $user, ?string $key = null)
    {
        $request = $this->actingAs($user);

        if ($key !== null) {
            $request = $request->withHeader('Idempotency-Key', $key);
        }

        return $request->postJson("/api/activities/{$activity->id}/registration");
    }
}
