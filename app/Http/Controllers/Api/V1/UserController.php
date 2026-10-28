<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function __construct(
        protected UserService $userService
    ) {}

    /**
     * Display a paginated listing of users.
     * Endpoint: GET /api/v1/users
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->userService->list($request->all());

        return response()->json([
            'data' => UserResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created user.
     * Endpoint: POST /api/v1/users
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $user = $this->userService->create(
            $request->validated(),
            $request->user()?->id
        );

        return response()->json([
            'data' => new UserResource($user),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified user.
     * Endpoint: GET /api/v1/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $user->loadMissing('role');

        return response()->json([
            'data' => new UserResource($user),
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified user.
     * Endpoint: PATCH /api/v1/users/{user}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:100', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $updated = $this->userService->update(
            $user,
            $validated,
            $request->user()?->id
        );

        return response()->json([
            'data' => new UserResource($updated),
        ], Response::HTTP_OK);
    }

    /**
     * Deactivate the specified user (soft deactivation keeping history).
     * Endpoint: DELETE /api/v1/users/{user}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $deactivated = $this->userService->deactivate(
            $user,
            $request->user()?->id
        );

        return response()->json([
            'data' => [
                'message' => 'User account successfully deactivated.',
                'user' => new UserResource($deactivated),
            ],
        ], Response::HTTP_OK);
    }
}
