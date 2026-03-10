<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SagaOrchestrator;
use App\Services\ProductServiceClient;
use App\Services\InventoryServiceClient;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = '1';
    private string $userId = '100';
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $payload = [
            'sub'         => $this->userId,
            'tenant_id'   => $this->tenantId,
            'tenant_code' => 'testcorp',
            'role'        => 'staff',
            'email'       => 'staff@test.com',
            'name'        => 'Staff User',
            'iat'         => time(),
            'exp'         => time() + 3600,
        ];

        $this->token = JWT::encode($payload, config('jwt.secret'), 'HS256');
    }

    public function test_can_list_orders(): void
    {
        Order::create([
            'tenant_id'    => $this->tenantId,
            'user_id'      => $this->userId,
            'status'       => 'confirmed',
            'total_amount' => 99.99,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/orders');

        $response->assertOk()->assertJsonStructure(['data', 'total']);
    }

    public function test_can_get_order_by_id(): void
    {
        $order = Order::create([
            'tenant_id'    => $this->tenantId,
            'user_id'      => $this->userId,
            'status'       => 'confirmed',
            'total_amount' => 149.99,
        ]);

        $response = $this->withToken($this->token)->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.status', 'confirmed');
    }

    /**
     * Test successful order creation via Saga
     */
    public function test_creates_order_via_saga_on_success(): void
    {
        // Mock external service clients
        $productClient = Mockery::mock(ProductServiceClient::class);
        $productClient->shouldReceive('getProductsByIds')->andReturn([
            'success'  => true,
            'products' => [
                ['id' => '10', 'name' => 'Laptop', 'code' => 'LAP-001', 'price' => 999.99],
            ],
        ]);

        $inventoryClient = Mockery::mock(InventoryServiceClient::class);
        $inventoryClient->shouldReceive('getInventoryForProduct')->andReturn([
            'success'   => true,
            'inventory' => [
                ['id' => '1', 'product_id' => '10', 'quantity' => 100, 'reserved' => 0, 'warehouse' => 'main'],
            ],
        ]);
        $inventoryClient->shouldReceive('reserveStock')->andReturn(['success' => true, 'data' => []]);

        $this->bindMockClients($productClient, $inventoryClient);

        $response = $this->withToken($this->token)->postJson('/api/orders', [
            'items' => [
                ['product_id' => '10', 'quantity' => 2, 'price' => 999.99],
            ],
            'shipping_address' => '123 Test Street',
        ]);

        $response->assertStatus(201)
            ->assertJson(['saga_status' => 'COMPLETED'])
            ->assertJsonStructure(['order', 'saga_id']);

        $this->assertDatabaseHas('orders', [
            'tenant_id' => $this->tenantId,
            'status'    => 'confirmed',
        ]);
    }

    /**
     * Test saga compensation when inventory reservation fails
     */
    public function test_saga_compensates_when_inventory_fails(): void
    {
        $productClient = Mockery::mock(ProductServiceClient::class);
        $productClient->shouldReceive('getProductsByIds')->andReturn([
            'success'  => true,
            'products' => [
                ['id' => '10', 'name' => 'Laptop', 'code' => 'LAP-001'],
                ['id' => '20', 'name' => 'Mouse', 'code' => 'MOU-001'],
            ],
        ]);

        $inventoryClient = Mockery::mock(InventoryServiceClient::class);
        // First item reserves successfully
        $inventoryClient->shouldReceive('getInventoryForProduct')->with(
            Mockery::any(), Mockery::any(), '10'
        )->andReturn([
            'success'   => true,
            'inventory' => [['id' => '1', 'product_id' => '10', 'quantity' => 100, 'reserved' => 0]],
        ]);
        $inventoryClient->shouldReceive('reserveStock')->with(
            Mockery::any(), Mockery::any(), '1', 1, Mockery::any(), Mockery::any()
        )->andReturn(['success' => true, 'data' => []]);

        // Second item has insufficient stock
        $inventoryClient->shouldReceive('getInventoryForProduct')->with(
            Mockery::any(), Mockery::any(), '20'
        )->andReturn([
            'success'   => true,
            'inventory' => [['id' => '2', 'product_id' => '20', 'quantity' => 1, 'reserved' => 0]],
        ]);

        // Compensation: release first item's reservation
        $inventoryClient->shouldReceive('releaseStock')->andReturn(['success' => true, 'data' => []]);

        $this->bindMockClients($productClient, $inventoryClient);

        $response = $this->withToken($this->token)->postJson('/api/orders', [
            'items' => [
                ['product_id' => '10', 'quantity' => 1, 'price' => 999.99],
                ['product_id' => '20', 'quantity' => 5, 'price' => 29.99], // Will fail
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson(['saga_status' => 'COMPENSATED'])
            ->assertJsonStructure(['saga_id', 'compensations', 'failed_step']);

        // Order should be marked as FAILED
        $this->assertDatabaseHas('orders', [
            'tenant_id' => $this->tenantId,
            'status'    => 'failed',
        ]);
    }

    public function test_can_cancel_order(): void
    {
        $order = Order::create([
            'tenant_id'    => $this->tenantId,
            'user_id'      => $this->userId,
            'status'       => 'confirmed',
            'total_amount' => 50.00,
            'saga_id'      => 'test-saga-id',
        ]);

        $inventoryClient = Mockery::mock(InventoryServiceClient::class);
        $inventoryClient->shouldReceive('releaseStock')->andReturn(['success' => true]);

        $this->bindMockClients(null, $inventoryClient);

        $response = $this->withToken($this->token)->postJson("/api/orders/{$order->id}/cancel", [
            'reason' => 'Customer request',
        ]);

        $response->assertOk()
            ->assertJson(['saga_status' => 'COMPENSATED'])
            ->assertJsonPath('order.status', 'cancelled');
    }

    public function test_can_filter_orders_by_status(): void
    {
        Order::create(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'status' => 'confirmed', 'total_amount' => 10]);
        Order::create(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'status' => 'pending', 'total_amount' => 20]);

        $response = $this->withToken($this->token)->getJson('/api/orders?status=confirmed');

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('confirmed', $response->json('data.0.status'));
    }

    public function test_tenant_isolation(): void
    {
        // Create order in different tenant
        Order::create([
            'tenant_id'    => '999',
            'user_id'      => $this->userId,
            'status'       => 'confirmed',
            'total_amount' => 100.00,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/orders');
        $this->assertEquals(0, $response->json('total'));
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/orders');
        $response->assertStatus(401);
    }

    /**
     * Bind mock service clients into the container and rebuild SagaOrchestrator.
     */
    private function bindMockClients(
        ?ProductServiceClient $productClient = null,
        ?InventoryServiceClient $inventoryClient = null
    ): void {
        $productClient   = $productClient   ?? $this->app->make(ProductServiceClient::class);
        $inventoryClient = $inventoryClient ?? $this->app->make(InventoryServiceClient::class);

        $this->app->instance(ProductServiceClient::class, $productClient);
        $this->app->instance(InventoryServiceClient::class, $inventoryClient);
        $this->app->forgetInstance(SagaOrchestrator::class);
        $this->app->singleton(SagaOrchestrator::class, fn() => new SagaOrchestrator(
            $productClient,
            $inventoryClient
        ));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
