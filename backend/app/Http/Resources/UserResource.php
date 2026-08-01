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

            // 列舉一律以 {value, label} 的形式輸出，讓用戶端完全不需要自行把原始
            // 整數對應成顯示文字。null 代表「未指定」。
            'sex' => $this->sex === null ? null : [
                'value' => $this->sex->value,
                'label' => $this->sex->label(),
            ],

            'areas' => DistrictResource::collection($this->whenLoaded('areas')),
            'sports' => SportResource::collection($this->whenLoaded('sports')),
        ];
    }
}
