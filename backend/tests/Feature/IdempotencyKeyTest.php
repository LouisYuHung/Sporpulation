<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\District;
use App\Models\IdempotencyKey;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'a3f1c9d2-7b64-4e01-9f2a-8c5d1e0b4a77';

    #[Test]
    public function a_retry_with_the_same_key_replays_the_original_response(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        $first = $this->join($activity, $user);
        $second = $this->join($activity, $user);

        $first->assertCreated();
        $second->assertCreated();

        // 逐位元組完全相同的回應，並加上標記讓用戶端能分辨。
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertNull($first->headers->get('Idempotent-Replay'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));

        $this->assertSame(1, $activity->fresh()->joined_count);
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    #[Test]
    public function the_replay_never_reaches_the_controller(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        $this->join($activity, $user);

        // 在兩次嘗試之間，剩餘名額被別人取走了。重播是直接以儲存的結果作答，
        // 因此重試不會依新的狀態重新評估 - 而這正是儲存結果的意義所在。
        Activity::whereKey($activity->id)->update(['joined_count' => 4]);

        $this->join($activity, $user)
            ->assertCreated()
            ->assertJsonPath('data.joined_count', 1);
    }

    #[Test]
    public function a_different_key_is_a_different_request(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        $this->join($activity, $user, self::KEY);
        $second = $this->join($activity, $user, 'b7e2d4a8-1c39-4f02-8a6b-3d9e5c7f1b20');

        // 沒有發生重播，代表 controller 又跑了一次 - 而讓名額數維持在 1 的，
        // 是端點自身的冪等性。
        $this->assertNull($second->headers->get('Idempotent-Replay'));
        $this->assertSame(1, $activity->fresh()->joined_count);
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    #[Test]
    public function reusing_a_key_for_a_different_request_is_refused(): void
    {
        $user = User::factory()->create();
        $first = Activity::factory()->withCapacity(4)->create();
        $other = Activity::factory()->withCapacity(4)->create();

        $this->join($first, $user);

        $this->join($other, $user)
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');

        // 第二個活動完全沒有被動到。
        $this->assertSame(0, $other->fresh()->joined_count);
    }

    #[Test]
    public function a_key_still_in_flight_is_reported_as_in_progress(): void
    {
        $user = User::factory()->create();

        $this->createActivity($user)->assertCreated();

        // 把已儲存的紀錄倒回該請求仍在執行時的樣子 - 這正是並行的重複請求會看到
        // 的狀態。沿用同一列可以讓測試不必碰 fingerprint 的內部細節。
        IdempotencyKey::query()->update(['status' => null, 'body' => null]);

        $this->createActivity($user)
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_in_progress');

        // 被擋下的重試什麼都沒有建立。
        $this->assertDatabaseCount('activities', 1);
    }

    #[Test]
    public function keys_are_scoped_to_the_caller(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();

        // 兩個人剛好選到同一把 key 時不能互相衝突。
        $this->join($activity, User::factory()->create())->assertCreated();
        $second = $this->join($activity, User::factory()->create());

        $second->assertCreated();
        $this->assertNull($second->headers->get('Idempotent-Replay'));
        $this->assertSame(2, $activity->fresh()->joined_count);
    }

    #[Test]
    public function a_failed_request_releases_the_key_so_it_can_be_retried(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();
        $user = User::factory()->create();

        $occupant = User::factory()->create();
        $activity->join($occupant);

        // 額滿：什麼都沒做，因此這個結果不該被儲存。
        $this->join($activity, $user)
            ->assertStatus(409)
            ->assertJsonPath('code', 'activity_full');

        // 有名額釋出了。同一把 key 必須給出全新的答案，而不是重播「額滿」或回報
        // 自己仍在處理中。
        $activity->cancel($occupant);

        $response = $this->join($activity, $user);

        $response->assertCreated();
        $this->assertNull($response->headers->get('Idempotent-Replay'));
        $this->assertSame(1, $activity->fresh()->joined_count);
    }

    #[Test]
    public function records_survive_the_cache_being_cleared(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        $this->join($activity, $user)->assertCreated();

        // 這些紀錄之所以放在資料表裡：清快取是例行操作，而它不該無聲無息地把這道
        // 保護關掉。
        $this->artisan('cache:clear')->assertSuccessful();

        $this->assertSame('true', $this->join($activity, $user)->headers->get('Idempotent-Replay'));
        $this->assertSame(1, $activity->fresh()->joined_count);
    }

    #[Test]
    public function an_expired_record_frees_the_key_for_reuse(): void
    {
        $user = User::factory()->create();

        $this->createActivity($user)->assertCreated();

        IdempotencyKey::query()->update(['expires_at' => now()->subMinute()]);

        // 超過 TTL 之後紀錄已無意義，因此同一把 key 會開啟一個全新的請求，而不是
        // 重播過時的答案。
        $response = $this->createActivity($user);

        $response->assertCreated();
        $this->assertNull($response->headers->get('Idempotent-Replay'));
        $this->assertSame(1, IdempotencyKey::count());

        // 保護已經過期，所以第二次真的又建了一場 - 這正是 TTL 的代價。
        $this->assertDatabaseCount('activities', 2);
    }

    #[Test]
    public function expired_records_are_prunable(): void
    {
        $this->createActivity(User::factory()->create());
        $this->createActivity(User::factory()->create(), 'c1d4e7a0-2b58-4c93-8f6d-1a7e3b9c5d02');

        IdempotencyKey::query()->limit(1)->update(['expires_at' => now()->subDay()]);

        $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();

        $this->assertSame(1, IdempotencyKey::count());
    }

    #[Test]
    public function requests_without_a_key_are_untouched(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/activities/{$activity->id}/registration");

        $response->assertCreated();
        $this->assertNull($response->headers->get('Idempotent-Replay'));
        $this->assertSame(1, $activity->fresh()->joined_count);
    }

    #[Test]
    public function a_key_that_is_too_short_is_rejected(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();

        $this->join($activity, User::factory()->create(), 'short')
            ->assertJsonValidationErrors('Idempotency-Key');
    }

    #[Test]
    public function creating_an_activity_twice_with_one_key_creates_one_activity(): void
    {
        $user = User::factory()->create();

        $payload = [
            'sport_id' => Sport::factory()->create()->id,
            'district_id' => District::factory()->create()->id,
            'title' => '週末鬥牛',
            'location' => '大安運動中心',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHours(2)->toIso8601String(),
            'capacity' => 10,
        ];

        $first = $this->createActivity($user);
        $second = $this->createActivity($user);

        $first->assertCreated();
        $second->assertCreated();

        // 沒有帶 key 的話，這裡會留下兩筆一模一樣的活動 - 這個情境沒有天然的唯一
        // 鍵可以退而求其次。
        $this->assertDatabaseCount('activities', 1);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    private function join(Activity $activity, User $user, string $key = self::KEY)
    {
        return $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/activities/{$activity->id}/registration");
    }

    private ?array $activityPayload = null;

    /**
     * 打一條綁在 database store 上的路由。
     *
     * 建立活動用的是 idempotent:database（沒有天然唯一鍵，見 routes/api.php），
     * 因此想驗證資料表層級的機制 —— expires_at、model:prune —— 就必須走這條，
     * 報名那條已經在 Redis 上了。
     */
    private function createActivity(User $user, string $key = self::KEY)
    {
        // 同一個測試裡重複呼叫必須送出一模一樣的 body：fingerprint 是
        // method + path + body 的雜湊，body 變了就會被判定成「同一把 key 用在
        // 不同的請求」而回 409，而不是我們想測的那個結果。
        $this->activityPayload ??= [
            'sport_id' => Sport::factory()->create()->id,
            'district_id' => District::factory()->create()->id,
            'title' => '週末鬥牛',
            'location' => '大安運動中心',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHours(2)->toIso8601String(),
            'capacity' => 10,
        ];

        return $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/activities', $this->activityPayload);
    }
}
