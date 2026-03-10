<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class InventoryMovementController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $movements = InventoryMovement::where('tenant_id', $tenantId)
            ->when($request->inventory_id, fn($q, $i) => $q->where('inventory_id', $i))
            ->when($request->product_id, fn($q, $p) => $q->where('product_id', $p))
            ->when($request->type, fn($q, $t) => $q->where('type', $t))
            ->when($request->saga_id, fn($q, $s) => $q->where('saga_id', $s))
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($movements);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $movement = InventoryMovement::where('tenant_id', $tenantId)->findOrFail($id);
        return response()->json(['movement' => $movement]);
    }
}
