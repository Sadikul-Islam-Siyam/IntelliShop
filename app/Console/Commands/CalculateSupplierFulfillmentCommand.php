<?php

namespace App\Console\Commands;

use App\Services\FulfillmentRateCalculator;
use Illuminate\Console\Command;

class CalculateSupplierFulfillmentCommand extends Command
{
    protected $signature = 'suppliers:fulfillment
                            {--product= : Optional product ID filter}
                            {--supplier= : Optional supplier ID filter}
                            {--window= : Optional rolling window in days (default: 90)}';

    protected $description = 'Calculate and update product_suppliers.fulfillment_rate over a rolling window (#67)';

    public function handle(FulfillmentRateCalculator $calculator): int
    {
        $productId = $this->option('product') !== null ? (int) $this->option('product') : null;
        $supplierId = $this->option('supplier') !== null ? (int) $this->option('supplier') : null;
        $windowDays = $this->option('window') !== null ? (int) $this->option('window') : null;

        $results = $calculator->recalculateAll($productId, $supplierId, $windowDays);

        $this->info(sprintf('Updated fulfillment rates for %d product-supplier records.', count($results)));

        return self::SUCCESS;
    }
}

