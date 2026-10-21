<?php

namespace App\Contracts;

use App\Models\AuditLog;

interface AuditLogger
{
    /** @param array<string, mixed>|string|null $details */
    public static function log(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, array|string|null $details = null): AuditLog;
}
