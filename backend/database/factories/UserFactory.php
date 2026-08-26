<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * 目前 factory 所使用的密碼。
     */
    protected static ?string $password;

    /**
     * 定義模型的預設狀態。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // fake()->unique() 的記憶只存在於單一 PHP 行程裡。測試用 RefreshDatabase
            // 每次清空資料庫，所以那個假設成立；但壓測指令是往一個持續累積的資料庫
            // 寫，而且每一輪都是新行程 —— Faker 重新開始記，資料庫卻沒有忘記。
            //
            // 改成「由建構方式保證唯一」，而不是「靠記得自己發過什麼」。前者不依賴
            // 任何跨呼叫的狀態，也就不會因為行程換了而失效。
            'email' => Str::lower(fake()->userName()).'-'.Str::random(8).'@example.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * 表示這個模型的 email 尚未驗證。
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
