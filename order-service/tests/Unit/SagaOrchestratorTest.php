<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\SagaLog;
use App\Services\InventoryServiceClient;
use App\Services\ProductServiceClient;
use App\Services\SagaOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SagaOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_saga_creates_confirmed_order(): void
    {
        $productClient = Mockery::mock(ProductServiceClient::class);
        $productClient->shouldReceive('getProductsByIds')->andReturn([
            'success'  => true,
            'products' => [['id' => '5', 'name' => 'Widget', 'code' => 'WID-001']],
        ]);

        $inventoryClient = Mockery::mock(InventoryServiceClient::class);
        $inventoryClient->shouldReceive('getInventoryForProduct')->andReturn([
            'success'   => true,
            'inventory' => [['id' => '3', 'product_id' => '5', 'quantity' => 50, 'reserved' => 0]],
        ]);
        $inventoryClient->shouldReceive('reserveStock')->andReturn(['success' => true, 'data' => []]);

        $orchestrator = new SagaOrchestrator($productClient, $inventoryClient);

        $result = $orchestrator->createOrderSaga(
            tenantId: '1',
            userId: '10',
            token: 'test-token',
            items: [['product_id' => '5', 'quantity' => 3, 'price' => 9.99]],
            shippingAddress: null,
            notes: null,
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['saga_id']);
        $this->assertEquals('confirmed', $result['order']->status);
    }

    public function test_saga_records_all_steps(): void
    {
        $productClient = Mockery::mock(ProductServiceClient::class);
        $productClient->shouldReceive('getProductsByIds')->andReturn([
            'success'  => true,
            'products' => [['id' => '5', 'name' => 'Widget', 'code' => 'WID-001']],
        ]);

        $inventoryClient = Mockery::mock(InventoryServiceClient::class);
        $inventoryClient->shouldReceive('getInventoryForProduct')->andReturn([
            'success'   => true,
            'inventory' => [['id' => '3', 'product_id' => '5', 'quantity' => 50, 'reserved' => 0]],
        ]);
        $inventoryClient->shouldReceive('reserveStock')->andReturn(['success' => true]);

        $orchestrator = new SagaOrchestrator($productClient, $inventoryClient);

        $result = $orchestrator->createOrderSaga(
            tenantId: '1', userId: '10', token: 'test',
            items: [['product_id' => '5', 'quantity' => 1, 'price' => 9.99]],
            shippingAddress: null, notes: null,
        );

        $sagaLog = SagaLog::where('saga_id', $result['saga_id'])->first();
        $this->assertNotNull($sagaLog);
        $this->assertEquals('COMPLETED', $sagaLog->status);
        $steps = $sagaLog->steps;
        $this->assertCount(4, $steps);
        $stepNames = array_column($steps, 'step');
        $this->assertContains('1_CREATE_ORDER', $stepNames);
        $this->assertContains('4_CONFIRM_ORDER', $stepNames);
    }

    public function test_saga_compensates_on_product_not_found(): void
    {
        $productClient = Mockery::mock(ProductServiceClient::class);
        $productClient->shouldReceive('getProductsByIds')->andReturn([
            'success'  => true,
            'products' => [], // No products found
        ]);

        $inventoryClient = Mockery::mock(InventoryServiceClient::class);

        $orchestrator = new SagaOrchestrator($productClient, $inventoryClient);

        $result = $orchestrator->createOrderSaga(
            tenantId: '1', userId: '10', token: 'test',
            items: [['product_id' => '99', 'quantity' => 1, 'price' => 9.99]],
            shippingAddress: null, notes: null,
        );

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['compensations']);

        // Order should be marked as failed
        $order = Order::where('saga_id', $result['saga_id'])->first();
        $this->assertEquals('failed', $order->status);
    }

    public function test_saga_compensates_on_reservation_failure(): void
    {
        $productClient = Mockery::mock(ProductServiceClient::class);
        $productClient->shouldReceive('getProductsByIds')->andReturn([
            'success'  => true,
            'products' => [['id' => '5', 'name' => 'Widget', 'code' => 'WID-001']],
        ]);

        $inventoryClient = Mockery::mock(InventoryServiceClient::class);
        $inventoryClient->shouldReceive('getInventoryForProduct')->andReturn([
            'success'   => true,
            'inventory' => [['id' => '3', 'product_id' => '5', 'quantity' => 2, 'reserved' => 0]],
        ]);
        // Reservation fails due to insufficient stock
        $inventoryClient->shouldReceive('reserveStock')->andReturn([
            'success' => false,
            'error'   => 'Insufficient stock',
        ]);

        $orchestrator = new SagaOrchestrator($productClient, $inventoryClient);

        $result = $orchestrator->createOrderSaga(
            tenantId: '1', userId: '10', token: 'test',
            items: [['product_id' => '5', 'quantity' => 100, 'price' => 9.99]],
            shippingAddress: null, notes: null,
        );

        $this->assertFalse($result['success']);
        $sagaLog = SagaLog::where('saga_id', $result['saga_id'])->first();
        $this->assertEquals('COMPENSATED', $sagaLog->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
