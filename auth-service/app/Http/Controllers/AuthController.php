<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Tenant;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller as BaseController;

class AuthController extends BaseController
{
    public function __construct(private JwtService $jwtService) {}

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'email'       => 'required|string|email|max:255|unique:users',
            'password'    => 'required|string|min:8|confirmed',
            'tenant_code' => 'required|string|exists:tenants,code',
            'role'        => 'sometimes|string|in:admin,manager,staff,viewer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant = Tenant::where('code', $request->tenant_code)->first();

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'tenant_id' => $tenant->id,
            'role'      => $request->input('role', 'staff'),
            'permissions' => [],
        ]);

        $token = $this->jwtService->generateToken($user);

        return response()->json([
            'message' => 'User registered successfully',
            'user'    => $user->toSafeArray(),
            'token'   => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['error' => 'Account is deactivated'], 403);
        }

        $user->update(['last_login_at' => now()]);
        $token = $this->jwtService->generateToken($user);

        return response()->json([
            'message'    => 'Login successful',
            'user'       => $user->toSafeArray(),
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl', 1440) * 60,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Invalidate the token by adding to blacklist in Redis
        $token = $request->bearerToken();
        if ($token) {
            $this->jwtService->blacklistToken($token);
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'No token provided'], 401);
        }

        $newToken = $this->jwtService->refreshToken($token);
        if (!$newToken) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        return response()->json([
            'token'      => $newToken,
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl', 1440) * 60,
        ]);
    }

    public function validateToken(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->input('token');
        if (!$token) {
            return response()->json(['valid' => false, 'error' => 'No token provided'], 401);
        }

        $payload = $this->jwtService->validateToken($token);
        if (!$payload) {
            return response()->json(['valid' => false, 'error' => 'Invalid or expired token'], 401);
        }

        return response()->json([
            'valid'   => true,
            'payload' => $payload,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json(['user' => $user->toSafeArray()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update($request->only(['name', 'email']));

        return response()->json([
            'message' => 'Profile updated successfully',
            'user'    => $user->fresh()->toSafeArray(),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'Current password is incorrect'], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password changed successfully']);
    }
}
