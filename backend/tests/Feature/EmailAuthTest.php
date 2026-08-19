<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailAuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_account_can_be_registered(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => '周禹宏',
            'email' => 'louis@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'louis@example.com')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', ['email' => 'louis@example.com']);

        // 以雜湊形式儲存，絕不明文保存。
        $this->assertNotSame('correct-horse-battery', User::first()->password);
    }

    #[Test]
    public function registering_rejects_a_taken_email_and_an_unconfirmed_password(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'different-password',
        ])->assertJsonValidationErrors(['email', 'password']);
    }

    #[Test]
    public function a_registered_user_can_sign_in(): void
    {
        User::factory()->create([
            'email' => 'louis@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'louis@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'louis@example.com')
            ->assertJsonStructure(['token']);

        // 確認這個 token 真的可以使用。
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'louis@example.com');
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_email_fail_the_same_way(): void
    {
        User::factory()->create([
            'email' => 'louis@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'louis@example.com',
            'password' => 'not-the-password',
        ]);

        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ]);

        // 回應完全相同，因此這個端點無法被用來探測哪些 email 有帳號。
        $wrongPassword->assertJsonValidationErrors('email');
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    #[Test]
    public function signing_out_revokes_only_the_token_that_was_used(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone')->plainTextToken;
        $laptop = $user->createToken('laptop')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->postJson('/api/auth/logout')
            ->assertOk();

        // 測試中的請求之間不會重新啟動應用程式，因此 guard 仍握著剛才解析出來的
        // 使用者。清掉它可以讓下一次呼叫從頭進行驗證，就像真實世界的第二個請求
        // 一樣。
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$laptop)
            ->getJson('/api/me')
            ->assertOk();
    }

    #[Test]
    public function signing_out_requires_a_token(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }
}
