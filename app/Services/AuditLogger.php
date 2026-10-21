<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * AuditLogger Service
 *
 * Records immutable audit trail entries in the database and structured log channel
 * for all administrative, security, and sensitive business actions.
 */
class AuditLogger implements \App\Contracts\AuditLogger
{
    /**
     * Log an administrative or sensitive action to the audit_logs table.
     *
     * @param int|null $userId ID of the user performing the action (null for system/guest events)
     * @param string $action Standard action key (e.g., 'auth.login', 'users.create', 'settings.update')
     * @param string|null $entityType Name of the affected entity model/table (e.g., 'users', 'roles', 'settings')
     * @param int|null $entityId Primary key of the affected entity
     * @param array|string|null $details Contextual details or payload changes
     * @param string|null $ip Client IP address (defaults to request IP)
     * @return AuditLog
     */
    public static function log(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array|string|null $details = null,
        ?string $ip = null
    ): AuditLog {
        $ipAddress = $ip ?? (function_exists('request') && request() ? request()->ip() : '127.0.0.1');

        // Persist immutable audit log entry in the database
        $auditLog = AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);

        // Secondary structured log entry for aggregation
        Log::info('[AUDIT]', [
            'id' => $auditLog->id,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip_address' => $ipAddress,
            'timestamp' => now()->toIso8601String(),
        ]);

        return $auditLog;
    }

    /**
     * Retrieve paginated audit logs with optional filtering.
     *
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public static function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = AuditLog::with('user:id,name,email,role_id')
            ->latest('id');

        if (!empty($filters['action'])) {
            $query->filterByAction($filters['action']);
        }

        if (!empty($filters['entity_type'])) {
            $query->filterByEntity($filters['entity_type'], !empty($filters['entity_id']) ? (int) $filters['entity_id'] : null);
        }

        if (!empty($filters['user_id'])) {
            $query->filterByUser((int) $filters['user_id']);
        }

        if (!empty($filters['from']) || !empty($filters['to'])) {
            $query->filterByDateRange($filters['from'] ?? null, $filters['to'] ?? null);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get distinct action keys for filtering dropdowns.
     *
     * @return Collection<int, string>
     */
    public static function getDistinctActions(): Collection
    {
        return AuditLog::distinct()->orderBy('action')->pluck('action');
    }

    /**
     * Get distinct entity types for filtering dropdowns.
     *
     * @return Collection<int, string>
     */
    public static function getDistinctEntityTypes(): Collection
    {
        return AuditLog::whereNotNull('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type');
    }
}
