<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_sample_data(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'sample_data' => true,
        ]);

        $response->assertCreated()->assertJsonStructure(['user' => ['id', 'email'], 'token']);
        $user = User::firstWhere('email', 'ada@example.com');
        $this->assertSame(4, $user->channels()->count());
        $this->assertGreaterThan(0, $user->orders()->count());
    }

    public function test_user_can_register_an_empty_workspace(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Empty', 'email' => 'empty@example.com',
            'password' => 'secret-password', 'password_confirmation' => 'secret-password', 'sample_data' => false,
        ])->assertCreated();

        $user = User::firstWhere('email', 'empty@example.com');
        $this->assertSame(0, $user->orders()->count());
        $this->assertSame(8, $user->ledgerAccounts()->count());
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', ['name' => '', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_user_can_login_and_logout(): void
    {
        User::factory()->create(['email' => 'grace@example.com', 'password' => 'secret-password']);

        $this->postJson('/api/auth/login', ['email' => 'grace@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $token = $this->postJson('/api/auth/login', ['email' => 'grace@example.com', 'password' => 'secret-password'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'grace@example.com');
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/orders')->assertUnauthorized();
    }

    public function test_user_can_reset_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) {
            $this->postJson('/api/auth/reset-password', [
                'token' => $notification->token,
                'email' => 'reset@example.com',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])->assertOk();

            return true;
        });

        $this->postJson('/api/auth/login', ['email' => 'reset@example.com', 'password' => 'brand-new-password'])->assertOk();
    }

    public function test_new_users_start_with_the_tour_pending_and_can_complete_it(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Newcomer', 'email' => 'new@example.com',
            'password' => 'secret-password', 'password_confirmation' => 'secret-password', 'sample_data' => false,
        ])->assertJsonPath('user.tour_completed_at', null)->json('token');

        $this->withToken($token)->postJson('/api/auth/tour')->assertOk();

        $this->assertNotNull(User::firstWhere('email', 'new@example.com')->tour_completed_at);
        $this->withToken($token)->getJson('/api/auth/me')->assertJsonPath('tour_completed_at', fn ($value) => $value !== null);
    }

    public function test_user_can_update_profile_and_password(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/auth/profile', ['name' => 'New Name', 'email' => $user->email, 'company' => 'Acme'])
            ->assertOk()->assertJsonPath('company', 'Acme');

        $this->putJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'another-password', 'password_confirmation' => 'another-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }
}
