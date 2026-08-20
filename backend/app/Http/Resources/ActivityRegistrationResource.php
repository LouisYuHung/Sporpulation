<?php

namespace App\Http\Resources;

use App\Models\ActivityRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityRegistration
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

            // 列舉一律以 {value, label} 的形式輸出，讓用戶端完全不需要自行把
            // 原始整數對應成顯示文字。
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
