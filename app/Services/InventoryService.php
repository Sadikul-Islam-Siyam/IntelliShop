<?php

namespace App\Services;

use App\Contracts\InventoryService as InventoryServiceContract;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use Illuminate\Support\Facades\DB;

class InventoryService implements InventoryServiceContract
{
    /**
     * The single canonical application choke point for changing inventory balances.
     * Records signed inventory movements into the immutable inventory_transactions ledger.
     */
    public function adjust(
        int $productId,
        int $branchId,
        int $signedQuantity,
        string $type,
        string $reason,
        ?int $batchId = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): int {
        return DB::transaction(function () use (
            $productId,
            $branchId,
            $signedQuantity,
            $type,
            $reason,
            $batchId,
            $referenceType,
            $referenceId
        ) {
            $inventory = Inventory::where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            $quantityBefore = $inventory ? (int) $inventory->quantity_available : 0;
            $quantityAfter = max(0, $quantityBefore + $signedQuantity);

            if ($inventory) {
                $inventory->update([
                    'quantity_available' => $quantityAfter,
                    'last_updated' => now(),
                ]);
            } else {
                $inventory = Inventory::create([
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'quantity_available' => $quantityAfter,
                    'quantity_reserved' => 0,
                    'last_updated' => now(),
                ]);
            }

            if ($batchId) {
                $batch = InventoryBatch::where('id', $batchId)->lockForUpdate()->first();
                if ($batch) {
                    $batchBefore = (int) $batch->quantity_remaining;
                    $batchAfter = max(0, $batchBefore + $signedQuantity);
                    $batch->update([
                        'quantity_remaining' => $batchAfter,
                        'status' => $batchAfter === 0 ? 'depleted' : $batch->status,
                    ]);
                }
            }

            $txId = DB::table('inventory_transactions')->insertGetId([
                'product_id' => $productId,
                'branch_id' => $branchId,
                'transaction_type' => $type,
                'quantity' => $signedQuantity,
                'batch_id' => $batchId,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'performed_by' => auth()->id() ?? null,
                'notes' => $reason,
                'created_at' => now(),
            ]);

            return (int) $txId;
        });
    }
}
