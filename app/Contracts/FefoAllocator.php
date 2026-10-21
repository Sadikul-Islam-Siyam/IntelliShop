<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface FefoAllocator
{
    /** @return Collection<int, array{batch_id:int, quantity:int}> Allocation or throw when stock is insufficient. */
    public function allocate(int $productId, int $branchId, int $quantity): Collection;
}
