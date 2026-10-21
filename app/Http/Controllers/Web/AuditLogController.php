<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * Display a listing of system audit logs with filters.
     */
    public function index(Request $request): View
    {
        $filters = $request->only(['action', 'entity_type', 'user_id', 'search', 'from', 'to']);
        $logs = AuditLogger::paginate($filters, 20);
        $actions = AuditLogger::getDistinctActions();
        $entityTypes = AuditLogger::getDistinctEntityTypes();

        return view('audit-logs', [
            'user' => $request->user(),
            'logs' => $logs,
            'actions' => $actions,
            'entityTypes' => $entityTypes,
            'filters' => $filters,
        ]);
    }
}
