<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),

            // remaining_seats and is_full are derived, but sending them saves
            // every client from reimplementing the same arithmetic.
            'capacity' => $this->capacity,
            'joined_count' => $this->joined_count,
            'remaining_seats' => $this->remainingSeats(),
            'is_full' => $this->isFull(),
            'is_open' => $this->isOpenForRegistration(),

            'host' => new UserResource($this->whenLoaded('host')),
            'sport' => new SportResource($this->whenLoaded('sport')),
            'district' => new DistrictResource($this->whenLoaded('district')),

            // The controller eager loads `registrations` constrained to the
            // current user, so this holds at most their own row. Null means
            // the user has never registered; a cancelled registration still
            // shows up, with its status.
            'my_registration' => $this->whenLoaded('registrations', function () {
                $registration = $this->registrations->first();

                return $registration === null
                    ? null
                    : new ActivityRegistrationResource($registration);
            }),
        ];
    }
}
