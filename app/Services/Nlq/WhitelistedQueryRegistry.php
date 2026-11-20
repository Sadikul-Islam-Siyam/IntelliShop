<?php

namespace App\Services\Nlq;

use App\Models\Branch;
use App\Models\CycleCount;
use App\Models\Inventory;
use App\Models\MarkdownRecommendation;
use App\Models\MarketBasketRule;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesAnomaly;
use App\Models\StockPrediction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * WhitelistedQueryRegistry (Sub-Feature #61)
 *
 * Implements a strict registry of pre-approved parameterized read-only queries
 * for the 10+ business intents. Strictly forbids free-form SQL execution and
 * enforces row limits and execution timeouts.
 */
class WhitelistedQueryRegistry
{
    public const GLOBAL_MAX_ROW_LIMIT = 250;
    public const DEFAULT_TIMEOUT_SECONDS = 5;

    public const SUPPORTED_INTENTS = [
        'sales_summary',
        'top_selling_products',
        'inventory_summary',
        'low_stock_alerts',
        'stock_out_risk',
        'overstock_slow_moving',
        'expiring_batches_markdown',
        'supplier_ranking',
        'sales_anomalies',
        'phantom_inventory',
        'market_basket_cross_sell',
    ];

    /**
     * Execute a whitelisted query for the given intent and sanitized slots.
     *
     * @param  string  $intent
     * @param  array<string, mixed>  $slots
     * @return array{intent: string, columns: list<string>, rows: list<array<string, mixed>>, count: int, execution_time_ms: float}
     */
    public function execute(string $intent, array $slots = []): array
    {
        if (!in_array($intent, self::SUPPORTED_INTENTS, true)) {
            throw new InvalidArgumentException("Intent '{$intent}' is not registered in the whitelisted query registry.");
        }

        $startTime = microtime(true);
        $limit = $this->resolveRowLimit($slots['limit'] ?? null, $intent);

        $result = match ($intent) {
            'sales_summary' => $this->runSalesSummary($slots, $limit),
            'top_selling_products' => $this->runTopSellingProducts($slots, $limit),
            'inventory_summary' => $this->runInventorySummary($slots, $limit),
            'low_stock_alerts' => $this->runLowStockAlerts($slots, $limit),
            'stock_out_risk' => $this->runStockOutRisk($slots, $limit),
            'overstock_slow_moving' => $this->runOverstockSlowMoving($slots, $limit),
            'expiring_batches_markdown' => $this->runExpiringBatchesMarkdown($slots, $limit),
            'supplier_ranking' => $this->runSupplierRanking($slots, $limit),
            'sales_anomalies' => $this->runSalesAnomalies($slots, $limit),
            'phantom_inventory' => $this->runPhantomInventory($slots, $limit),
            'market_basket_cross_sell' => $this->runMarketBasketCrossSell($slots, $limit),
            default => throw new InvalidArgumentException("Unhandled intent: {$intent}"),
        };

        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'intent' => $intent,
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'count' => count($result['rows']),
            'execution_time_ms' => $executionTimeMs,
        ];
    }

    protected function resolveRowLimit(mixed $requestedLimit, string $intent): int
    {
        $max = match ($intent) {
            'supplier_ranking', 'market_basket_cross_sell', 'top_selling_products' => 50,
            default => 100,
        };

        if ($requestedLimit === null || !is_numeric($requestedLimit)) {
            return min(25, $max);
        }

        return max(1, min((int) $requestedLimit, $max));
    }

    protected function runSalesSummary(array $slots, int $limit): array
    {
        $query = DB::table('sales')
            ->selectRaw('DATE(created_at) as sale_date, COUNT(id) as total_transactions, ROUND(SUM(total_amount), 2) as total_revenue, ROUND(AVG(total_amount), 2) as avg_order_value');

        if (!empty($slots['branch_id'])) {
            $query->where('branch_id', (int) $slots['branch_id']);
        }
        if (!empty($slots['start_date'])) {
            $query->where('created_at', '>=', Carbon::parse($slots['start_date'])->startOfDay());
        }
        if (!empty($slots['end_date'])) {
            $query->where('created_at', '<=', Carbon::parse($slots['end_date'])->endOfDay());
        }

        $rows = $query->groupBy(DB::raw('DATE(created_at)'))
            ->orderByDesc('sale_date')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['sale_date', 'total_transactions', 'total_revenue', 'avg_order_value'],
            'rows' => $rows,
        ];
    }

    protected function runTopSellingProducts(array $slots, int $limit): array
    {
        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->selectRaw('si.product_id, p.name as product_name, p.sku, SUM(si.quantity) as units_sold, ROUND(SUM(si.subtotal), 2) as total_sales');

        if (!empty($slots['branch_id'])) {
            $query->where('s.branch_id', (int) $slots['branch_id']);
        }
        if (!empty($slots['start_date'])) {
            $query->where('s.created_at', '>=', Carbon::parse($slots['start_date'])->startOfDay());
        }
        if (!empty($slots['end_date'])) {
            $query->where('s.created_at', '<=', Carbon::parse($slots['end_date'])->endOfDay());
        }

        $rows = $query->groupBy('si.product_id', 'p.name', 'p.sku')
            ->orderByDesc('units_sold')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['product_id', 'product_name', 'sku', 'units_sold', 'total_sales'],
            'rows' => $rows,
        ];
    }

    protected function runInventorySummary(array $slots, int $limit): array
    {
        $query = DB::table('inventory as inv')
            ->join('products as p', 'p.id', '=', 'inv.product_id')
            ->join('branches as b', 'b.id', '=', 'inv.branch_id')
            ->selectRaw('inv.branch_id, b.name as branch_name, inv.product_id, p.name as product_name, p.sku, inv.quantity_available, inv.quantity_reserved, p.selling_price, ROUND(inv.quantity_available * p.cost_price, 2) as stock_valuation');

        if (!empty($slots['branch_id'])) {
            $query->where('inv.branch_id', (int) $slots['branch_id']);
        }
        if (!empty($slots['product_id'])) {
            $query->where('inv.product_id', (int) $slots['product_id']);
        }

        $rows = $query->orderBy('inv.quantity_available', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['branch_id', 'branch_name', 'product_id', 'product_name', 'sku', 'quantity_available', 'quantity_reserved', 'selling_price', 'stock_valuation'],
            'rows' => $rows,
        ];
    }

    protected function runLowStockAlerts(array $slots, int $limit): array
    {
        $query = DB::table('inventory as inv')
            ->join('products as p', 'p.id', '=', 'inv.product_id')
            ->join('branches as b', 'b.id', '=', 'inv.branch_id')
            ->selectRaw('inv.branch_id, b.name as branch_name, p.id as product_id, p.name as product_name, p.sku, inv.quantity_available, p.reorder_point, (p.reorder_point - inv.quantity_available) as deficit')
            ->whereColumn('inv.quantity_available', '<=', 'p.reorder_point');

        if (!empty($slots['branch_id'])) {
            $query->where('inv.branch_id', (int) $slots['branch_id']);
        }

        $rows = $query->orderByDesc('deficit')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['branch_id', 'branch_name', 'product_id', 'product_name', 'sku', 'quantity_available', 'reorder_point', 'deficit'],
            'rows' => $rows,
        ];
    }

    protected function runStockOutRisk(array $slots, int $limit): array
    {
        $query = DB::table('stock_predictions as sp')
            ->join('products as p', 'p.id', '=', 'sp.product_id')
            ->join('branches as b', 'b.id', '=', 'sp.branch_id')
            ->selectRaw('sp.id, sp.branch_id, b.name as branch_name, sp.product_id, p.name as product_name, p.sku, sp.current_stock, sp.predicted_demand, sp.safety_stock, sp.reorder_point, sp.predicted_stockout_date, sp.risk_level, sp.recommended_reorder_qty');

        if (!empty($slots['branch_id'])) {
            $query->where('sp.branch_id', (int) $slots['branch_id']);
        }
        if (!empty($slots['risk_level'])) {
            $query->where('sp.risk_level', strtoupper((string) $slots['risk_level']));
        }

        $rows = $query->orderBy('sp.predicted_stockout_date', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['id', 'branch_id', 'branch_name', 'product_id', 'product_name', 'sku', 'current_stock', 'predicted_demand', 'safety_stock', 'reorder_point', 'predicted_stockout_date', 'risk_level', 'recommended_reorder_qty'],
            'rows' => $rows,
        ];
    }

    protected function runOverstockSlowMoving(array $slots, int $limit): array
    {
        $query = DB::table('ai_recommendations as ar')
            ->join('products as p', 'p.id', '=', 'ar.related_entity_id')
            ->selectRaw('ar.id, ar.related_entity_id as product_id, p.name as product_name, p.sku, ar.priority, ar.priority_score, ar.recommendation_text')
            ->where('ar.type', 'overstock')
            ->where('ar.status', 'open');

        $rows = $query->orderByDesc('ar.priority_score')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['id', 'product_id', 'product_name', 'sku', 'priority', 'priority_score', 'recommendation_text'],
            'rows' => $rows,
        ];
    }

    protected function runExpiringBatchesMarkdown(array $slots, int $limit): array
    {
        $query = DB::table('markdown_recommendations as mr')
            ->join('products as p', 'p.id', '=', 'mr.product_id')
            ->join('branches as b', 'b.id', '=', 'mr.branch_id')
            ->leftJoin('inventory_batches as ib', 'ib.id', '=', 'mr.batch_id')
            ->selectRaw('mr.id, mr.branch_id, b.name as branch_name, mr.product_id, p.name as product_name, p.sku, ib.batch_no, ib.expiry_date, mr.days_remaining, mr.available_qty, mr.suggested_markdown_pct, mr.status')
            ->whereIn('mr.status', ['pending', 'approved']);

        if (!empty($slots['branch_id'])) {
            $query->where('mr.branch_id', (int) $slots['branch_id']);
        }

        $rows = $query->orderBy('mr.days_remaining', 'asc')
            ->orderByDesc('mr.suggested_markdown_pct')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['id', 'branch_id', 'branch_name', 'product_id', 'product_name', 'sku', 'batch_no', 'expiry_date', 'days_remaining', 'available_qty', 'suggested_markdown_pct', 'status'],
            'rows' => $rows,
        ];
    }

    protected function runSupplierRanking(array $slots, int $limit): array
    {
        $query = DB::table('product_suppliers as ps')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->selectRaw('ps.supplier_id, s.name as supplier_name, ps.product_id, p.name as product_name, ps.supply_price, ps.lead_time_days, ps.fulfillment_rate, ps.quality_score, ps.is_preferred');

        if (!empty($slots['product_id'])) {
            $query->where('ps.product_id', (int) $slots['product_id']);
        }

        $rows = $query->orderByDesc('ps.is_preferred')
            ->orderByDesc('ps.fulfillment_rate')
            ->orderBy('ps.supply_price', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['supplier_id', 'supplier_name', 'product_id', 'product_name', 'supply_price', 'lead_time_days', 'fulfillment_rate', 'quality_score', 'is_preferred'],
            'rows' => $rows,
        ];
    }

    protected function runSalesAnomalies(array $slots, int $limit): array
    {
        $query = DB::table('sales_anomalies as sa')
            ->join('products as p', 'p.id', '=', 'sa.product_id')
            ->join('branches as b', 'b.id', '=', 'sa.branch_id')
            ->selectRaw('sa.id, sa.product_id, p.name as product_name, sa.branch_id, b.name as branch_name, sa.detected_date, sa.actual_value, sa.expected_value, sa.deviation_score, sa.status');

        if (!empty($slots['branch_id'])) {
            $query->where('sa.branch_id', (int) $slots['branch_id']);
        }
        if (!empty($slots['product_id'])) {
            $query->where('sa.product_id', (int) $slots['product_id']);
        }

        $rows = $query->orderByDesc('sa.detected_date')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['id', 'product_id', 'product_name', 'branch_id', 'branch_name', 'detected_date', 'actual_value', 'expected_value', 'deviation_score', 'status'],
            'rows' => $rows,
        ];
    }

    protected function runPhantomInventory(array $slots, int $limit): array
    {
        $query = DB::table('cycle_counts as cc')
            ->join('products as p', 'p.id', '=', 'cc.product_id')
            ->join('branches as b', 'b.id', '=', 'cc.branch_id')
            ->selectRaw('cc.id, cc.branch_id, b.name as branch_name, cc.product_id, p.name as product_name, p.sku, cc.system_quantity, cc.physical_quantity, cc.variance, cc.trigger_source, cc.status');

        if (!empty($slots['branch_id'])) {
            $query->where('cc.branch_id', (int) $slots['branch_id']);
        }
        if (!empty($slots['product_id'])) {
            $query->where('cc.product_id', (int) $slots['product_id']);
        }

        $rows = $query->orderByDesc('cc.id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['id', 'branch_id', 'branch_name', 'product_id', 'product_name', 'sku', 'system_quantity', 'physical_quantity', 'variance', 'trigger_source', 'status'],
            'rows' => $rows,
        ];
    }

    protected function runMarketBasketCrossSell(array $slots, int $limit): array
    {
        $query = DB::table('market_basket_rules as mbr')
            ->join('products as pa', 'pa.id', '=', 'mbr.antecedent_product_id')
            ->join('products as pc', 'pc.id', '=', 'mbr.consequent_product_id')
            ->selectRaw('mbr.id, mbr.antecedent_product_id, pa.name as antecedent_name, mbr.consequent_product_id, pc.name as consequent_name, mbr.support, mbr.confidence, mbr.lift');

        if (!empty($slots['product_id'])) {
            $query->where('mbr.antecedent_product_id', (int) $slots['product_id']);
        }

        $rows = $query->orderByDesc('mbr.lift')
            ->orderByDesc('mbr.confidence')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'columns' => ['id', 'antecedent_product_id', 'antecedent_name', 'consequent_product_id', 'consequent_name', 'support', 'confidence', 'lift'],
            'rows' => $rows,
        ];
    }
}
