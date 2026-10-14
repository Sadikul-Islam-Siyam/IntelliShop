<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * AuditLogger Service (Contract Pack Stub - Sub-Feature #73 / #5)
 *
 * Signature: AuditLogger::log(user_id, action, entity_type, entity_id, details, ip)
 * Writes audit trail entries. No-op or log fallback until #5 full foundation lands.
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
