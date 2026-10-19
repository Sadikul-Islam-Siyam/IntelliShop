<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * Authenticate user session.
     * Endpoint: POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->authService->login(
            $validated['email'],
            $validated['password'],
            (bool) ($validated['remember'] ?? false),
            $request
        );

        return response()->json([
            'data' => [
                'user' => new UserResource($result['user']),
                'role' => $result['role'],
                'permissions' => $result['permissions'],
                'redirect' => $result['redirect'],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Terminate current authenticated session.
     * Endpoint: POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return response()->json([
            'data' => [
                'message' => 'Successfully logged out.',
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Get profile of currently authenticated user.
     * Endpoint: GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user->loadMissing('role');

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'role' => $user->role?->name,
                'permissions' => $user->getPermissions(),
                'branch_id' => $user->branch_id,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Register a new user account.
     * Endpoint: POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register(
            $request->validated(),
            $request->user()?->id
        );

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'role' => $user->role?->name,
                'permissions' => $user->getPermissions(),
            ],
        ], Response::HTTP_CREATED);
    }
}
