<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!in_array($user->role, $roles)) {
            return response()->json([
                'error' => 'Forbidden: Insufficient role',
                'required_roles' => $roles,
                'current_role' => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
