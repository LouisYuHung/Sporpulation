<?php

namespace App\Http\Controllers;

use App\Exceptions\ResourceNotFoundException;
use App\Http\Resources\SportResource;
use App\Models\Sport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserSportController extends Controller
{
    /**
     * 已登入使用者從事的運動。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->sports($request);
    }

    /**
     * 新增一項運動，可一併帶入自評等級。
     * 具冪等性：重新加入曾移除的運動會將它還原。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sport_id' => ['required', 'integer', 'exists:sports,id'],
            'level' => ['nullable', 'integer', 'between:1,10'],
        ]);

        $request->user()->addSport($data['sport_id'], $data['level'] ?? null);

        return $this->sports($request)->response()->setStatusCode(201);
    }

    /**
     * 更新使用者已標記運動的自評等級。
     * 傳入 level: null 可清除等級。
     */
    public function update(Request $request, Sport $sport): AnonymousResourceCollection
    {
        $data = $request->validate([
            'level' => ['present', 'nullable', 'integer', 'between:1,10'],
        ]);

        $updated = $request->user()->setSportLevel($sport->id, $data['level']);

        if ($updated === null) {
            throw new ResourceNotFoundException(__('messages.sports.not_tagged'));
        }

        return $this->sports($request);
    }

    /**
     * 移除一項運動。具冪等性：移除未標記的運動不會有任何作用。
     */
    public function destroy(Request $request, Sport $sport): AnonymousResourceCollection
    {
        $request->user()->removeSport($sport->id);

        return $this->sports($request);
    }

    private function sports(Request $request): AnonymousResourceCollection
    {
        return SportResource::collection($request->user()->sports()->get());
    }
}
