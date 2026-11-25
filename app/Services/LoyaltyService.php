<?php

namespace App\Services;

use App\Contracts\SettingsService;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;

class LoyaltyService
{
    public function __construct(
        protected ?SettingsService $settingsService = null
    ) {
        $this->settingsService = $settingsService ?? app(SettingsService::class);
    }

    /**
     * Calculate loyalty points earned for a given currency amount.
     * Rule: Points = floor(sale total / points_per_currency_unit)
     */
    public function calculatePoints(float $totalAmount): int
    {
        if ($totalAmount <= 0) {
            return 0;
        }

        $rate = (float) $this->settingsService->get('loyalty_points_currency_rate', 10.0);
        if ($rate <= 0) {
            $rate = 10.0;
        }

        return (int) floor($totalAmount / $rate);
    }

    /**
     * Award loyalty points to a customer upon sale completion.
     */
    public function awardPointsForSale(Sale $sale): int
    {
        if (!$sale->customer_id || strtolower((string) $sale->status) !== 'completed') {
            return 0;
        }

        $customer = Customer::find($sale->customer_id);
        if (!$customer) {
            return 0;
        }

        $points = $this->calculatePoints((float) $sale->total_amount);
        if ($points > 0) {
            $customer->increment('loyalty_points', $points);
            Log::info("Awarded {$points} loyalty points to customer #{$customer->id} for sale #{$sale->id}");
        }

        return $points;
    }
}
