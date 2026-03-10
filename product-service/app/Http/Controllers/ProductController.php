<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller as BaseController;

class ProductController extends BaseController
{
    /** Returns 'ilike' for PostgreSQL, 'like' for other drivers (e.g., SQLite in tests) */
    private function likeOp(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $like = $this->likeOp();

        $products = Product::where('tenant_id', $tenantId)
            ->with('category')
            ->when($request->category_id, fn($q, $c) => $q->where('category_id', $c))
            ->when($request->is_active !== null,
                fn($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->name, function ($q, $n) use ($like) {
                $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $n);
                $q->where('name', $like, "%{$safe}%");
            })
            ->when($request->code, function ($q, $c) use ($like) {
                $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $c);
                $q->where('code', $like, "%{$safe}%");
            })
            ->paginate($request->input('per_page', 15));

        return response()->json($products);
    }

    public function search(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $query    = $request->input('q', '');
        $like     = $this->likeOp();

        $products = Product::where('tenant_id', $tenantId)
            ->where(function ($q) use ($query, $like) {
                $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
                $q->where('name', $like, "%{$safe}%")
                  ->orWhere('code', $like, "%{$safe}%")
                  ->orWhere('description', $like, "%{$safe}%");
            })
            ->with('category')
            ->limit(50)
            ->get();

        return response()->json(['products' => $products, 'count' => $products->count()]);
    }

    public function getByIds(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $ids = explode(',', $request->input('ids', ''));
        $ids = array_filter(array_map('trim', $ids));

        if (empty($ids)) {
            return response()->json(['products' => []]);
        }

        $products = Product::where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->with('category')
            ->get();

        return response()->json(['products' => $products]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'code'        => 'required|string|max:100',
            'description' => 'sometimes|string',
            'category_id' => 'sometimes|exists:categories,id',
            'price'       => 'required|numeric|min:0',
            'cost'        => 'sometimes|numeric|min:0',
            'unit'        => 'sometimes|string|max:50',
            'attributes'  => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Ensure code is unique within tenant
        $exists = Product::where('tenant_id', $tenantId)->where('code', $request->code)->exists();
        if ($exists) {
            return response()->json(['error' => 'Product code already exists in this tenant'], 409);
        }

        $product = Product::create([
            'tenant_id'   => $tenantId,
            'name'        => $request->name,
            'code'        => strtoupper($request->code),
            'description' => $request->description,
            'category_id' => $request->category_id,
            'price'       => $request->price,
            'cost'        => $request->input('cost', 0),
            'unit'        => $request->input('unit', 'pcs'),
            'attributes'  => $request->input('attributes', []),
            'is_active'   => true,
        ]);

        return response()->json([
            'message' => 'Product created',
            'product' => $product->load('category'),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $product = Product::where('tenant_id', $tenantId)->with('category')->findOrFail($id);
        return response()->json(['product' => $product]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $product = Product::where('tenant_id', $tenantId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'category_id' => 'sometimes|nullable|exists:categories,id',
            'price'       => 'sometimes|numeric|min:0',
            'cost'        => 'sometimes|numeric|min:0',
            'unit'        => 'sometimes|string|max:50',
            'attributes'  => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product->update($request->only([
            'name', 'description', 'category_id', 'price', 'cost', 'unit', 'attributes',
        ]));

        return response()->json([
            'message' => 'Product updated',
            'product' => $product->fresh()->load('category'),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $product = Product::where('tenant_id', $tenantId)->findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Product deleted']);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $product = Product::where('tenant_id', $tenantId)->findOrFail($id);

        $product->update(['is_active' => !$product->is_active]);

        return response()->json([
            'message'   => 'Status toggled',
            'is_active' => $product->fresh()->is_active,
        ]);
    }
}
