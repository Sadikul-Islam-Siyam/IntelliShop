<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a repeatable six-month retail dataset for demos and analytics work.
 * Run with: php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private const START_DATE = '2026-04-25';
    private const DAYS = 180;
    private const BRANCH_NAME = 'IntelliShop Central Branch';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->ensureFoundationData();

            $manager = User::where('email', 'manager@intellishop.com')->firstOrFail();
            $cashier = User::where('email', 'cashier@intellishop.com')->firstOrFail();
            $branchId = $this->upsert('branches', ['name' => self::BRANCH_NAME], [
                'address' => '100 Demo Market Road',
                'phone' => '+1-555-0100',
                'manager_id' => $manager->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $manager->forceFill(['branch_id' => $branchId])->save();
            $cashier->forceFill(['branch_id' => $branchId])->save();

            $suppliers = $this->seedSuppliers();
            $customers = $this->seedCustomers();
            $products = $this->seedProducts($suppliers);
            $this->seedPromotion($products['coffee'], $customers[0]);

            foreach ($products as $product) {
                $this->seedProductHistory($product, $branchId, $cashier->id, $customers);
            }
        });
    }

    private function ensureFoundationData(): void
    {
        if (Role::count() === 0) {
            $this->call(RoleSeeder::class);
        }

        if (! User::where('email', 'manager@intellishop.com')->exists()
            || ! User::where('email', 'cashier@intellishop.com')->exists()) {
            $this->call(UserSeeder::class);
        }

        if (SystemSetting::count() === 0) {
            $this->call(SettingsSeeder::class);
        }
    }

    /** @return array<string, int> */
    private function seedSuppliers(): array
    {
        $rows = [
            'northstar' => ['name' => 'Northstar Foods', 'contact_person' => 'Avery Patel', 'phone' => '+1-555-0201', 'email' => 'orders@northstar.example', 'address' => '12 Harbor Street', 'rating' => 4.70],
            'harvest' => ['name' => 'Harvest Wholesale', 'contact_person' => 'Jordan Lee', 'phone' => '+1-555-0202', 'email' => 'sales@harvest.example', 'address' => '48 Market Avenue', 'rating' => 4.40],
            'daily' => ['name' => 'Daily Goods Co.', 'contact_person' => 'Morgan Reed', 'phone' => '+1-555-0203', 'email' => 'hello@dailygoods.example', 'address' => '7 Supply Lane', 'rating' => 4.20],
        ];

        $ids = [];
        foreach ($rows as $key => $row) {
            $ids[$key] = $this->upsert('suppliers', ['name' => $row['name']], $row + [
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /** @return list<int> */
    private function seedCustomers(): array
    {
        $rows = [
            ['name' => 'Taylor Morgan', 'email' => 'taylor.morgan@demo.example', 'phone' => '+1-555-0301', 'address' => '10 Oak Street', 'loyalty_points' => 340, 'segment' => 'loyal'],
            ['name' => 'Casey Rivera', 'email' => 'casey.rivera@demo.example', 'phone' => '+1-555-0302', 'address' => '22 Pine Avenue', 'loyalty_points' => 85, 'segment' => 'regular'],
            ['name' => 'Riley Chen', 'email' => 'riley.chen@demo.example', 'phone' => '+1-555-0303', 'address' => '5 Lake Road', 'loyalty_points' => 20, 'segment' => 'new'],
            ['name' => 'Jamie Brooks', 'email' => 'jamie.brooks@demo.example', 'phone' => '+1-555-0304', 'address' => '91 Hill Drive', 'loyalty_points' => 0, 'segment' => 'inactive'],
            ['name' => 'Alex Kim', 'email' => 'alex.kim@demo.example', 'phone' => '+1-555-0305', 'address' => '38 Cedar Court', 'loyalty_points' => 610, 'segment' => 'vip'],
        ];

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $this->upsert('customers', ['email' => $row['email']], $row + [
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /** @param array<string, int> $suppliers
     *  @return array<string, array<string, mixed>>
     */
    private function seedProducts(array $suppliers): array
    {
        $categoryId = $this->upsert('categories', ['name' => 'Grocery & Essentials'], [
            'parent_category_id' => null, 'description' => 'Daily grocery and household products.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $brandId = $this->upsert('brands', ['name' => 'FreshValley'], [
            'description' => 'Retail grocery brand.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = $this->upsert('units', ['name' => 'Each'], [
            'abbreviation' => 'ea', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $definitions = [
            'milk' => ['name' => 'Whole Milk 1L', 'sku' => 'MILK-1L-001', 'barcode' => '890103000101', 'cost' => 1.10, 'price' => 1.79, 'shelf' => 14, 'min' => 12, 'reorder' => 24, 'supplier' => 'northstar', 'pattern' => 'seasonal', 'expiry_days' => 8],
            'rice' => ['name' => 'Long Grain Rice 1kg', 'sku' => 'RICE-1KG-002', 'barcode' => '890103000102', 'cost' => 2.05, 'price' => 3.29, 'shelf' => null, 'min' => 10, 'reorder' => 20, 'supplier' => 'harvest', 'pattern' => 'trend', 'expiry_days' => 400],
            'coffee' => ['name' => 'Ground Coffee 250g', 'sku' => 'COFF-250G-003', 'barcode' => '890103000103', 'cost' => 3.60, 'price' => 5.99, 'shelf' => 365, 'min' => 8, 'reorder' => 16, 'supplier' => 'northstar', 'pattern' => 'promotion', 'expiry_days' => 20],
            'cereal' => ['name' => 'Honey Oat Cereal 500g', 'sku' => 'CERL-500G-004', 'barcode' => '890103000104', 'cost' => 2.25, 'price' => 3.89, 'shelf' => 240, 'min' => 8, 'reorder' => 18, 'supplier' => 'harvest', 'pattern' => 'anomaly', 'expiry_days' => 150],
            'spice' => ['name' => 'Smoked Paprika 50g', 'sku' => 'SPIC-50G-005', 'barcode' => '890103000105', 'cost' => 1.30, 'price' => 2.49, 'shelf' => 540, 'min' => 5, 'reorder' => 10, 'supplier' => 'daily', 'pattern' => 'slow', 'expiry_days' => 300],
            'dead' => ['name' => 'Assorted Gift Tin', 'sku' => 'GIFT-TIN-006', 'barcode' => '890103000106', 'cost' => 4.00, 'price' => 7.50, 'shelf' => null, 'min' => 3, 'reorder' => 6, 'supplier' => 'daily', 'pattern' => 'dead', 'expiry_days' => null],
        ];

        $products = [];
        foreach ($definitions as $key => $definition) {
            $id = $this->upsert('products', ['sku' => $definition['sku']], [
                'name' => $definition['name'], 'barcode' => $definition['barcode'],
                'category_id' => $categoryId, 'brand_id' => $brandId, 'unit_id' => $unitId,
                'unit_size' => 1, 'shelf_life_days' => $definition['shelf'],
                'cost_price' => $definition['cost'], 'selling_price' => $definition['price'],
                'min_stock_level' => $definition['min'], 'reorder_point' => $definition['reorder'],
                'reorder_quantity' => $definition['reorder'] * 2, 'is_expiry_tracked' => $definition['shelf'] !== null,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $supplierId = $suppliers[$definition['supplier']];
            DB::table('product_suppliers')->updateOrInsert(
                ['product_id' => $id, 'supplier_id' => $supplierId],
                ['supply_price' => $definition['cost'], 'lead_time_days' => 3 + ($id % 5),
                    'min_order_qty' => 6, 'quality_score' => 0.90, 'is_preferred' => true,
                    'order_multiple' => 6, 'fulfillment_rate' => 0.96, 'created_at' => now(), 'updated_at' => now()]
            );

            $products[$key] = $definition + ['id' => $id];
        }

        return $products;
    }

    private function seedPromotion(array $product, int $customerId): void
    {
        $start = CarbonImmutable::parse(self::START_DATE);
        $end = $start->addDays(self::DAYS - 1);
        $discountId = $this->upsert('discounts', ['name' => 'Demo Coffee Days'], [
            'type' => 'percentage', 'value' => 10, 'product_id' => $product['id'],
            'category_id' => null, 'batch_id' => null, 'starts_at' => $start->startOfDay(),
            'ends_at' => $end->endOfDay(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->upsert('product_promotions', [
            'product_id' => $product['id'], 'customer_id' => $customerId, 'promotion_type' => 'email',
            'message' => 'Demo promotion: save 10% on Ground Coffee 250g.',
        ], ['sent_at' => $start->addDays(30), 'status' => 'sent', 'opened' => true,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param list<int> $customers */
    private function seedProductHistory(array $product, int $branchId, int $cashierId, array $customers): void
    {
        $start = CarbonImmutable::parse(self::START_DATE);
        $unitSales = [];
        $grossSales = [];
        for ($day = 0; $day < self::DAYS; $day++) {
            $unitSales[$day] = $this->dailyUnits($product['pattern'], $day, $start);
            $grossSales[$day] = round($unitSales[$day] * $product['price'], 2);
        }

        // Two receipts leave a restock point in the middle of the time series.
        $firstDays = 90;
        $firstQty = array_sum(array_slice($unitSales, 0, $firstDays)) + 15;
        $secondQty = array_sum(array_slice($unitSales, $firstDays)) + ($product['pattern'] === 'dead' ? 8 : 12);
        $firstReceived = $start->subDays(2);
        $secondReceived = $start->addDays($firstDays);
        $batchSpecs = [
            ['suffix' => 'A', 'date' => $firstReceived, 'quantity' => $firstQty, 'expiry' => $start->addDays(self::DAYS + 30)],
            ['suffix' => 'B', 'date' => $secondReceived, 'quantity' => $secondQty,
                'expiry' => $start->addDays(self::DAYS + ($product['expiry_days'] ? 3 : 30))],
        ];

        $batches = [];
        foreach ($batchSpecs as $spec) {
            $purchaseOrderId = $this->upsert('purchase_orders', [
                'supplier_id' => $this->supplierForProduct($product), 'branch_id' => $branchId,
                'order_date' => $spec['date']->subDays(3 + (int) $product['id'])->toDateString(),
            ], ['expected_date' => $spec['date']->toDateString(), 'status' => 'received',
                'total_amount' => round($spec['quantity'] * $product['cost'], 2),
                'created_by' => User::where('email', 'purchase@intellishop.com')->value('id'),
                'is_auto_generated' => false, 'created_at' => $spec['date'], 'updated_at' => $spec['date']]);

            $this->upsert('purchase_order_items', ['purchase_order_id' => $purchaseOrderId, 'product_id' => $product['id']], [
                'quantity' => $spec['quantity'], 'unit_price' => $product['cost'],
                'subtotal' => round($spec['quantity'] * $product['cost'], 2),
                'created_at' => $spec['date'], 'updated_at' => $spec['date'],
            ]);

            $batchNo = 'BATCH-' . $product['sku'] . '-' . $spec['suffix'];
            $purchaseId = $this->upsert('purchases', ['batch_no' => $batchNo, 'product_id' => $product['id']], [
                'purchase_order_id' => $purchaseOrderId, 'expiry_date' => $spec['expiry']->toDateString(),
                'quantity_received' => $spec['quantity'], 'unit_cost' => $product['cost'],
                'received_by' => User::where('email', 'purchase@intellishop.com')->value('id'),
                'received_at' => $spec['date'], 'created_at' => $spec['date'], 'updated_at' => $spec['date'],
            ]);

            $batchId = $this->upsert('inventory_batches', [
                'product_id' => $product['id'], 'branch_id' => $branchId, 'batch_no' => $batchNo,
            ], ['purchase_id' => $purchaseId, 'expiry_date' => $spec['expiry']->toDateString(),
                'quantity_received' => $spec['quantity'], 'quantity_remaining' => $spec['quantity'],
                'unit_cost' => $product['cost'], 'received_at' => $spec['date'], 'status' => 'active',
                'created_at' => $spec['date'], 'updated_at' => $spec['date']]);

            $batches[] = ['id' => $batchId, 'remaining' => $spec['quantity'], 'date' => $spec['date'], 'expiry' => $spec['expiry']];
            $this->upsert('inventory_transactions', [
                'product_id' => $product['id'], 'branch_id' => $branchId, 'transaction_type' => 'purchase',
                'reference_type' => 'purchase', 'reference_id' => $purchaseId,
            ], ['quantity' => $spec['quantity'], 'batch_id' => $batchId,
                'quantity_before' => $spec === $batchSpecs[0] ? 0 : $firstQty,
                'quantity_after' => $spec === $batchSpecs[0] ? $firstQty : $firstQty + $spec['quantity'],
                'reason' => 'Demo goods receipt', 'performed_by' => null,
                'notes' => 'Deterministic demo inventory receipt.', 'created_at' => $spec['date']]);
        }

        $quantityOnHand = $firstQty;
        for ($day = 0; $day < self::DAYS; $day++) {
            $date = $start->addDays($day);
            if ($day === $firstDays) {
                $quantityOnHand += $secondQty;
            }
            $quantity = $unitSales[$day];
            if ($quantity === 0) {
                continue;
            }

            $customerId = $customers[$day % count($customers)];
            $invoiceNo = sprintf('INV-%s-%s', $date->format('Ymd'), $product['sku']);
            $subtotal = $grossSales[$day];
            $discount = $product['pattern'] === 'promotion' && $day % 30 < 4 ? round($subtotal * 0.10, 2) : 0.0;
            $saleId = $this->upsert('sales', ['invoice_no' => $invoiceNo], [
                'branch_id' => $branchId, 'customer_id' => $customerId, 'cashier_id' => $cashierId,
                'sale_date' => $date->setTime(12, 0), 'subtotal' => $subtotal,
                'discount_amount' => $discount, 'tax_amount' => 0,
                'total_amount' => round($subtotal - $discount, 2),
                'payment_method' => ['cash', 'card', 'mobile'][($day + $product['id']) % 3],
                'status' => 'completed', 'created_at' => $date->setTime(12, 0), 'updated_at' => $date->setTime(12, 0),
            ]);
            $saleItemId = $this->upsert('sale_items', ['sale_id' => $saleId, 'product_id' => $product['id']], [
                'quantity' => $quantity, 'unit_price' => $product['price'], 'discount' => $discount,
                'subtotal' => round($subtotal - $discount, 2),
                'created_at' => $date->setTime(12, 0), 'updated_at' => $date->setTime(12, 0),
            ]);

            $remainingToAllocate = $quantity;
            usort($batches, fn (array $a, array $b): int => [$a['expiry']->timestamp, $a['id']] <=> [$b['expiry']->timestamp, $b['id']]);
            foreach ($batches as &$batch) {
                if ($batch['date']->greaterThan($date) || $remainingToAllocate === 0) {
                    continue;
                }
                $allocated = min($remainingToAllocate, $batch['remaining']);
                if ($allocated === 0) {
                    continue;
                }
                $batch['remaining'] -= $allocated;
                $remainingToAllocate -= $allocated;
                $quantityBefore = $quantityOnHand;
                $quantityOnHand -= $allocated;

                $this->upsert('sale_item_batches', [
                    'sale_item_id' => $saleItemId, 'inventory_batch_id' => $batch['id'],
                ], ['quantity' => $allocated, 'created_at' => $date->setTime(12, 0)]);
                $this->upsert('inventory_transactions', [
                    'product_id' => $product['id'], 'branch_id' => $branchId,
                    'transaction_type' => 'sale', 'reference_type' => 'sale_item', 'reference_id' => $saleItemId,
                    'batch_id' => $batch['id'],
                ], ['quantity' => -$allocated, 'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityOnHand, 'reason' => 'Demo point-of-sale transaction',
                    'performed_by' => $cashierId, 'notes' => 'Deterministic demo sale.',
                    'created_at' => $date->setTime(12, 0)]);
            }
            unset($batch);

            if ($remainingToAllocate !== 0) {
                throw new \RuntimeException('Demo sales exceed the available batch quantity for ' . $product['sku']);
            }
        }

        foreach ($batches as $batch) {
            DB::table('inventory_batches')->where('id', $batch['id'])->update([
                'quantity_remaining' => $batch['remaining'],
                'status' => $batch['remaining'] === 0 ? 'depleted' : 'active',
                'updated_at' => now(),
            ]);
        }

        DB::table('inventory')->updateOrInsert(
            ['product_id' => $product['id'], 'branch_id' => $branchId],
            ['quantity_available' => $quantityOnHand, 'quantity_reserved' => 0,
                'max_capacity' => max(200, $quantityOnHand), 'last_updated' => now()]
        );
    }

    private function supplierForProduct(array $product): int
    {
        $supplierName = match ($product['pattern']) {
            'seasonal', 'promotion' => 'Northstar Foods',
            'trend', 'anomaly' => 'Harvest Wholesale',
            default => 'Daily Goods Co.',
        };

        return (int) DB::table('suppliers')->where('name', $supplierName)->value('id');
    }

    private function dailyUnits(string $pattern, int $day, CarbonImmutable $start): int
    {
        $weekend = $start->addDays($day)->isWeekend();

        return match ($pattern) {
            'seasonal' => 17 + ($weekend ? 7 : 0) + (($day % 28 < 5) ? 2 : 0),
            'trend' => 5 + intdiv($day, 45) + ($weekend ? 1 : 0),
            'promotion' => 3 + ($day % 30 < 4 ? 8 : 0) + ($weekend ? 1 : 0),
            'anomaly' => ($day === 112 ? 48 : 3) + ($weekend ? 2 : 0),
            'slow' => $day % 8 === 0 ? 1 : 0,
            default => 0,
        };
    }

    /** Inserts or updates a row and returns its primary key. */
    private function upsert(string $table, array $identity, array $values): int
    {
        $query = DB::table($table);
        foreach ($identity as $column => $value) {
            $query->where($column, $value);
        }

        $existing = $query->first();
        if ($existing) {
            DB::table($table)->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) DB::table($table)->insertGetId($identity + $values);
    }
}
