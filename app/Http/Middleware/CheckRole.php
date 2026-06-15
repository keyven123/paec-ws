<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!auth('admin')->check() || $request->bearerToken() == null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $user = auth('admin')->user();

        if (!$user->role) {
            return response()->json([
                'success' => false,
                'message' => 'User has no role assigned'
            ], 403);
        }

        if (!in_array($user->role->code, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions. Required role: ' . implode(', ', $roles)
            ], 403);
        }

        return $next($request);
    }
}
