<?php

namespace App\Http\Controllers;

use App\Http\Resources\SportResource;
use App\Models\Sport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SportController extends Controller
{
    /**
     * 完整的運動項目清單，供選擇器使用。
     */
    public function index(): AnonymousResourceCollection
    {
        return SportResource::collection(Sport::all());
    }
}
