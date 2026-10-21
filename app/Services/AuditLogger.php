<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * AuditLogger Service
 *
 * Writes audit trail entries for administrative and sensitive actions.
 */
class AuditLogger
{
    /**
     * Log an administrative or sensitive action.
     */
    public static function log(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array|string|null $details = null,
        ?string $ip = null
    ): void {
        Log::info('[AUDIT]', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip_address' => $ip ?? request()->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
