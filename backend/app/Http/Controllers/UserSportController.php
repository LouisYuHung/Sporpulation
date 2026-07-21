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
     * The sports the authenticated user plays.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->sports($request);
    }

    /**
     * Add a sport, optionally with a self-rated level.
     * Idempotent: re-adding a removed sport restores it.
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
     * Update the self-rated level for a sport the user already plays.
     * Pass level: null to clear the rating.
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
     * Remove a sport. Idempotent: removing one not played is a no-op.
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
