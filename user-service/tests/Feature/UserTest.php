<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = '1';
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate a valid JWT for testing
        $payload = [
            'sub'         => '1',
            'tenant_id'   => $this->tenantId,
            'tenant_code' => 'testcorp',
            'role'        => 'admin',
            'email'       => 'admin@test.com',
            'name'        => 'Admin User',
            'iat'         => time(),
            'exp'         => time() + 3600,
        ];

        $this->token = JWT::encode($payload, config('jwt.secret'), 'HS256');
    }

    public function test_can_list_users(): void
    {
        User::create([
            'tenant_id' => $this->tenantId, 'external_id' => 'ext-1',
            'name' => 'Test User', 'email' => 'test@test.com',
            'role' => 'staff', 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/users');

        $response->assertOk()->assertJsonStructure(['data', 'total', 'per_page']);
    }

    public function test_can_create_user(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/users', [
            'name'        => 'New User',
            'email'       => 'newuser@example.com',
            'external_id' => 'ext-new-1',
            'role'        => 'staff',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email', 'tenant_id']]);
    }

    public function test_can_get_user_by_id(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenantId, 'external_id' => 'ext-2',
            'name' => 'Find Me', 'email' => 'findme@test.com',
            'role' => 'staff', 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->getJson("/api/users/{$user->id}");

        $response->assertOk()->assertJsonPath('user.name', 'Find Me');
    }

    public function test_can_update_user(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenantId, 'external_id' => 'ext-3',
            'name' => 'Old Name', 'email' => 'old@test.com',
            'role' => 'staff', 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->putJson("/api/users/{$user->id}", ['name' => 'New Name']);

        $response->assertOk()->assertJsonPath('user.name', 'New Name');
    }

    public function test_can_delete_user(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenantId, 'external_id' => 'ext-4',
            'name' => 'Delete Me', 'email' => 'delete@test.com',
            'role' => 'staff', 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->deleteJson("/api/users/{$user->id}");

        $response->assertOk()->assertJson(['message' => 'User deleted']);
    }

    public function test_tenant_isolation_enforced(): void
    {
        // Create user in different tenant
        User::create([
            'tenant_id' => '999', 'external_id' => 'ext-5',
            'name' => 'Other Tenant User', 'email' => 'other@test.com',
            'role' => 'staff', 'is_active' => true,
        ]);

        // Current tenant (1) should not see tenant 999's user
        $response = $this->withToken($this->token)->getJson('/api/users');
        $data = $response->json('data');
        $this->assertCount(0, $data);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/users');
        $response->assertStatus(401);
    }
}
