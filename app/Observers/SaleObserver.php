<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\LoyaltyService;

class SaleObserver
{
    public function __construct(
        protected LoyaltyService $loyaltyService
    ) {}

    public function created(Sale $sale): void
    {
        if (strtolower((string) $sale->status) === 'completed') {
            $this->loyaltyService->awardPointsForSale($sale);
        }
    }

    public function updated(Sale $sale): void
    {
        if ($sale->isDirty('status') && strtolower((string) $sale->status) === 'completed') {
            $this->loyaltyService->awardPointsForSale($sale);
        }
    }
}
