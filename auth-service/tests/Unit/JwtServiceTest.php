<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Tenant;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    use RefreshDatabase;

    private JwtService $jwtService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwtService = new JwtService();
    }

    public function test_generates_valid_jwt_token(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant', 'code' => 'test',
            'plan' => 'basic', 'is_active' => true, 'settings' => [],
        ]);

        $user = User::create([
            'name' => 'Test User', 'email' => 'test@test.com',
            'password' => bcrypt('password'), 'tenant_id' => $tenant->id,
            'role' => 'staff', 'is_active' => true,
        ]);

        $token = $this->jwtService->generateToken($user);

        $this->assertNotEmpty($token);
        $this->assertIsString($token);
        $this->assertCount(3, explode('.', $token)); // JWT has 3 parts
    }

    public function test_validates_valid_token(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant', 'code' => 'test2',
            'plan' => 'basic', 'is_active' => true, 'settings' => [],
        ]);

        $user = User::create([
            'name' => 'Test User', 'email' => 'test2@test.com',
            'password' => bcrypt('password'), 'tenant_id' => $tenant->id,
            'role' => 'staff', 'is_active' => true,
        ]);

        $token = $this->jwtService->generateToken($user);
        $payload = $this->jwtService->validateToken($token);

        $this->assertNotNull($payload);
        $this->assertEquals((string) $user->id, $payload['sub']);
        $this->assertEquals((string) $tenant->id, $payload['tenant_id']);
        $this->assertEquals('staff', $payload['role']);
    }

    public function test_rejects_invalid_token(): void
    {
        $payload = $this->jwtService->validateToken('invalid.token.here');
        $this->assertNull($payload);
    }

    public function test_blacklists_token(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant', 'code' => 'test3',
            'plan' => 'basic', 'is_active' => true, 'settings' => [],
        ]);

        $user = User::create([
            'name' => 'Test User', 'email' => 'test3@test.com',
            'password' => bcrypt('password'), 'tenant_id' => $tenant->id,
            'role' => 'staff', 'is_active' => true,
        ]);

        $token = $this->jwtService->generateToken($user);
        $this->jwtService->blacklistToken($token);

        $payload = $this->jwtService->validateToken($token);
        $this->assertNull($payload);
    }
}
