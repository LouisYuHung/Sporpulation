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
            'name' => '周宇宏',
            'email' => 'louis@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'louis@example.com')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', ['email' => 'louis@example.com']);

        // Stored hashed, never in the clear.
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

        // The token actually works.
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

        // Identical responses, so the endpoint cannot be used to discover
        // which emails have accounts.
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

        // The application is not rebooted between requests in a test, so the
        // guard still holds the user it resolved a moment ago. Clearing it
        // makes the next call authenticate from scratch, the way a real
        // second request would.
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
