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

            // remaining_seats 與 is_full 都是推導出來的，但一併回傳可以省下每個
            // 用戶端各自重寫同一套計算。
            'capacity' => $this->capacity,
            'joined_count' => $this->joined_count,
            'remaining_seats' => $this->remainingSeats(),
            'is_full' => $this->isFull(),
            'is_open' => $this->isOpenForRegistration(),

            'host' => new UserResource($this->whenLoaded('host')),
            'sport' => new SportResource($this->whenLoaded('sport')),
            'district' => new DistrictResource($this->whenLoaded('district')),

            // Controller 預先載入的 `registrations` 已限縮成只有目前使用者，因此
            // 這裡最多只會有他自己的那一列。null 代表使用者從未報名；已取消的報名
            // 仍會出現，並附帶其狀態。
            'my_registration' => $this->whenLoaded('registrations', function () {
                $registration = $this->registrations->first();

                return $registration === null
                    ? null
                    : new ActivityRegistrationResource($registration);
            }),
        ];
    }
}
