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

        // Byte for byte the same answer, and flagged so the client can tell.
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

        // Someone else takes the remaining seats between the two attempts. A
        // replay answers from the store, so the retry is not re-evaluated
        // against the new state - which is the whole point of a stored result.
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

        // No replay, so the controller ran again - and the endpoint's own
        // idempotency is what keeps the seat count at one.
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

        // The second activity was never touched.
        $this->assertSame(0, $other->fresh()->joined_count);
    }

    #[Test]
    public function a_key_still_in_flight_is_reported_as_in_progress(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        $this->join($activity, $user)->assertCreated();

        // Rewind the stored record to how it looked while that request was
        // still running - which is exactly what a concurrent duplicate finds.
        // Reusing the row keeps the test off the fingerprint's internals.
        IdempotencyKey::query()->update(['status' => null, 'body' => null]);

        $this->join($activity, $user)
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_in_progress');

        // The blocked retry changed nothing.
        $this->assertSame(1, $activity->fresh()->joined_count);
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    #[Test]
    public function keys_are_scoped_to_the_caller(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();

        // Two people happening to pick the same key must not collide.
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

        // Full: nothing was done, so this outcome must not be stored.
        $this->join($activity, $user)
            ->assertStatus(409)
            ->assertJsonPath('code', 'activity_full');

        // A seat opens up. The same key has to give a fresh answer rather than
        // replaying "full" or reporting itself as still in progress.
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

        // The reason these live in a table: clearing the cache is routine, and
        // it must not quietly switch the protection off.
        $this->artisan('cache:clear')->assertSuccessful();

        $this->assertSame('true', $this->join($activity, $user)->headers->get('Idempotent-Replay'));
        $this->assertSame(1, $activity->fresh()->joined_count);
    }

    #[Test]
    public function an_expired_record_frees_the_key_for_reuse(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        $this->join($activity, $user)->assertCreated();

        IdempotencyKey::query()->update(['expires_at' => now()->subMinute()]);

        // Past its TTL the record means nothing, so the same key starts a
        // fresh request rather than replaying a stale answer.
        $response = $this->join($activity, $user);

        $response->assertCreated();
        $this->assertNull($response->headers->get('Idempotent-Replay'));
        $this->assertSame(1, IdempotencyKey::count());
    }

    #[Test]
    public function expired_records_are_prunable(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();

        $this->join($activity, User::factory()->create())->assertCreated();
        $this->join($activity, User::factory()->create(), 'c1d4e7a0-2b58-4c93-8f6d-1a7e3b9c5d02')->assertCreated();

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

        $create = fn () => $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson('/api/activities', $payload);

        $first = $create();
        $second = $create();

        $first->assertCreated();
        $second->assertCreated();

        // Without a key this would leave two identical activities behind -
        // there is no natural unique key to fall back on here.
        $this->assertDatabaseCount('activities', 1);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    private function join(Activity $activity, User $user, string $key = self::KEY)
    {
        return $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/activities/{$activity->id}/registration");
    }
}
