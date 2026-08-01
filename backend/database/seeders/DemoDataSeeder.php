<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * 示範用的假資料（測試使用者、示範活動），讓 demo / staging 站台一部署完就有
 * 東西可以看。是否執行由 database.seed_demo_data 決定。
 *
 * 注意：這裡刻意不使用 model factory。factory 依賴 fakerphp/faker，而它是
 * require-dev 的套件，正式映像檔以 composer install --no-dev 建置時並不存在，
 * 呼叫下去會炸在「Call to undefined function fake()」。
 *
 * 底下的內容都是冪等的，重跑不會產生重複資料。
 */
class DemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEMO_EMAIL = 'test@example.com';

    public function run(): void
    {
        if (! User::where('email', self::DEMO_EMAIL)->exists()) {
            $user = new User;

            // 用 forceFill 是因為 email_verified_at 不在 fillable 清單裡。
            // password 有 'hashed' cast，這裡給明文即可，儲存時會自動雜湊。
            $user->forceFill([
                'name' => 'Test User',
                'email' => self::DEMO_EMAIL,
                'password' => 'password',
                'email_verified_at' => now(),
            ])->save();
        }

        // 放在使用者之後，因為它會拿那位使用者當主辦人。
        $this->call(ActivitySeeder::class);
    }
}
