<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Services\ProductServiceClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller as BaseController;

class InventoryController extends BaseController
{
    public function __construct(private ProductServiceClient $productClient) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $inventory = Inventory::where('tenant_id', $tenantId)
            ->when($request->product_id, fn($q, $p) => $q->where('product_id', $p))
            ->when($request->warehouse, fn($q, $w) => $q->where('warehouse', $w))
            ->paginate($request->input('per_page', 15));

        return response()->json($inventory);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $validator = Validator::make($request->all(), [
            'product_id'      => 'required|string',
            'warehouse'       => 'sometimes|string|max:100',
            'quantity'        => 'required|integer|min:0',
            'reserved'        => 'sometimes|integer|min:0',
            'reorder_level'   => 'sometimes|integer|min:0',
            'reorder_quantity'=> 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if inventory already exists for this product+warehouse combo
        $existing = Inventory::where('tenant_id', $tenantId)
            ->where('product_id', $request->product_id)
            ->where('warehouse', $request->input('warehouse', 'main'))
            ->first();

        if ($existing) {
            return response()->json(['error' => 'Inventory record already exists for this product/warehouse'], 409);
        }

        $inventory = Inventory::create([
            'tenant_id'       => $tenantId,
            'product_id'      => $request->product_id,
            'warehouse'       => $request->input('warehouse', 'main'),
            'quantity'        => $request->quantity,
            'reserved'        => $request->input('reserved', 0),
            'reorder_level'   => $request->input('reorder_level', 10),
            'reorder_quantity'=> $request->input('reorder_quantity', 50),
        ]);

        InventoryMovement::create([
            'tenant_id'    => $tenantId,
            'inventory_id' => $inventory->id,
            'product_id'   => $request->product_id,
            'type'         => 'initial',
            'quantity'     => $request->quantity,
            'reference'    => 'Initial stock',
            'notes'        => 'Initial inventory creation',
        ]);

        return response()->json([
            'message'   => 'Inventory created',
            'inventory' => $inventory,
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $inventory = Inventory::where('tenant_id', $tenantId)->findOrFail($id);
        return response()->json(['inventory' => $inventory]);
    }

    public function byProduct(Request $request, string $productId): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $inventory = Inventory::where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->get();

        return response()->json([
            'product_id' => $productId,
            'inventory'  => $inventory,
            'total_available' => $inventory->sum(fn($i) => max(0, $i->quantity - $i->reserved)),
        ]);
    }

    /**
     * Cross-service filtering: filter inventory by product attributes
     * (name, code, category) by calling the product service
     */
    public function filterByProductAttributes(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $token = $request->bearerToken();

        // Build product search params
        $searchParams = array_filter([
            'name'        => $request->input('product_name'),
            'code'        => $request->input('product_code'),
            'category_id' => $request->input('category_id'),
            'q'           => $request->input('q'),
        ]);

        if (empty($searchParams)) {
            return response()->json(['error' => 'At least one product filter is required'], 422);
        }

        // Call Product Service to get matching product IDs
        $productResult = $this->productClient->searchProducts($token, $tenantId, $searchParams);

        if (!$productResult['success']) {
            return response()->json([
                'error'   => 'Failed to fetch products from product service',
                'details' => $productResult['error'] ?? 'Unknown error',
            ], 502);
        }

        $productIds = collect($productResult['products'])->pluck('id')->toArray();

        if (empty($productIds)) {
            return response()->json([
                'inventory' => [],
                'total'     => 0,
                'message'   => 'No products matched the search criteria',
                'filters_applied' => $searchParams,
            ]);
        }

        // Get inventory for matching products
        $inventory = Inventory::where('tenant_id', $tenantId)
            ->whereIn('product_id', $productIds)
            ->paginate($request->input('per_page', 15));

        // Enrich with product info
        $productMap = collect($productResult['products'])->keyBy('id');
        $inventory->getCollection()->transform(function ($item) use ($productMap) {
            $item->product = $productMap->get($item->product_id);
            return $item;
        });

        return response()->json([
            'inventory'       => $inventory,
            'products_found'  => count($productIds),
            'filters_applied' => $searchParams,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $inventory = Inventory::where('tenant_id', $tenantId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'reorder_level'    => 'sometimes|integer|min:0',
            'reorder_quantity' => 'sometimes|integer|min:0',
            'warehouse'        => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $inventory->update($request->only(['reorder_level', 'reorder_quantity', 'warehouse']));

        return response()->json([
            'message'   => 'Inventory updated',
            'inventory' => $inventory->fresh(),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $inventory = Inventory::where('tenant_id', $tenantId)->findOrFail($id);
        $inventory->delete();

        return response()->json(['message' => 'Inventory deleted']);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $lowStock = Inventory::where('tenant_id', $tenantId)
            ->whereRaw('(quantity - reserved) <= reorder_level')
            ->get();

        return response()->json([
            'low_stock_items' => $lowStock,
            'count'           => $lowStock->count(),
        ]);
    }

    /**
     * Reserve stock for an order (Saga step 1)
     * Called by Order Service during saga execution
     */
    public function reserve(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $validator = Validator::make($request->all(), [
            'quantity'    => 'required|integer|min:1',
            'reference'   => 'required|string|max:255',
            'saga_id'     => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($request, $id, $tenantId) {
            $inventory = Inventory::where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($id);

            $available = $inventory->quantity - $inventory->reserved;

            if ($available < $request->quantity) {
                return response()->json([
                    'error'     => 'Insufficient stock',
                    'available' => $available,
                    'requested' => $request->quantity,
                ], 409);
            }

            $inventory->increment('reserved', $request->quantity);
            $inventory->refresh();

            InventoryMovement::create([
                'tenant_id'    => $tenantId,
                'inventory_id' => $inventory->id,
                'product_id'   => $inventory->product_id,
                'type'         => 'reservation',
                'quantity'     => -$request->quantity,
                'reference'    => $request->reference,
                'saga_id'      => $request->input('saga_id'),
                'notes'        => "Reserved {$request->quantity} units for order {$request->reference}",
            ]);

            return response()->json([
                'message'   => 'Stock reserved successfully',
                'inventory' => $inventory->fresh(),
                'reserved'  => $request->quantity,
            ]);
        });
    }

    /**
     * Release reserved stock (Saga compensating transaction)
     * Called when order is cancelled or fails
     */
    public function release(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $validator = Validator::make($request->all(), [
            'quantity'  => 'required|integer|min:1',
            'reference' => 'required|string|max:255',
            'saga_id'   => 'sometimes|string|max:255',
            'reason'    => 'sometimes|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($request, $id, $tenantId) {
            $inventory = Inventory::where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($id);

            // Ensure we don't release more than reserved
            $releaseQty = min($request->quantity, $inventory->reserved);

            if ($releaseQty <= 0) {
                return response()->json([
                    'error'    => 'No reserved stock to release',
                    'reserved' => $inventory->reserved,
                ], 409);
            }

            $inventory->decrement('reserved', $releaseQty);
            $inventory->refresh();

            InventoryMovement::create([
                'tenant_id'    => $tenantId,
                'inventory_id' => $inventory->id,
                'product_id'   => $inventory->product_id,
                'type'         => 'release',
                'quantity'     => $releaseQty,
                'reference'    => $request->reference,
                'saga_id'      => $request->input('saga_id'),
                'notes'        => $request->input('reason', "Released reservation for {$request->reference}"),
            ]);

            return response()->json([
                'message'        => 'Stock released successfully',
                'inventory'      => $inventory->fresh(),
                'released'       => $releaseQty,
                'compensation'   => true,
            ]);
        });
    }

    /**
     * Adjust stock (deduct or add)
     */
    public function adjust(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $validator = Validator::make($request->all(), [
            'adjustment' => 'required|integer|not_in:0',
            'type'       => 'required|string|in:purchase,sale,adjustment,damage,return',
            'reference'  => 'required|string|max:255',
            'notes'      => 'sometimes|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($request, $id, $tenantId) {
            $inventory = Inventory::where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($id);

            $newQty = $inventory->quantity + $request->adjustment;

            if ($newQty < 0) {
                return response()->json([
                    'error'    => 'Adjustment would result in negative stock',
                    'current'  => $inventory->quantity,
                    'adjustment' => $request->adjustment,
                ], 409);
            }

            $inventory->update(['quantity' => $newQty]);

            // If it's a sale, also reduce reserved
            if ($request->type === 'sale' && $request->adjustment < 0) {
                $releaseQty = min(abs($request->adjustment), $inventory->reserved);
                if ($releaseQty > 0) {
                    $inventory->decrement('reserved', $releaseQty);
                }
            }

            InventoryMovement::create([
                'tenant_id'    => $tenantId,
                'inventory_id' => $inventory->id,
                'product_id'   => $inventory->product_id,
                'type'         => $request->type,
                'quantity'     => $request->adjustment,
                'reference'    => $request->reference,
                'notes'        => $request->notes,
            ]);

            return response()->json([
                'message'       => 'Stock adjusted',
                'inventory'     => $inventory->fresh(),
                'adjustment'    => $request->adjustment,
                'new_quantity'  => $inventory->fresh()->quantity,
            ]);
        });
    }
}
