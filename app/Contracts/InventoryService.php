<?php

namespace App\Contracts;

interface InventoryService
{
    /** The only supported application contract for changing stock balances. */
    public function adjust(int $productId, int $branchId, int $signedQuantity, string $type, string $reason, ?int $batchId = null, ?string $referenceType = null, ?int $referenceId = null): int;
}
