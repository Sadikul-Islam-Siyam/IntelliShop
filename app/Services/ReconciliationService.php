<?php

namespace App\Services;

use App\Contracts\InventoryService;
use App\Contracts\SettingsService;
use App\Models\CycleCount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReconciliationService
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected SettingsService $settingsService
    ) {}

    /**
     * Record physical count entry for a cycle count task.
     * Computes signed Stock Variance = Physical Quantity - Recorded System Quantity.
     */
    public function recordPhysicalCount(CycleCount $cycleCount, int $physicalQuantity, int $userId, ?string $reason = null): CycleCount
    {
        $systemQuantity = (int) $cycleCount->system_quantity;
        $variance = $physicalQuantity - $systemQuantity; // signed: physical - recorded

        $cycleCount->update([
            'physical_quantity' => $physicalQuantity,
            'variance' => $variance,
            'status' => 'pending_approval',
            'counted_by' => $userId,
            'counted_at' => now(),
            'variance_reason' => $reason ?: $cycleCount->variance_reason,
            'previous_quantity' => $systemQuantity,
        ]);

        AuditLogger::log(
            $userId,
            'cycle_count.counted',
            'cycle_counts',
            $cycleCount->id,
            [
                'physical_quantity' => $physicalQuantity,
                'system_quantity' => $systemQuantity,
                'variance' => $variance,
                'reason' => $reason,
            ]
        );

        return $cycleCount->fresh();
    }

    /**
     * Approve variance and post explicit stock adjustment ledger movement.
     * Large variances (default 50+ units) require Super Administrator or Admin approval.
     */
    public function approveVariance(CycleCount $cycleCount, int $approverId, ?string $notes = null): CycleCount
    {
        if ($cycleCount->physical_quantity === null) {
            throw ValidationException::withMessages([
                'cycle_count' => ['Cannot approve cycle count before physical count is recorded.'],
            ]);
        }

        if (in_array($cycleCount->status, ['approved', 'closed'], true)) {
            throw ValidationException::withMessages([
                'cycle_count' => ['This cycle count has already been approved.'],
            ]);
        }

        $approver = User::find($approverId);
        $threshold = (int) $this->settingsService->get('large_variance_super_admin_units', 50);

        if (abs((int) $cycleCount->variance) >= $threshold) {
            $isSuperOrAdmin = $approver && ($approver->isAdmin() || $approver->isSuperAdmin() || $approver->hasRole(['admin', 'super-admin']));
            if (!$isSuperOrAdmin) {
                throw ValidationException::withMessages([
                    'approval' => ["Variances of {$threshold}+ units require Super Administrator approval."],
                ]);
            }
        }

        $variance = (int) $cycleCount->variance;
        $txId = null;

        if ($variance !== 0) {
            $txId = $this->inventoryService->adjust(
                (int) $cycleCount->product_id,
                (int) $cycleCount->branch_id,
                $variance,
                'adjustment',
                $notes ?: ($cycleCount->variance_reason ?: 'Cycle count reconciliation adjustment'),
                $cycleCount->batch_id ? (int) $cycleCount->batch_id : null,
                'cycle_counts',
                (int) $cycleCount->id
            );
        }

        $cycleCount->update([
            'status' => 'approved',
            'reviewed_by' => $approverId,
            'reviewed_at' => now(),
            'closed_at' => now(),
            'adjusted_quantity' => (int) $cycleCount->physical_quantity,
            'adjustment_transaction_id' => $txId,
        ]);

        AuditLogger::log(
            $approverId,
            'cycle_count.approved',
            'cycle_counts',
            $cycleCount->id,
            [
                'variance' => $variance,
                'physical_quantity' => $cycleCount->physical_quantity,
                'system_quantity' => $cycleCount->system_quantity,
                'adjustment_transaction_id' => $txId,
                'notes' => $notes,
            ]
        );

        return $cycleCount->fresh();
    }

    /**
     * Reject variance review, leaving system inventory intact.
     */
    public function rejectVariance(CycleCount $cycleCount, int $reviewerId, ?string $reason = null): CycleCount
    {
        $cycleCount->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'closed_at' => now(),
            'variance_reason' => $reason ?: $cycleCount->variance_reason,
        ]);

        AuditLogger::log(
            $reviewerId,
            'cycle_count.rejected',
            'cycle_counts',
            $cycleCount->id,
            [
                'reason' => $reason,
                'variance' => $cycleCount->variance,
            ]
        );

        return $cycleCount->fresh();
    }
}
