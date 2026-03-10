<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->input('_jwt_payload', []);

        if (empty($payload['tenant_id'])) {
            return response()->json(['error' => 'Tenant context missing'], 401);
        }

        $request->merge([
            'tenant_id'   => $payload['tenant_id'],
            'tenant_code' => $payload['tenant_code'] ?? null,
        ]);

        return $next($request);
    }
}
