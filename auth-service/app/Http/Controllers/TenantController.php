<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller as BaseController;

class TenantController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::query()
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%"))
            ->paginate($request->input('per_page', 15));

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'   => 'required|string|max:255',
            'code'   => 'required|string|max:50|unique:tenants|alpha_dash',
            'domain' => 'sometimes|string|max:255',
            'plan'   => 'sometimes|string|in:free,basic,professional,enterprise',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant = Tenant::create([
            'name'      => $request->name,
            'code'      => strtolower($request->code),
            'domain'    => $request->domain,
            'plan'      => $request->input('plan', 'basic'),
            'is_active' => true,
            'settings'  => [],
        ]);

        return response()->json([
            'message' => 'Tenant created successfully',
            'tenant'  => $tenant,
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::with('users')->findOrFail($id);
        return response()->json(['tenant' => $tenant]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'      => 'sometimes|string|max:255',
            'domain'    => 'sometimes|string|max:255',
            'plan'      => 'sometimes|string|in:free,basic,professional,enterprise',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant->update($request->only(['name', 'domain', 'plan', 'is_active']));

        return response()->json([
            'message' => 'Tenant updated successfully',
            'tenant'  => $tenant->fresh(),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->delete();

        return response()->json(['message' => 'Tenant deleted successfully']);
    }
}
