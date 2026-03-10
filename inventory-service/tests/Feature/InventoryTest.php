<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = '1';
    private string $token;
    private string $productId = '10';

    protected function setUp(): void
    {
        parent::setUp();

        $payload = [
            'sub'         => '1',
            'tenant_id'   => $this->tenantId,
            'tenant_code' => 'testcorp',
            'role'        => 'admin',
            'email'       => 'admin@test.com',
            'name'        => 'Admin',
            'iat'         => time(),
            'exp'         => time() + 3600,
        ];

        $this->token = JWT::encode($payload, config('jwt.secret'), 'HS256');
    }

    private function createInventory(array $overrides = []): Inventory
    {
        return Inventory::create(array_merge([
            'tenant_id'  => $this->tenantId,
            'product_id' => $this->productId,
            'warehouse'  => 'main',
            'quantity'   => 100,
            'reserved'   => 0,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
        ], $overrides));
    }

    public function test_can_create_inventory(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/inventory', [
            'product_id' => $this->productId,
            'quantity'   => 100,
            'warehouse'  => 'main',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('inventory.product_id', $this->productId)
            ->assertJsonPath('inventory.quantity', 100);
    }

    public function test_can_list_inventory(): void
    {
        $this->createInventory();

        $response = $this->withToken($this->token)->getJson('/api/inventory');

        $response->assertOk()->assertJsonStructure(['data', 'total']);
    }

    public function test_can_reserve_stock(): void
    {
        $inventory = $this->createInventory(['quantity' => 100, 'reserved' => 0]);

        $response = $this->withToken($this->token)->postJson("/api/inventory/{$inventory->id}/reserve", [
            'quantity'  => 10,
            'reference' => 'ORDER-001',
            'saga_id'   => 'saga-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('reserved', 10);

        $this->assertEquals(10, $inventory->fresh()->reserved);
    }

    public function test_reserve_fails_when_insufficient_stock(): void
    {
        $inventory = $this->createInventory(['quantity' => 5, 'reserved' => 0]);

        $response = $this->withToken($this->token)->postJson("/api/inventory/{$inventory->id}/reserve", [
            'quantity'  => 10,
            'reference' => 'ORDER-002',
        ]);

        $response->assertStatus(409)->assertJson(['error' => 'Insufficient stock']);
    }

    public function test_can_release_stock_saga_compensation(): void
    {
        $inventory = $this->createInventory(['quantity' => 100, 'reserved' => 10]);

        $response = $this->withToken($this->token)->postJson("/api/inventory/{$inventory->id}/release", [
            'quantity'  => 10,
            'reference' => 'ORDER-001',
            'saga_id'   => 'saga-123',
            'reason'    => 'Order cancelled - saga rollback',
        ]);

        $response->assertOk()
            ->assertJsonPath('released', 10)
            ->assertJsonPath('compensation', true);

        $this->assertEquals(0, $inventory->fresh()->reserved);
    }

    public function test_can_adjust_stock(): void
    {
        $inventory = $this->createInventory(['quantity' => 50]);

        $response = $this->withToken($this->token)->postJson("/api/inventory/{$inventory->id}/adjust", [
            'adjustment' => 25,
            'type'       => 'purchase',
            'reference'  => 'PO-001',
        ]);

        $response->assertOk()->assertJsonPath('new_quantity', 75);
    }

    public function test_adjustment_prevents_negative_stock(): void
    {
        $inventory = $this->createInventory(['quantity' => 10]);

        $response = $this->withToken($this->token)->postJson("/api/inventory/{$inventory->id}/adjust", [
            'adjustment' => -20,
            'type'       => 'adjustment',
            'reference'  => 'ADJ-001',
        ]);

        $response->assertStatus(409);
    }

    public function test_low_stock_detection(): void
    {
        $this->createInventory(['quantity' => 5, 'reorder_level' => 10]);
        $this->createInventory(['product_id' => '11', 'quantity' => 100, 'reorder_level' => 10]);

        $response = $this->withToken($this->token)->getJson('/api/inventory/low-stock');

        $response->assertOk()->assertJsonPath('count', 1);
    }

    public function test_inventory_movement_recorded_on_reservation(): void
    {
        $inventory = $this->createInventory(['quantity' => 100]);

        $this->withToken($this->token)->postJson("/api/inventory/{$inventory->id}/reserve", [
            'quantity'  => 5,
            'reference' => 'ORDER-TEST',
        ]);

        $movement = InventoryMovement::where('inventory_id', $inventory->id)->latest()->first();
        $this->assertNotNull($movement);
        $this->assertEquals('reservation', $movement->type);
        $this->assertEquals(-5, $movement->quantity);
    }

    public function test_tenant_isolation(): void
    {
        Inventory::create([
            'tenant_id'  => '999',
            'product_id' => $this->productId,
            'warehouse'  => 'main',
            'quantity'   => 100,
            'reserved'   => 0,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/inventory');
        $this->assertEquals(0, $response->json('total'));
    }
}
