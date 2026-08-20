<?php

namespace App\Http\Resources;

use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sport
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

            // 只有透過使用者的 sports 樞紐表取得時才會出現；
            // 公開的運動清單沒有等級。
            'level' => $this->whenPivotLoaded('user_sports', fn () => $this->pivot->level),
        ];
    }
}
