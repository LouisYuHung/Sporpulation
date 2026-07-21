<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'avatar' => $this->avatar,
            'email' => $this->email,

            // Enums are exposed as {value, label} so the client never has to
            // map a raw int to display text. Null means "not specified".
            'sex' => $this->sex === null ? null : [
                'value' => $this->sex->value,
                'label' => $this->sex->label(),
            ],

            'areas' => DistrictResource::collection($this->whenLoaded('areas')),
            'sports' => SportResource::collection($this->whenLoaded('sports')),
        ];
    }
}
