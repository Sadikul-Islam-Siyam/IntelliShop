<?php

namespace App\Jobs;

use App\Services\FulfillmentRateCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FulfillmentRateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $productId = null,
        public ?int $supplierId = null,
        public ?int $windowDays = null
    ) {}

    public function handle(FulfillmentRateCalculator $calculator): array
    {
        return $calculator->recalculateAll($this->productId, $this->supplierId, $this->windowDays);
    }
}

