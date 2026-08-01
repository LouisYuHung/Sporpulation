<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * 示範用的假資料（測試使用者、示範活動），讓 demo / staging 站台一部署完就有
 * 東西可以看。是否執行由 database.seed_demo_data 決定。
 *
 * 底下的內容都是冪等的，重跑不會產生重複資料。
 */
class DemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
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
