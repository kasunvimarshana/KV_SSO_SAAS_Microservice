<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SagaLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Saga Orchestration Pattern for Distributed Transactions
 *
 * The Saga pattern manages distributed transactions across microservices
 * by breaking the transaction into a series of local transactions.
 * Each local transaction publishes an event or triggers the next step.
 * If any step fails, compensating transactions are executed to undo
 * the effects of preceding steps (rollback).
 *
 * Order Creation Saga:
 * Step 1: Create Order (local - Order DB)
 * Step 2: Validate Products (Product Service)
 * Step 3: Reserve Inventory (Inventory Service) ← most critical
 * Step 4: Confirm Order (local - Order DB)
 *
 * Compensation (if Step 3 or 4 fails):
 * Compensate Step 3: Release inventory reservations
 * Compensate Step 1: Mark order as FAILED
 */
class SagaOrchestrator
{
    private array $completedSteps = [];
    private array $compensations = [];

    public function __construct(
        private ProductServiceClient $productClient,
        private InventoryServiceClient $inventoryClient
    ) {}

    public function createOrderSaga(
        string $tenantId,
        string $userId,
        string $token,
        array $items,
        ?string $shippingAddress,
        ?string $notes
    ): array {
        $sagaId = Uuid::uuid4()->toString();
        $this->completedSteps = [];
        $this->compensations = [];

        $sagaLog = $this->initSagaLog($sagaId, $tenantId, null);

        try {
            // === STEP 1: Create order in PENDING state (local transaction) ===
            $order = $this->step1_createPendingOrder(
                $tenantId, $userId, $sagaId, $items, $shippingAddress, $notes
            );
            $sagaLog->update(['order_id' => $order->id]);
            $this->recordStep($sagaLog, '1_CREATE_ORDER', 'SUCCESS', ['order_id' => $order->id]);

            // === STEP 2: Validate products exist in Product Service ===
            $productData = $this->step2_validateProducts($token, $tenantId, $items);
            $this->recordStep($sagaLog, '2_VALIDATE_PRODUCTS', 'SUCCESS', ['product_count' => count($productData)]);

            // === STEP 3: Reserve inventory in Inventory Service ===
            $reservations = $this->step3_reserveInventory($token, $tenantId, $sagaId, $order, $items, $productData);
            $this->recordStep($sagaLog, '3_RESERVE_INVENTORY', 'SUCCESS', ['reservations' => count($reservations)]);

            // === STEP 4: Confirm the order ===
            $order = $this->step4_confirmOrder($order, $productData);
            $this->recordStep($sagaLog, '4_CONFIRM_ORDER', 'SUCCESS', ['order_id' => $order->id]);

            $sagaLog->update([
                'status'       => 'COMPLETED',
                'completed_at' => now(),
            ]);

            return [
                'success' => true,
                'order'   => $order->load('items'),
                'saga_id' => $sagaId,
            ];

        } catch (\Throwable $e) {
            Log::error("Saga {$sagaId} failed", [
                'error'   => $e->getMessage(),
                'step'    => end($this->completedSteps)['step'] ?? 'unknown',
                'saga_id' => $sagaId,
            ]);

            // Execute compensating transactions
            $this->compensate($token, $tenantId, $sagaId);

            $sagaLog->update([
                'status'        => 'COMPENSATED',
                'error'         => $e->getMessage(),
                'compensations' => $this->compensations,
                'completed_at'  => now(),
            ]);

            return [
                'success'      => false,
                'error'        => $e->getMessage(),
                'saga_id'      => $sagaId,
                'compensations'=> $this->compensations,
                'failed_step'  => end($this->completedSteps)['step'] ?? 'unknown',
            ];
        }
    }

    /**
     * Cancel order saga - triggers compensating transactions
     */
    public function cancelOrderSaga(Order $order, string $token, string $reason): array
    {
        $sagaId = $order->saga_id ?? Uuid::uuid4()->toString();
        $compensations = [];

        DB::transaction(function () use ($order, $token, $reason, $sagaId, &$compensations) {
            // Compensating transaction: Release all inventory reservations
            foreach ($order->items as $item) {
                if ($item->inventory_id) {
                    $result = $this->inventoryClient->releaseStock(
                        token: $token,
                        tenantId: $order->tenant_id,
                        inventoryId: $item->inventory_id,
                        quantity: $item->quantity,
                        reference: "ORDER-{$order->id}",
                        sagaId: $sagaId,
                        reason: "Order cancelled: {$reason}",
                    );

                    $compensations[] = [
                        'action'       => 'RELEASE_INVENTORY',
                        'inventory_id' => $item->inventory_id,
                        'product_id'   => $item->product_id,
                        'quantity'     => $item->quantity,
                        'success'      => $result['success'],
                    ];
                }
            }

            // Also deduct actual quantity if order was already completed with stock adjustment
            $order->update([
                'status'              => 'cancelled',
                'cancelled_at'        => now(),
                'cancellation_reason' => $reason,
            ]);
        });

        return [
            'order'         => $order->fresh()->load('items'),
            'compensations' => $compensations,
        ];
    }

    /**
     * Complete order saga - finalize inventory (deduct from reserved)
     */
    public function completeOrderSaga(Order $order, string $token): array
    {
        DB::transaction(function () use ($order, $token) {
            foreach ($order->items as $item) {
                if ($item->inventory_id) {
                    // Deduct actual stock (sale adjustment)
                    $this->inventoryClient->adjustStock(
                        token: $token,
                        tenantId: $order->tenant_id,
                        inventoryId: $item->inventory_id,
                        adjustment: -$item->quantity,
                        type: 'sale',
                        reference: "ORDER-{$order->id}",
                    );
                }
            }

            $order->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);
        });

        return ['order' => $order->fresh()->load('items')];
    }

    // ======== SAGA STEPS ========

    private function step1_createPendingOrder(
        string $tenantId, string $userId, string $sagaId,
        array $items, ?string $shippingAddress, ?string $notes
    ): Order {
        return DB::transaction(function () use ($tenantId, $userId, $sagaId, $items, $shippingAddress, $notes) {
            $totalAmount = collect($items)->sum(fn($i) => $i['price'] * $i['quantity']);

            $order = Order::create([
                'tenant_id'        => $tenantId,
                'user_id'          => $userId,
                'status'           => 'pending',
                'total_amount'     => $totalAmount,
                'shipping_address' => $shippingAddress,
                'notes'            => $notes,
                'saga_id'          => $sagaId,
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'tenant_id'   => $tenantId,
                    'order_id'    => $order->id,
                    'product_id'  => $item['product_id'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['price'],
                    'total_price' => $item['price'] * $item['quantity'],
                ]);
            }

            $this->completedSteps[] = ['step' => 'STEP_1_CREATE_ORDER', 'order_id' => $order->id];
            return $order->load('items');
        });
    }

    private function step2_validateProducts(string $token, string $tenantId, array $items): array
    {
        $productIds = array_column($items, 'product_id');
        $result = $this->productClient->getProductsByIds($token, $tenantId, $productIds);

        if (!$result['success']) {
            throw new \RuntimeException('Failed to validate products: ' . ($result['error'] ?? 'Service unavailable'));
        }

        if (empty($result['products'])) {
            throw new \RuntimeException('No valid products found for the order');
        }

        // Validate all requested products exist
        $foundIds = collect($result['products'])->pluck('id')->map(fn($id) => (string)$id)->toArray();
        foreach ($productIds as $pid) {
            if (!in_array((string)$pid, $foundIds)) {
                throw new \RuntimeException("Product {$pid} not found or not available");
            }
        }

        $this->completedSteps[] = ['step' => 'STEP_2_VALIDATE_PRODUCTS'];
        return $result['products'];
    }

    private function step3_reserveInventory(
        string $token, string $tenantId, string $sagaId,
        Order $order, array $items, array $productData
    ): array {
        $reservations = [];
        $productMap = collect($productData)->keyBy('id');

        foreach ($items as $item) {
            // Get inventory for this product
            $inventoryResult = $this->inventoryClient->getInventoryForProduct(
                $token, $tenantId, $item['product_id']
            );

            if (!$inventoryResult['success'] || empty($inventoryResult['inventory'])) {
                throw new \RuntimeException(
                    "No inventory found for product {$item['product_id']}"
                );
            }

            // Find inventory with enough stock (prefer 'main' warehouse)
            $inventory = collect($inventoryResult['inventory'])
                ->sortByDesc(fn($i) => $i['quantity'] - $i['reserved'])
                ->first();

            if (!$inventory) {
                throw new \RuntimeException("Insufficient inventory for product {$item['product_id']}");
            }

            $available = ($inventory['quantity'] ?? 0) - ($inventory['reserved'] ?? 0);
            if ($available < $item['quantity']) {
                throw new \RuntimeException(
                    "Insufficient stock for product {$item['product_id']}. " .
                    "Available: {$available}, Requested: {$item['quantity']}"
                );
            }

            // Reserve stock
            $reserveResult = $this->inventoryClient->reserveStock(
                token: $token,
                tenantId: $tenantId,
                inventoryId: $inventory['id'],
                quantity: $item['quantity'],
                reference: "ORDER-{$order->id}",
                sagaId: $sagaId,
            );

            if (!$reserveResult['success']) {
                throw new \RuntimeException(
                    "Failed to reserve stock for product {$item['product_id']}: " .
                    ($reserveResult['error'] ?? 'Unknown error')
                );
            }

            // Update order item with inventory and product info
            $product = $productMap->get($item['product_id']);
            OrderItem::where('order_id', $order->id)
                ->where('product_id', $item['product_id'])
                ->update([
                    'inventory_id' => $inventory['id'],
                    'product_name' => $product['name'] ?? null,
                    'product_code' => $product['code'] ?? null,
                ]);

            $reservations[] = [
                'product_id'   => $item['product_id'],
                'inventory_id' => $inventory['id'],
                'quantity'     => $item['quantity'],
            ];

            $this->completedSteps[] = [
                'step'         => 'STEP_3_RESERVE_INVENTORY',
                'inventory_id' => $inventory['id'],
                'product_id'   => $item['product_id'],
                'quantity'     => $item['quantity'],
            ];
        }

        return $reservations;
    }

    private function step4_confirmOrder(Order $order, array $productData): Order
    {
        $order->update([
            'status'       => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->completedSteps[] = ['step' => 'STEP_4_CONFIRM_ORDER'];
        return $order->fresh()->load('items');
    }

    // ======== COMPENSATION ========

    /**
     * Execute compensating transactions in reverse order (LIFO)
     */
    private function compensate(string $token, string $tenantId, string $sagaId): void
    {
        // Reverse the completed steps and compensate
        foreach (array_reverse($this->completedSteps) as $step) {
            try {
                match ($step['step']) {
                    'STEP_3_RESERVE_INVENTORY' => $this->compensate_releaseInventory(
                        $token, $tenantId, $sagaId,
                        $step['inventory_id'], $step['quantity'], $step['product_id']
                    ),
                    'STEP_1_CREATE_ORDER' => $this->compensate_failOrder($step['order_id']),
                    default => null,
                };
            } catch (\Throwable $e) {
                Log::error("Compensation failed for step {$step['step']}", [
                    'error'   => $e->getMessage(),
                    'saga_id' => $sagaId,
                ]);

                $this->compensations[] = [
                    'step'    => $step['step'],
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }
    }

    private function compensate_releaseInventory(
        string $token, string $tenantId, string $sagaId,
        string $inventoryId, int $quantity, string $productId
    ): void {
        $result = $this->inventoryClient->releaseStock(
            token: $token,
            tenantId: $tenantId,
            inventoryId: $inventoryId,
            quantity: $quantity,
            reference: "SAGA-COMPENSATION-{$sagaId}",
            sagaId: $sagaId,
            reason: 'Saga compensation: order creation failed',
        );

        $this->compensations[] = [
            'step'         => 'COMPENSATE_RELEASE_INVENTORY',
            'inventory_id' => $inventoryId,
            'product_id'   => $productId,
            'quantity'     => $quantity,
            'success'      => $result['success'],
        ];
    }

    private function compensate_failOrder(int $orderId): void
    {
        Order::where('id', $orderId)->update([
            'status'              => 'failed',
            'cancellation_reason' => 'Saga compensation: order creation failed',
        ]);

        $this->compensations[] = [
            'step'     => 'COMPENSATE_FAIL_ORDER',
            'order_id' => $orderId,
            'success'  => true,
        ];
    }

    private function initSagaLog(string $sagaId, string $tenantId, ?int $orderId): SagaLog
    {
        return SagaLog::create([
            'saga_id'    => $sagaId,
            'tenant_id'  => $tenantId,
            'order_id'   => $orderId,
            'status'     => 'IN_PROGRESS',
            'steps'      => [],
            'started_at' => now(),
        ]);
    }

    private function recordStep(SagaLog $saga, string $stepName, string $status, array $data = []): void
    {
        $steps = $saga->steps ?? [];
        $steps[] = [
            'step'      => $stepName,
            'status'    => $status,
            'data'      => $data,
            'timestamp' => now()->toIso8601String(),
        ];
        $saga->update(['steps' => $steps]);
    }
}
