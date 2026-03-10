<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SagaOrchestrator;
use App\Services\ProductServiceClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller as BaseController;

class OrderController extends BaseController
{
    public function __construct(
        private SagaOrchestrator $sagaOrchestrator,
        private ProductServiceClient $productClient
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $orders = Order::where('tenant_id', $tenantId)
            ->with('items')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->user_id, fn($q, $u) => $q->where('user_id', $u))
            ->when($request->from_date, fn($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to_date, fn($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json($orders);
    }

    /**
     * Create an order using the Saga Orchestration pattern.
     *
     * Saga Steps:
     * 1. Create order in PENDING state
     * 2. Validate products exist (Product Service)
     * 3. Reserve inventory for each item (Inventory Service)
     * 4. Confirm order (mark as CONFIRMED)
     *
     * Compensating Transactions on failure:
     * - Release any reserved inventory
     * - Mark order as FAILED
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $userId   = $request->input('_user_id');
        $token    = $request->bearerToken();

        $validator = Validator::make($request->all(), [
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|string',
            'items.*.quantity'    => 'required|integer|min:1',
            'items.*.price'       => 'required|numeric|min:0',
            'shipping_address'    => 'sometimes|string|max:500',
            'notes'               => 'sometimes|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Execute the Saga
        $result = $this->sagaOrchestrator->createOrderSaga(
            tenantId: $tenantId,
            userId: $userId,
            token: $token,
            items: $request->items,
            shippingAddress: $request->input('shipping_address'),
            notes: $request->input('notes'),
        );

        if (!$result['success']) {
            return response()->json([
                'error'           => $result['error'],
                'saga_id'         => $result['saga_id'] ?? null,
                'saga_status'     => 'COMPENSATED',
                'compensations'   => $result['compensations'] ?? [],
                'failed_step'     => $result['failed_step'] ?? null,
            ], 422);
        }

        return response()->json([
            'message'     => 'Order created successfully',
            'order'       => $result['order'],
            'saga_id'     => $result['saga_id'],
            'saga_status' => 'COMPLETED',
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $order = Order::where('tenant_id', $tenantId)->with('items')->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $order = Order::where('tenant_id', $tenantId)->findOrFail($id);

        if (in_array($order->status, ['completed', 'cancelled'])) {
            return response()->json(['error' => 'Cannot update a completed or cancelled order'], 409);
        }

        $order->update($request->only(['shipping_address', 'notes']));

        return response()->json([
            'message' => 'Order updated',
            'order'   => $order->fresh()->load('items'),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $order = Order::where('tenant_id', $tenantId)->findOrFail($id);

        if (!in_array($order->status, ['cancelled', 'failed'])) {
            return response()->json(['error' => 'Only cancelled or failed orders can be deleted'], 409);
        }

        $order->delete();
        return response()->json(['message' => 'Order deleted']);
    }

    /**
     * Cancel an order and trigger compensating transactions (Saga rollback)
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $token    = $request->bearerToken();
        $order    = Order::where('tenant_id', $tenantId)->with('items')->findOrFail($id);

        if (in_array($order->status, ['completed', 'cancelled'])) {
            return response()->json(['error' => 'Order cannot be cancelled'], 409);
        }

        $result = $this->sagaOrchestrator->cancelOrderSaga(
            order: $order,
            token: $token,
            reason: $request->input('reason', 'Cancelled by user'),
        );

        return response()->json([
            'message'       => 'Order cancelled',
            'order'         => $result['order'],
            'compensations' => $result['compensations'],
            'saga_status'   => 'COMPENSATED',
        ]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $order    = Order::where('tenant_id', $tenantId)->with('items')->findOrFail($id);

        if ($order->status !== 'confirmed') {
            return response()->json(['error' => 'Only confirmed orders can be completed'], 409);
        }

        $result = $this->sagaOrchestrator->completeOrderSaga($order, $request->bearerToken());

        return response()->json([
            'message' => 'Order completed',
            'order'   => $result['order'],
        ]);
    }

    /**
     * Cross-service: Filter orders by product attributes (name, code, category)
     * Calls Product Service to find product IDs, then filters order items
     */
    public function filterByProduct(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $token    = $request->bearerToken();

        $searchParams = array_filter([
            'name'        => $request->input('product_name'),
            'code'        => $request->input('product_code'),
            'category_id' => $request->input('category_id'),
            'q'           => $request->input('q'),
        ]);

        if (empty($searchParams)) {
            return response()->json(['error' => 'At least one product filter is required'], 422);
        }

        // Step 1: Get matching product IDs from Product Service
        $productResult = $this->productClient->searchProducts($token, $tenantId, $searchParams);

        if (!$productResult['success']) {
            return response()->json([
                'error'   => 'Failed to fetch products',
                'details' => $productResult['error'] ?? 'Service unavailable',
            ], 502);
        }

        $productIds = collect($productResult['products'])->pluck('id')->toArray();

        if (empty($productIds)) {
            return response()->json([
                'orders'          => [],
                'total'           => 0,
                'products_found'  => 0,
                'filters_applied' => $searchParams,
                'message'         => 'No products matched the filter criteria',
            ]);
        }

        // Step 2: Find orders containing those products
        $orderIds = OrderItem::whereIn('product_id', $productIds)
            ->where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('order_id');

        $orders = Order::where('tenant_id', $tenantId)
            ->whereIn('id', $orderIds)
            ->with('items')
            ->paginate($request->input('per_page', 15));

        // Enrich order items with product info
        $productMap = collect($productResult['products'])->keyBy('id');
        $orders->getCollection()->each(function ($order) use ($productMap) {
            $order->items->each(function ($item) use ($productMap) {
                $item->product = $productMap->get($item->product_id);
            });
        });

        return response()->json([
            'orders'          => $orders,
            'products_found'  => count($productIds),
            'filters_applied' => $searchParams,
        ]);
    }
}
