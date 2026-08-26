<?php

namespace Tests\Feature;

use App\Idempotency\RedisIdempotencyStore;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingIdempotencyStore;
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

        $store = $this->spyOnTheRegistrationStore();

        $activity = Activity::factory()->withCapacity(10)->create();
        $user = User::factory()->create();

        // 一定要帶 Idempotency-Key：沒帶的話 EnsureIdempotentRequest 會直接放行，
        // 不管順序如何都不會碰 store，這個測試就變成永遠通過。
        $this->join($activity, $user, self::KEY)->assertCreated();

        // 先確立前提：正常的請求確實會碰到 store。少了這行，替身沒被裝上去
        // （容器綁錯、factory 改成別的解析方式）也一樣是綠的。
        $this->assertNotSame([], $store->calls, '替身沒有被裝上去，後面那句斷言等於沒測');

        $store->forgetCalls();

        $this->join($activity, $user, self::KEY.'-second')->assertStatus(429);

        $this->assertSame([], $store->calls, '限流必須擋在冪等之前，否則被擋的請求仍會佔位再釋放');
    }

    /**
     * 把報名路由用的冪等 store 換成會記帳的替身。
     *
     * 路由掛的是 idempotent:redis，IdempotencyStoreFactory 是拿類別名去問容器要
     * 實例的，所以把那個類別綁成我們的物件就換得掉 —— 不必動到 config 或路由。
     */
    private function spyOnTheRegistrationStore(): RecordingIdempotencyStore
    {
        $spy = new RecordingIdempotencyStore($this->app->make(RedisIdempotencyStore::class));

        $this->app->instance(RedisIdempotencyStore::class, $spy);

        return $spy;
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
