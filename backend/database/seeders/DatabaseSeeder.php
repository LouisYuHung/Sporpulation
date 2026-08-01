<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * 填充應用程式的資料庫。
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        // 假資料只給本地開發用。正式環境的基礎資料是由容器啟動時單獨執行
        // ReferenceDataSeeder 來填的，不會走到這裡。
        if (app()->isProduction()) {
            return;
        }

        // User::factory(10)->create();

        if (! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // 放在使用者之後，因為它會拿那位使用者當主辦人。
        $this->call(ActivitySeeder::class);
    }
}
