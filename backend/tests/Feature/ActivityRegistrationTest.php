<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function joining_claims_a_seat(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/activities/{$activity->id}/registration");

        $response->assertCreated()
            ->assertJsonPath('data.joined_count', 1)
            ->assertJsonPath('data.remaining_seats', 3)
            ->assertJsonPath('data.is_full', false)
            ->assertJsonPath('data.my_registration.status.value', RegistrationStatus::Confirmed->value);

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id,
            'user_id' => $user->id,
            'status' => RegistrationStatus::Confirmed->value,
        ]);
    }

    #[Test]
    public function joining_twice_is_idempotent(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();

        // 第二次呼叫模擬按鈕被連點兩下，或用戶端在逾時後重試：結果相同、名額也
        // 相同。
        $first = $this->actingAs($user)->postJson("/api/activities/{$activity->id}/registration");
        $second = $this->actingAs($user)->postJson("/api/activities/{$activity->id}/registration");

        $first->assertCreated()->assertJsonPath('data.joined_count', 1);
        $second->assertCreated()->assertJsonPath('data.joined_count', 1);

        $this->assertSame(1, $activity->fresh()->joined_count);
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    #[Test]
    public function joining_a_full_activity_is_rejected(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();
        $activity->join(User::factory()->create());

        $response = $this->actingAs(User::factory()->create())
            ->postJson("/api/activities/{$activity->id}/registration");

        $response->assertStatus(409)
            ->assertJsonPath('code', 'activity_full');

        $this->assertSame(1, $activity->fresh()->joined_count);
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    #[Test]
    public function the_seat_count_never_exceeds_capacity(): void
    {
        $activity = Activity::factory()->withCapacity(3)->create();

        $accepted = 0;

        foreach (User::factory()->count(10)->create() as $user) {
            $status = $this->actingAs($user)
                ->postJson("/api/activities/{$activity->id}/registration")
                ->status();

            $accepted += $status === 201 ? 1 : 0;
        }

        $this->assertSame(3, $accepted);
        $this->assertSame(3, $activity->fresh()->joined_count);
        $this->assertTrue($activity->fresh()->isFull());
    }

    #[Test]
    public function cancelling_frees_the_seat(): void
    {
        $activity = Activity::factory()->withCapacity(2)->create();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/activities/{$activity->id}/registration");

        $response = $this->actingAs($user)
            ->deleteJson("/api/activities/{$activity->id}/registration");

        $response->assertOk()
            ->assertJsonPath('data.joined_count', 0)
            ->assertJsonPath('data.remaining_seats', 2)
            ->assertJsonPath('data.my_registration.status.value', RegistrationStatus::Cancelled->value);

        // 資料列會保留，因此歷程得以留存，唯一索引在使用者回頭時也仍認得他。
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    #[Test]
    public function cancelling_twice_releases_only_one_seat(): void
    {
        $activity = Activity::factory()->withCapacity(2)->create();
        $host = User::factory()->create();
        $user = User::factory()->create();

        $activity->join($host);
        $this->actingAs($user)->postJson("/api/activities/{$activity->id}/registration");
        $this->assertSame(2, $activity->fresh()->joined_count);

        $this->actingAs($user)->deleteJson("/api/activities/{$activity->id}/registration");
        $this->actingAs($user)->deleteJson("/api/activities/{$activity->id}/registration")
            ->assertOk()
            ->assertJsonPath('data.joined_count', 1);

        $this->assertSame(1, $activity->fresh()->joined_count);
    }

    #[Test]
    public function cancelling_without_ever_joining_is_a_no_op(): void
    {
        $activity = Activity::factory()->withCapacity(2)->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/activities/{$activity->id}/registration")
            ->assertOk()
            ->assertJsonPath('data.joined_count', 0)
            ->assertJsonPath('data.my_registration', null);

        $this->assertDatabaseCount('activity_registrations', 0);
    }

    #[Test]
    public function rejoining_after_cancelling_reuses_the_registration(): void
    {
        $activity = Activity::factory()->withCapacity(2)->create();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/activities/{$activity->id}/registration");
        $this->actingAs($user)->deleteJson("/api/activities/{$activity->id}/registration");

        $this->actingAs($user)->postJson("/api/activities/{$activity->id}/registration")
            ->assertCreated()
            ->assertJsonPath('data.joined_count', 1)
            ->assertJsonPath('data.my_registration.status.value', RegistrationStatus::Confirmed->value);

        $this->assertDatabaseCount('activity_registrations', 1);
        $this->assertNull($activity->registrationFor($user)->cancelled_at);
    }

    #[Test]
    public function rejoining_is_rejected_once_someone_took_the_freed_seat(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $activity->join($user);
        $activity->cancel($user);
        $activity->join($other);

        $this->actingAs($user)->postJson("/api/activities/{$activity->id}/registration")
            ->assertStatus(409)
            ->assertJsonPath('code', 'activity_full');

        $this->assertSame(1, $activity->fresh()->joined_count);
        $this->assertSame(
            RegistrationStatus::Cancelled,
            $activity->registrationFor($user)->status,
        );
    }

    #[Test]
    public function joining_an_activity_that_has_started_is_rejected(): void
    {
        $activity = Activity::factory()->started()->withCapacity(4)->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/activities/{$activity->id}/registration")
            ->assertStatus(409)
            ->assertJsonPath('code', 'activity_closed');

        $this->assertSame(0, $activity->fresh()->joined_count);
    }

    #[Test]
    public function guests_cannot_join(): void
    {
        $activity = Activity::factory()->create();

        $this->postJson("/api/activities/{$activity->id}/registration")
            ->assertUnauthorized();
    }

    #[Test]
    public function my_registrations_lists_confirmed_seats_by_start_time(): void
    {
        $user = User::factory()->create();

        $later = Activity::factory()->create(['starts_at' => now()->addDays(5)]);
        $sooner = Activity::factory()->create(['starts_at' => now()->addDay()]);
        $cancelled = Activity::factory()->create(['starts_at' => now()->addDays(2)]);

        $later->join($user);
        $sooner->join($user);
        $cancelled->join($user);
        $cancelled->cancel($user);

        $this->actingAs($user)->getJson('/api/me/registrations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.activity.id', $sooner->id)
            ->assertJsonPath('data.1.activity.id', $later->id);

        $this->actingAs($user)
            ->getJson('/api/me/registrations?status='.RegistrationStatus::Cancelled->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity.id', $cancelled->id);
    }
}
