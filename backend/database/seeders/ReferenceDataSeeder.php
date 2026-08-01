<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * 應用程式運作所需的基礎資料（縣市、行政區、郵遞區號、運動類型）。
 *
 * 這些不是測試假資料，正式環境同樣需要，因此由容器啟動流程自動執行。
 * 底下每個 seeder 都是冪等的，重跑不會產生重複資料。
 */
class ReferenceDataSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            CitySeeder::class,
            DistrictSeeder::class,
            PostalCodeSeeder::class,
            SportSeeder::class,
        ]);
    }
}
