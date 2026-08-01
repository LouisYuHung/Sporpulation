<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    /**
     * Upcoming activities, soonest first, optionally narrowed by sport or
     * district.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'sport_id' => ['nullable', 'integer', 'exists:sports,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $activities = Activity::query()
            ->upcoming()
            ->with(self::relations($request))
            ->when(
                isset($filters['sport_id']),
                fn (Builder $query) => $query->where('sport_id', $filters['sport_id'])
            )
            ->when(
                isset($filters['district_id']),
                fn (Builder $query) => $query->where('district_id', $filters['district_id'])
            )
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ActivityResource::collection($activities);
    }

    /**
     * Organise an activity. The host is not registered automatically; they
     * join like anyone else if they intend to play.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sport_id' => ['required', 'integer', 'exists:sports,id'],
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'location' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'between:1,999'],
        ]);

        $activity = $request->user()->hostedActivities()->create($data);

        return $this->show($request, $activity)->response()->setStatusCode(201);
    }

    public function show(Request $request, Activity $activity): ActivityResource
    {
        return new ActivityResource($activity->load(self::relations($request)));
    }

    /**
     * Relations every activity payload carries.
     *
     * `registrations` is constrained to the caller so the payload can report
     * their own registration status without a second request. Guests get
     * nothing, and `my_registration` stays absent from the response.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(Request $request): array
    {
        $relations = ['host', 'sport', 'district.city'];

        if ($user = $request->user()) {
            $relations['registrations'] = fn ($query) => $query->where('user_id', $user->id);
        }

        return $relations;
    }
}
