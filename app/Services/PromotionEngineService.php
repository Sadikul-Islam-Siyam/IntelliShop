<?php

namespace App\Services;

use App\Jobs\PromotionJob;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Support\Collection;

class PromotionEngineService
{
    /**
     * Run promotion matching and dispatch jobs for all eligible customers or a specific customer.
     *
     * @param Customer|null $targetCustomer
     * @return array
     */
    public function run(?Customer $targetCustomer = null): array
    {
        $customers = $targetCustomer ? collect([$targetCustomer]) : Customer::whereNotNull('email')->get();
        $dispatched = [];
        $skipped = [];

        $activeDiscounts = Discount::active()->with(['product', 'category'])->get();

        if ($activeDiscounts->isEmpty()) {
            return [
                'dispatched_count' => 0,
                'skipped_count' => $customers->count(),
                'dispatched' => [],
                'skipped' => $customers->pluck('id')->toArray(),
            ];
        }

        foreach ($customers as $customer) {
            $match = $this->findPromotionMatchForCustomer($customer, $activeDiscounts);

            if ($match) {
                PromotionJob::dispatch(
                    $customer,
                    $match['product'],
                    $match['discount'],
                    $match['message']
                );
                $dispatched[] = [
                    'customer_id' => $customer->id,
                    'product_id' => $match['product']->id,
                    'discount_id' => $match['discount']->id,
                    'message' => $match['message'],
                ];
            } else {
                // No match ends the run for that customer
                $skipped[] = $customer->id;
            }
        }

        return [
            'dispatched_count' => count($dispatched),
            'skipped_count' => count($skipped),
            'dispatched' => $dispatched,
            'skipped' => $skipped,
        ];
    }

    /**
     * Find a relevant active discount for a customer based on purchase history.
     */
    public function findPromotionMatchForCustomer(Customer $customer, Collection $activeDiscounts): ?array
    {
        $completedSales = $customer->sales()
            ->where('status', 'completed')
            ->with('items.product')
            ->get();

        if ($completedSales->isEmpty()) {
            return null;
        }

        $productCounts = [];
        $categoryCounts = [];
        $productMap = [];

        foreach ($completedSales as $sale) {
            foreach ($sale->items as $item) {
                if (!$item->product) {
                    continue;
                }
                $pid = $item->product_id;
                $cid = $item->product->category_id;

                $productCounts[$pid] = ($productCounts[$pid] ?? 0) + $item->quantity;
                $productMap[$pid] = $item->product;

                if ($cid) {
                    $categoryCounts[$cid] = ($categoryCounts[$cid] ?? 0) + $item->quantity;
                }
            }
        }

        if (empty($productCounts)) {
            return null;
        }

        arsort($productCounts);
        arsort($categoryCounts);

        // 1. Check direct product match on most frequently bought products
        foreach (array_keys($productCounts) as $pid) {
            $matchedDiscount = $activeDiscounts->firstWhere('product_id', $pid);
            if ($matchedDiscount) {
                $product = $productMap[$pid];
                return [
                    'product' => $product,
                    'discount' => $matchedDiscount,
                    'message' => $this->buildMessage($customer, $product, $matchedDiscount),
                ];
            }
        }

        // 2. Check category match on most frequently bought categories
        foreach (array_keys($categoryCounts) as $cid) {
            $matchedDiscount = $activeDiscounts->firstWhere('category_id', $cid);
            if ($matchedDiscount) {
                // Find highest bought product in this category
                $product = null;
                foreach (array_keys($productCounts) as $pid) {
                    if ($productMap[$pid]->category_id == $cid) {
                        $product = $productMap[$pid];
                        break;
                    }
                }
                if (!$product) {
                    $product = Product::where('category_id', $cid)->first();
                }

                if ($product) {
                    return [
                        'product' => $product,
                        'discount' => $matchedDiscount,
                        'message' => $this->buildMessage($customer, $product, $matchedDiscount),
                    ];
                }
            }
        }

        return null;
    }

    protected function buildMessage(Customer $customer, Product $product, Discount $discount): string
    {
        $discountText = $discount->type === 'percentage'
            ? "{$discount->value}% off"
            : "\${$discount->value} off";

        return "Special offer for {$customer->name}: Get {$discountText} on your favorite product '{$product->name}'!";
    }
}
