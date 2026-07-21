<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Sport
 */
class SportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // Only present when reached through a user's sports pivot;
            // the public catalogue has no level.
            'level' => $this->whenPivotLoaded('user_sports', fn () => $this->pivot->level),
        ];
    }
}
