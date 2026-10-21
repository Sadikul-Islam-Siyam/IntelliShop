<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // Ensure the incoming request is from an authenticated user
        if (!auth()->check()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Unauthenticated.',
                    ],
                ], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->guest('/login');
        }

        $user = $request->user();

        // When no explicit roles are provided, check the dynamic route parameter
        if (empty($roles)) {
            $routeRole = $request->route('role');
            if ($routeRole) {
                $roles = [$routeRole];
            }
        }

        // Parse and trim allowed role names and slugs
        $allowedRoles = [];
        foreach ($roles as $role) {
            foreach (preg_split('/[,|]/', $role) as $r) {
                $trimmed = trim($r);
                if ($trimmed !== '') {
                    $allowedRoles[] = $trimmed;
                }
            }
        }

        // Proceed if no specific roles are required
        if (empty($allowedRoles)) {
            return $next($request);
        }

        // Authorize request if user possesses any of the required roles
        if ($user && $user->hasRole($allowedRoles)) {
            return $next($request);
        }

        // Deny access with 403 Forbidden when role authorization fails
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Access denied. Required role not possessed.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, 'Access denied. You do not have permission to access this dashboard.');
    }
}
