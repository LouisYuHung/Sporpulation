<?php

namespace App\Http\Controllers;

use App\Http\Resources\CityResource;
use App\Models\City;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RegionController extends Controller
{
    /**
     * 縣市與其底下的行政區，供地區選擇器使用。
     *
     * 名稱會以請求語系的純字串回傳，語系由 SetLocale middleware 從 Accept-Language
     * 標頭解析而來。
     */
    public function index(): AnonymousResourceCollection
    {
        return CityResource::collection(
            City::with('districts.postalCode')->get()
        );
    }
}
