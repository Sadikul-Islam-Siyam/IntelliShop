<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogController extends Controller
{
    /**
     * Display a paginated listing of audit logs with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['action', 'entity_type', 'user_id', 'search', 'from', 'to']);
        $perPage = (int) $request->input('per_page', 25);
        $logs = AuditLogger::paginate($filters, min(max($perPage, 5), 100));

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
            'available_filters' => [
                'actions' => AuditLogger::getDistinctActions(),
                'entity_types' => AuditLogger::getDistinctEntityTypes(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Display the specified audit log entry.
     */
    public function show(int $id): JsonResponse
    {
        $log = AuditLog::with('user:id,name,email,role_id')->findOrFail($id);

        return response()->json([
            'data' => $log,
        ], Response::HTTP_OK);
    }
}
