<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * 填充應用程式的資料庫。
     *
     * 容器每次啟動都會執行這個 seeder，所以底下呼叫的每個 seeder 都必須是冪等的。
     */
    public function run(): void
    {
        // 基礎資料，任何環境都需要。
        $this->call(ReferenceDataSeeder::class);

        // 假資料，預設只在非正式環境填，可用 SEED_DEMO_DATA 覆寫。
        if (config('database.seed_demo_data')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
