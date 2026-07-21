<?php

namespace App\Http\Controllers;

use App\Http\Resources\SportResource;
use App\Models\Sport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SportController extends Controller
{
    /**
     * The full sport catalogue, for the picker.
     */
    public function index(): AnonymousResourceCollection
    {
        return SportResource::collection(Sport::all());
    }
}
