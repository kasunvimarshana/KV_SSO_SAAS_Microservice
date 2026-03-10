<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->input('_user_role');

        if (!$role || !in_array($role, $roles)) {
            return response()->json([
                'error'          => 'Forbidden: Insufficient role',
                'required_roles' => $roles,
                'current_role'   => $role,
            ], 403);
        }

        return $next($request);
    }
}
