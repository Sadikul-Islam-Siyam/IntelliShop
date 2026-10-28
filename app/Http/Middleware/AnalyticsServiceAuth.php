<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates server-to-server requests between Laravel and the Python Analytics Service
 * or allows authenticated internal users with the ml.data_export permission.
 */
class AnalyticsServiceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('analytics_service.token', '');
        $bearerToken = (string) $request->bearerToken();
        $headerToken = (string) $request->header('X-Analytics-Token', '');
        $providedToken = $bearerToken !== '' ? $bearerToken : $headerToken;

        // 1. Validate server-to-server service token if provided and configured
        if ($expectedToken !== '' && $providedToken !== '' && hash_equals($expectedToken, $providedToken)) {
            return $next($request);
        }

        // 2. Also permit authenticated Admin / System Analyst with ml.data_export permission
        $user = $request->user();
        if ($user && ($user->isSuperAdmin() || $user->hasPermission('ml.data_export') || $user->hasRole(['admin', 'super-admin', 'system-analyst']))) {
            return $next($request);
        }

        return response()->json([
            'error' => [
                'code' => 'UNAUTHENTICATED',
                'message' => 'Valid analytics service token or authorized analyst session is required.',
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
