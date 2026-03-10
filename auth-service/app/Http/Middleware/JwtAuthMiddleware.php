<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    public function __construct(private JwtService $jwtService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Unauthorized: No token provided'], 401);
        }

        $payload = $this->jwtService->validateToken($token);

        if (!$payload) {
            return response()->json(['error' => 'Unauthorized: Invalid or expired token'], 401);
        }

        $user = User::find($payload['sub']);

        if (!$user || !$user->is_active) {
            return response()->json(['error' => 'Unauthorized: User not found or inactive'], 401);
        }

        // Set user on request
        $request->setUserResolver(fn() => $user);
        $request->merge(['_jwt_payload' => $payload]);

        return $next($request);
    }
}
