<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller as BaseController;

class CategoryController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $categories = Category::where('tenant_id', $tenantId)
            ->when($request->search, function ($q, $s) {
                $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
                $q->where('name', 'like', "%{$safe}%");
            })
            ->withCount('products')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'description' => 'sometimes|string',
            'slug'        => 'sometimes|string|max:255|alpha_dash',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category = Category::create([
            'tenant_id'   => $tenantId,
            'name'        => $request->name,
            'description' => $request->description,
            'slug'        => $request->input('slug', \Illuminate\Support\Str::slug($request->name)),
            'is_active'   => true,
        ]);

        return response()->json([
            'message'  => 'Category created',
            'category' => $category,
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $category = Category::where('tenant_id', $tenantId)->withCount('products')->findOrFail($id);
        return response()->json(['category' => $category]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        $category->update($request->only(['name', 'description', 'slug', 'is_active']));

        return response()->json([
            'message'  => 'Category updated',
            'category' => $category->fresh(),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        if ($category->products()->count() > 0) {
            return response()->json(['error' => 'Cannot delete category with products'], 409);
        }

        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }
}
