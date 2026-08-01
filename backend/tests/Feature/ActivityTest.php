<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\District;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function anyone_can_browse_upcoming_activities(): void
    {
        $soon = Activity::factory()->create(['starts_at' => now()->addDay()]);
        $later = Activity::factory()->create(['starts_at' => now()->addWeek()]);
        Activity::factory()->started()->create();

        $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $soon->id)
            ->assertJsonPath('data.1.id', $later->id)
            // Guests have no registration to report.
            ->assertJsonMissingPath('data.0.my_registration');
    }

    #[Test]
    public function the_listing_carries_the_callers_own_registration(): void
    {
        $activity = Activity::factory()->withCapacity(4)->create();
        $user = User::factory()->create();
        $activity->join($user);

        $this->actingAs($user)->getJson('/api/activities')
            ->assertOk()
            ->assertJsonPath('data.0.my_registration.status.value', 1);

        $this->actingAs(User::factory()->create())->getJson('/api/activities')
            ->assertOk()
            ->assertJsonPath('data.0.my_registration', null);
    }

    #[Test]
    public function activities_can_be_filtered_by_sport_and_district(): void
    {
        $sport = Sport::factory()->create();
        $district = District::factory()->create();

        $match = Activity::factory()->create([
            'sport_id' => $sport->id,
            'district_id' => $district->id,
        ]);
        Activity::factory()->create(['district_id' => $district->id]);
        Activity::factory()->create(['sport_id' => $sport->id]);

        $this->getJson("/api/activities?sport_id={$sport->id}&district_id={$district->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    #[Test]
    public function an_activity_can_be_organised(): void
    {
        $user = User::factory()->create();
        $sport = Sport::factory()->create();
        $district = District::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/activities', [
            'sport_id' => $sport->id,
            'district_id' => $district->id,
            'title' => '週末鬥牛',
            'location' => '大安運動中心',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHours(2)->toIso8601String(),
            'capacity' => 10,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', '週末鬥牛')
            ->assertJsonPath('data.capacity', 10)
            // The host is not signed up automatically.
            ->assertJsonPath('data.joined_count', 0)
            ->assertJsonPath('data.host.id', $user->id);

        $this->assertDatabaseHas('activities', [
            'host_id' => $user->id,
            'title' => '週末鬥牛',
            'joined_count' => 0,
        ]);
    }

    #[Test]
    public function organising_rejects_an_end_before_the_start_and_a_start_in_the_past(): void
    {
        $payload = [
            'sport_id' => Sport::factory()->create()->id,
            'district_id' => District::factory()->create()->id,
            'title' => '早鳥晨跑',
            'location' => '河濱公園',
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->subDays(2)->toIso8601String(),
            'capacity' => 10,
        ];

        $this->actingAs(User::factory()->create())
            ->postJson('/api/activities', $payload)
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);
    }

    #[Test]
    public function guests_cannot_organise_an_activity(): void
    {
        $this->postJson('/api/activities', [])->assertUnauthorized();
    }

    #[Test]
    public function an_activity_can_be_viewed(): void
    {
        $activity = Activity::factory()->withCapacity(6)->create();

        $this->getJson("/api/activities/{$activity->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $activity->id)
            ->assertJsonPath('data.remaining_seats', 6)
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.district.city.id', $activity->district->city_id);
    }

    #[Test]
    public function an_unknown_activity_is_a_localised_404(): void
    {
        $this->getJson('/api/activities/999')
            ->assertNotFound()
            ->assertJsonPath('message', __('messages.errors.not_found'));
    }
}
