<?php

namespace Tests\Feature\Observability;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class RegistrationMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'c4d1a9e7-5b30-4f28-91ac-7e6d3f0b28aa';

    protected function setUp(): void
    {
        parent::setUp();

        // 指標寫入是 fail-open 的，所以少了 Redis 這些測試不會變紅，而是會
        // 「通過但什麼都沒測到」—— 那比紅字更糟。
        try {
            Redis::connection('metrics')->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis unavailable: '.$e->getMessage());
        }
    }

    #[Test]
    public function a_successful_registration_is_counted(): void
    {
        $this->join(Activity::factory()->withCapacity(10)->create(), User::factory()->create())
            ->assertCreated();

        $this->assertMetric('sporpulation_registration_attempts_total{outcome="granted"} 1');
    }

    /**
     * 這一個和下面那個是這批測試真正的重點：429 與 409 都是由例外產生的，而中介層
     * 收到的是 $next() 的回傳值。如果 Laravel 的路由管線沒有在每一層把例外轉成回應，
     * 這兩種結局就永遠不會被計到 —— 而症狀是「被擋下的請求數永遠是 0」，看起來像
     * 限流沒有作用，而不是像指標壞掉。
     */
    #[Test]
    public function a_throttled_attempt_is_counted_even_though_it_was_an_exception(): void
    {
        config(['rate_limits.registration.limit' => 1]);

        $activity = Activity::factory()->withCapacity(10)->create();
        $user = User::factory()->create();

        $this->join($activity, $user)->assertCreated();
        $this->join($activity, $user)->assertStatus(429);

        $this->assertMetric('sporpulation_registration_attempts_total{outcome="throttled"} 1');
    }

    #[Test]
    public function losing_the_race_is_counted_separately_from_winning_it(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();

        $this->join($activity, User::factory()->create())->assertCreated();
        $this->join($activity, User::factory()->create())->assertStatus(409);

        $this->assertMetric('sporpulation_registration_attempts_total{outcome="granted"} 1');
        $this->assertMetric('sporpulation_registration_attempts_total{outcome="rejected"} 1');
    }

    /**
     * 重播的狀態碼和真的搶到一樣（都是 201），混在一起計會讓「成功報名數」被重試灌水。
     */
    #[Test]
    public function a_replayed_response_is_not_counted_as_a_new_registration(): void
    {
        $activity = Activity::factory()->withCapacity(10)->create();
        $user = User::factory()->create();

        $this->join($activity, $user, self::KEY)->assertCreated();
        $this->join($activity, $user, self::KEY)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertMetric('sporpulation_registration_attempts_total{outcome="granted"} 1');
        $this->assertMetric('sporpulation_registration_attempts_total{outcome="replayed"} 1');
    }

    /**
     * 搶輸的請求正是延遲最有可能異常的一群。只在成功之後才記錄，會讓圖表在最需要
     * 它的時候變得好看 —— 所以 controller 用的是 finally。
     */
    #[Test]
    public function the_seat_claim_is_timed_for_winners_and_losers_alike(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();

        $this->join($activity, User::factory()->create())->assertCreated();
        $this->join($activity, User::factory()->create())->assertStatus(409);

        $this->assertMetric('sporpulation_seat_claim_duration_seconds_count{outcome="granted"} 1');
        $this->assertMetric('sporpulation_seat_claim_duration_seconds_count{outcome="rejected"} 1');
    }

    /**
     * 被限流擋下的請求根本沒有碰到名額，不該出現在佔名額的耗時分佈裡 —— 混進去會
     * 讓 p99 被一堆「其實什麼都沒做」的快速回應拉低。
     */
    #[Test]
    public function a_throttled_attempt_does_not_appear_in_the_seat_claim_histogram(): void
    {
        config(['rate_limits.registration.limit' => 1]);

        $activity = Activity::factory()->withCapacity(10)->create();
        $user = User::factory()->create();

        $this->join($activity, $user)->assertCreated();
        $this->join($activity, $user)->assertStatus(429);

        $this->assertMetric('sporpulation_seat_claim_duration_seconds_count{outcome="granted"} 1');
        $this->assertMetricMissing('sporpulation_seat_claim_duration_seconds_count{outcome="throttled"}');
    }

    #[Test]
    public function the_endpoint_speaks_the_content_type_prometheus_expects(): void
    {
        $this->get('/api/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    // --- 輔助 -----------------------------------------------------------------

    private function scrape(): string
    {
        return $this->get('/api/metrics')->getContent();
    }

    private function assertMetric(string $line): void
    {
        $this->assertStringContainsString($line, $this->scrape());
    }

    private function assertMetricMissing(string $line): void
    {
        $this->assertStringNotContainsString($line, $this->scrape());
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
