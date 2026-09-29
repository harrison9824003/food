<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email, string $password = 'password')
    {
        return $this->postJson('/api/admin/login', ['email' => $email, 'password' => $password]);
    }

    /** 同一個測試內連續用不同 token 打 API 時，要清掉 guard 快取的使用者。 */
    private function callWithToken(string $method, string $uri, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri);
    }

    public function test_login_returns_token_and_user(): void
    {
        $user = User::factory()->storeManager()->create(['email' => 'manager@example.com']);

        $response = $this->login('manager@example.com');

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'manager@example.com')
            ->assertJsonPath('user.role', 'store_manager')
            ->assertJsonPath('user.store.id', $user->store_id)
            ->assertJsonStructure(['token', 'expires_at']);
        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    public function test_issued_token_can_access_me(): void
    {
        User::factory()->hqAdmin()->create(['email' => 'hq@example.com']);
        $token = $this->login('hq@example.com')->json('token');

        $this->callWithToken('GET', '/api/admin/me', $token)
            ->assertOk()
            ->assertJsonPath('email', 'hq@example.com')
            ->assertJsonPath('role', 'hq_admin')
            ->assertJsonPath('store_id', null);
    }

    public function test_wrong_password_and_unknown_email_return_identical_errors(): void
    {
        User::factory()->create(['email' => 'real@example.com']);

        $wrongPassword = $this->login('real@example.com', 'bad-password');
        $unknownEmail = $this->login('nobody@example.com');

        $wrongPassword->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $unknownEmail->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->assertSame($wrongPassword->json('message'), $unknownEmail->json('message'));
    }

    public function test_inactive_account_cannot_login(): void
    {
        User::factory()->inactive()->create(['email' => 'off@example.com']);

        $this->login('off@example.com')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_inactive_status_is_not_disclosed_without_correct_password(): void
    {
        User::factory()->inactive()->create(['email' => 'off@example.com']);

        $this->login('off@example.com', 'bad-password')
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'target@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('target@example.com', 'bad-password')->assertStatus(401);
        }

        // 第 6 次即使密碼正確也要被擋
        $this->login('target@example.com')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_login_validates_input(): void
    {
        $this->postJson('/api/admin/login', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['email', 'password'], 'errors');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/admin/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        User::factory()->create(['email' => 'multi@example.com']);
        $tokenA = $this->login('multi@example.com')->json('token');
        $tokenB = $this->login('multi@example.com')->json('token');

        $this->callWithToken('POST', '/api/admin/logout', $tokenA)->assertOk();

        $this->callWithToken('GET', '/api/admin/me', $tokenA)->assertStatus(401);
        $this->callWithToken('GET', '/api/admin/me', $tokenB)->assertOk();
    }

    public function test_deactivated_user_with_existing_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'soon@example.com']);
        $token = $this->login('soon@example.com')->json('token');

        $user->update(['is_active' => false]);

        $this->callWithToken('GET', '/api/admin/me', $token)
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin', ['*'], now()->subMinute())->plainTextToken;

        $this->callWithToken('GET', '/api/admin/me', $token)
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
