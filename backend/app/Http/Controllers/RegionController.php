<?php

namespace App\Http\Controllers;

use App\Http\Resources\CityResource;
use App\Models\City;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RegionController extends Controller
{
    /**
     * Cities with their districts, for the area picker.
     *
     * Names come back as plain strings in the request locale, resolved by
     * the SetLocale middleware from the Accept-Language header.
     */
    public function index(): AnonymousResourceCollection
    {
        return CityResource::collection(
            City::with('districts.postalCode')->get()
        );
    }
}
