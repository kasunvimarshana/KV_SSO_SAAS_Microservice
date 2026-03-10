<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $payload = $request->input('_jwt_payload', []);

        if (!$user || !isset($payload['tenant_id'])) {
            return response()->json(['error' => 'Tenant context missing'], 401);
        }

        // Set tenant context
        $request->merge([
            'tenant_id' => $payload['tenant_id'],
            'tenant_code' => $payload['tenant_code'] ?? null,
        ]);

        return $next($request);
    }
}
