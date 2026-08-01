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
     * 已登入使用者標記的常用地區。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->areas($request);
    }

    /**
     * 標記一個常用地區。具冪等性：重新加入曾移除的地區會將它還原。
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
     * 取消標記一個常用地區。具冪等性：移除未標記的地區不會有任何作用。
     */
    public function destroy(Request $request, District $district): AnonymousResourceCollection
    {
        $request->user()->removeArea($district->id);

        return $this->areas($request);
    }

    /**
     * 使用者目前的常用地區，每筆都附帶所屬縣市。
     */
    private function areas(Request $request): AnonymousResourceCollection
    {
        return DistrictResource::collection(
            $request->user()->areas()->with(['city', 'postalCode'])->get()
        );
    }
}
