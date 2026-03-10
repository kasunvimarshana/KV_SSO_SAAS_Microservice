<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Corp', 'code' => 'testcorp',
            'plan' => 'basic', 'is_active' => true, 'settings' => [],
        ]);
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'John Doe',
            'email'                 => 'john@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'tenant_code'           => 'testcorp',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message', 'token', 'token_type',
                'user' => ['id', 'name', 'email', 'role', 'tenant_id'],
            ]);
    }

    public function test_user_can_login(): void
    {
        User::create([
            'name' => 'Jane Doe', 'email' => 'jane@example.com',
            'password' => bcrypt('password123'), 'tenant_id' => $this->tenant->id,
            'role' => 'staff', 'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'jane@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_in', 'user']);
    }

    public function test_login_fails_with_wrong_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'wrong@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)->assertJson(['error' => 'Invalid credentials']);
    }

    public function test_validates_token_endpoint(): void
    {
        $user = User::create([
            'name' => 'Test User', 'email' => 'test@example.com',
            'password' => bcrypt('password'), 'tenant_id' => $this->tenant->id,
            'role' => 'staff', 'is_active' => true,
        ]);

        $jwtService = app(\App\Services\JwtService::class);
        $token = $jwtService->generateToken($user);

        $response = $this->withToken($token)->postJson('/api/auth/validate-token');

        $response->assertOk()
            ->assertJson(['valid' => true])
            ->assertJsonStructure(['payload' => ['sub', 'tenant_id', 'role']]);
    }

    public function test_get_me_requires_auth(): void
    {
        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(401);
    }

    public function test_get_me_returns_user(): void
    {
        $user = User::create([
            'name' => 'Me User', 'email' => 'me@example.com',
            'password' => bcrypt('password'), 'tenant_id' => $this->tenant->id,
            'role' => 'admin', 'is_active' => true,
        ]);

        $jwtService = app(\App\Services\JwtService::class);
        $token = $jwtService->generateToken($user);

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.email', 'me@example.com')
            ->assertJsonPath('user.role', 'admin');
    }
}
