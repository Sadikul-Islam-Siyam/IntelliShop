<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\StoreBranchRequest;
use App\Http\Requests\Branches\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Branch::query()->with('manager:id,name,email')->orderBy('name');
        if (!$request->user()->isSuperAdmin()) {
            $query->whereKey($request->user()->branch_id);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        $branches = $query->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json([
            'data' => BranchResource::collection($branches->items()),
            'meta' => [
                'current_page' => $branches->currentPage(),
                'per_page' => $branches->perPage(),
                'total' => $branches->total(),
                'last_page' => $branches->lastPage(),
            ],
        ]);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = Branch::create($request->validated());
        AuditLogger::log($request->user()->id, 'branches.create', 'branches', $branch->id, $branch->only(['name', 'manager_id']));

        return response()->json(['data' => new BranchResource($branch->load('manager:id,name,email'))], Response::HTTP_CREATED);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($request, $branch);

        return response()->json(['data' => new BranchResource($branch->load('manager:id,name,email'))]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->fill($request->validated())->save();
        AuditLogger::log($request->user()->id, 'branches.update', 'branches', $branch->id, $request->validated());

        return response()->json(['data' => new BranchResource($branch->load('manager:id,name,email'))]);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), Response::HTTP_FORBIDDEN);
        $branch->update(['status' => 'inactive']);
        AuditLogger::log($request->user()->id, 'branches.deactivate', 'branches', $branch->id, ['status' => 'inactive']);

        return response()->json(['data' => new BranchResource($branch)], Response::HTTP_OK);
    }

    private function authorizeBranch(Request $request, Branch $branch): void
    {
        abort_unless($request->user()->isSuperAdmin() || (int) $request->user()->branch_id === (int) $branch->id, Response::HTTP_FORBIDDEN);
    }
}
