<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller as BaseController;

class UserController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $users = User::where('tenant_id', $tenantId)
            ->when($request->search, function ($q, $s) {
                $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
                $q->where('name', 'like', "%{$safe}%")
                  ->orWhere('email', 'like', "%{$safe}%");
            })
            ->when($request->role, fn($q, $r) => $q->where('role', $r))
            ->when($request->is_active !== null, fn($q) =>
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->paginate($request->input('per_page', 15));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'email'       => 'required|string|email|max:255',
            'external_id' => 'required|string|max:255',
            'role'        => 'sometimes|string|in:admin,manager,staff,viewer',
            'phone'       => 'sometimes|string|max:50',
            'address'     => 'sometimes|string|max:500',
            'metadata'    => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check for duplicate in this tenant
        $exists = User::where('tenant_id', $tenantId)
            ->where('external_id', $request->external_id)
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'User already exists in this tenant'], 409);
        }

        $user = User::create([
            'tenant_id'   => $tenantId,
            'external_id' => $request->external_id,
            'name'        => $request->name,
            'email'       => $request->email,
            'role'        => $request->input('role', 'staff'),
            'phone'       => $request->phone,
            'address'     => $request->address,
            'metadata'    => $request->input('metadata', []),
            'is_active'   => true,
        ]);

        return response()->json([
            'message' => 'User profile created',
            'user'    => $user,
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $user = User::where('tenant_id', $tenantId)->findOrFail($id);
        return response()->json(['user' => $user]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'     => 'sometimes|string|max:255',
            'email'    => 'sometimes|string|email|max:255',
            'phone'    => 'sometimes|string|max:50',
            'address'  => 'sometimes|string|max:500',
            'metadata' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update($request->only(['name', 'email', 'phone', 'address', 'metadata']));

        return response()->json([
            'message' => 'User updated',
            'user'    => $user->fresh(),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $user = User::where('tenant_id', $tenantId)->findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }

    public function profile(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json([
            'user'    => $user,
            'summary' => [
                'total_orders'        => $user->total_orders,
                'total_spent'         => $user->total_spent,
                'last_order_at'       => $user->last_order_at,
            ],
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update(['is_active' => $request->boolean('is_active')]);

        return response()->json([
            'message'   => 'Status updated',
            'is_active' => $user->fresh()->is_active,
        ]);
    }
}
