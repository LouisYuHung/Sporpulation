<?php

namespace App\Http\Controllers;

use App\Http\Resources\DistrictResource;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserAreaController extends Controller
{
    /**
     * The areas the authenticated user has tagged.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->areas($request);
    }

    /**
     * Tag an area. Idempotent: re-adding a removed area restores it.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'district_id' => ['required', 'integer', 'exists:districts,id'],
        ]);

        $request->user()->addArea($data['district_id']);

        return $this->areas($request)->response()->setStatusCode(201);
    }

    /**
     * Untag an area. Idempotent: removing an untagged area is a no-op.
     */
    public function destroy(Request $request, District $district): AnonymousResourceCollection
    {
        $request->user()->removeArea($district->id);

        return $this->areas($request);
    }

    /**
     * The user's current areas, each with its city.
     */
    private function areas(Request $request): AnonymousResourceCollection
    {
        return DistrictResource::collection(
            $request->user()->areas()->with(['city', 'postalCode'])->get()
        );
    }
}
