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
        $this->call([
            CitySeeder::class,
            DistrictSeeder::class,
            PostalCodeSeeder::class,
            SportSeeder::class,
        ]);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // 放在使用者之後，因為它會拿那位使用者當主辦人。
        $this->call(ActivitySeeder::class);
    }
}
