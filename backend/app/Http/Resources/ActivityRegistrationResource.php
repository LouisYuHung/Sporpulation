<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ActivityRegistration
 */
class ActivityRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Enums are exposed as {value, label} so the client never has to
            // map a raw int to display text.
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'joined_at' => $this->joined_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            'activity' => new ActivityResource($this->whenLoaded('activity')),
        ];
    }
}
