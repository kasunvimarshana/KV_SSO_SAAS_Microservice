<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Unauthorized: No token provided'], 401);
        }

        try {
            $payload = (array) JWT::decode($token, new Key(config('jwt.secret'), 'HS256'));
            $request->merge([
                '_jwt_payload' => $payload,
                '_user_id'     => $payload['sub'] ?? null,
                '_user_role'   => $payload['role'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Unauthorized: Invalid or expired token'], 401);
        }

        return $next($request);
    }
}
