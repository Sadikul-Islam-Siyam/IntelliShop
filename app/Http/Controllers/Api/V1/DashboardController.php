<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    /**
     * Retrieve role dashboard metadata for the currently authenticated user.
     * Endpoint: GET /api/v1/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Reject request if user is not authenticated
        if (!$user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $roleName = $user->role?->name ?? 'User';
        $normalizedSlug = Str::kebab(str_replace('_', '-', $user->role?->slug ?? 'user'));
        $redirectMap = config('permissions.role_redirect_map', []);
        $dashboards = config('permissions.role_dashboards', []);
        $roleConfig = $dashboards[$normalizedSlug] ?? null;

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'role' => $roleName,
                'role_slug' => $normalizedSlug,
                'dashboard_route' => $redirectMap[$roleName] ?? '/dashboard',
                'permissions' => $user->getPermissions(),
                'modules' => $roleConfig['modules'] ?? [],
                'meta' => [
                    'title' => $roleConfig['title'] ?? 'Dashboard',
                    'badge' => $roleConfig['badge'] ?? 'Standard Workspace',
                    'description' => $roleConfig['description'] ?? 'IntelliShop User Dashboard',
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Retrieve metadata for a specific role dashboard.
     * Endpoint: GET /api/v1/dashboard/{role}
     */
    public function show(Request $request, string $role): JsonResponse
    {
        $user = $request->user();

        // Reject request if user is not authenticated
        if (!$user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $normalizedSlug = Str::kebab(str_replace('_', '-', $role));
        $dashboards = config('permissions.role_dashboards', []);

        // Validate that requested dashboard exists
        if (!isset($dashboards[$normalizedSlug])) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Dashboard not found.',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $roleConfig = $dashboards[$normalizedSlug];

        // Check whether user possesses the required role
        if (!$user->hasRole($roleConfig['role_name']) && !$user->hasRole($normalizedSlug)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Access denied. Required role not possessed.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'data' => [
                'role' => $roleConfig['role_name'],
                'role_slug' => $normalizedSlug,
                'dashboard_route' => "/dashboard/{$normalizedSlug}",
                'modules' => $roleConfig['modules'],
                'meta' => [
                    'title' => $roleConfig['title'],
                    'badge' => $roleConfig['badge'],
                    'description' => $roleConfig['description'],
                ],
            ],
        ], Response::HTTP_OK);
    }
}
