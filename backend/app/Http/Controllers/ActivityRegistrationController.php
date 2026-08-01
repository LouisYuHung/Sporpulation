<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Http\Resources\ActivityRegistrationResource;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ActivityRegistrationController extends Controller
{
    /**
     * The authenticated user's registrations. Confirmed ones by default;
     * pass ?status= to see cancelled ones instead.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'integer', Rule::enum(RegistrationStatus::class)],
        ]);

        $registrations = $request->user()->registrations()
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('activity_registrations.status', $filters['status']),
                fn ($query) => $query->confirmed(),
            )
            ->with(['activity' => fn ($query) => $query->with(ActivityController::relations($request))])
            // Ordered by when the user is due to play, not when they signed up.
            ->join('activities', 'activities.id', '=', 'activity_registrations.activity_id')
            ->orderBy('activities.starts_at')
            ->select('activity_registrations.*')
            ->get();

        return ActivityRegistrationResource::collection($registrations);
    }

    /**
     * Claim a seat.
     *
     * Idempotent: replaying the request returns the same activity with the
     * same joined_count, because the registration itself is keyed on
     * (activity_id, user_id). Responds 409 when the activity is full or has
     * already started.
     */
    public function store(Request $request, Activity $activity): JsonResponse
    {
        $activity->join($request->user());

        return $this->activity($request, $activity)->response()->setStatusCode(201);
    }

    /**
     * Give the seat back. Idempotent: cancelling twice, or cancelling without
     * ever having joined, is a no-op.
     */
    public function destroy(Request $request, Activity $activity): ActivityResource
    {
        $activity->cancel($request->user());

        return $this->activity($request, $activity);
    }

    /**
     * The activity as it now stands, so the client can refresh joined_count
     * and the caller's own status straight from the write response.
     *
     * Re-read rather than trusted from memory: other people join and cancel
     * between requests, and the count they see should be the current one.
     */
    private function activity(Request $request, Activity $activity): ActivityResource
    {
        return new ActivityResource(
            $activity->refresh()->load(ActivityController::relations($request))
        );
    }
}
