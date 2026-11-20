<?php

namespace App\Services\Reports;

use App\Models\DemandForecast;
use App\Models\MarkdownRecommendation;
use App\Models\StockPrediction;
use App\Models\User;

/**
 * ReportChartService (Sub-Feature #34)
 *
 * Formats analytical series into Chart.js JSON configurations
 * (labels, datasets) for sales, inventory, demand forecasts,
 * stock-out risk distribution, and zero-waste markdown surplus.
 */
class ReportChartService
{
    public function __construct(
        protected SalesReportQuery $salesReportQuery,
        protected InventoryReportQuery $inventoryReportQuery
    ) {}

    /**
     * Build Chart.js datasets for all report categories or a specific chart type.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildCharts(array $filters = [], ?User $user = null, ?string $type = null): array
    {
        $branchId = $this->salesReportQuery->resolveScopedBranchId($filters['branch_id'] ?? null, $user);

        if ($type !== null) {
            return match (strtolower($type)) {
                'sales', 'sales_trend' => $this->buildSalesChart($filters, $user),
                'inventory', 'inventory_valuation' => $this->buildInventoryChart($filters, $user),
                'forecasts', 'forecast' => $this->buildForecastChart($branchId, $filters['product_id'] ?? null),
                'risk', 'stock_risk' => $this->buildStockRiskChart($branchId),
                'markdown', 'markdowns' => $this->buildMarkdownChart($branchId),
                default => $this->buildAllCharts($filters, $user, $branchId),
            };
        }

        return $this->buildAllCharts($filters, $user, $branchId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildAllCharts(array $filters = [], ?User $user = null, ?int $branchId = null): array
    {
        $salesChart = $this->buildSalesChart($filters, $user);
        $inventoryChart = $this->buildInventoryChart($filters, $user);
        $forecastChart = $this->buildForecastChart($branchId, $filters['product_id'] ?? null);
        $riskChart = $this->buildStockRiskChart($branchId);
        $markdownChart = $this->buildMarkdownChart($branchId);

        return [
            'labels' => $salesChart['labels'],
            'datasets' => $salesChart['datasets'],
            'charts' => [
                'sales_trend' => $salesChart,
                'inventory_valuation' => $inventoryChart,
                'forecast_projection' => $forecastChart,
                'stock_risk_distribution' => $riskChart,
                'markdown_surplus' => $markdownChart,
            ],
        ];
    }

    /**
     * Build Chart.js configuration for daily sales revenue & units sold.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildSalesChart(array $filters = [], ?User $user = null): array
    {
        $report = $this->salesReportQuery->execute($filters, $user);
        $daily = $report['daily_breakdown'] ?? [];

        $labels = array_column($daily, 'date');
        $revenues = array_map(fn ($r) => (float) ($r['total_revenue'] ?? 0.0), $daily);
        $units = array_map(fn ($r) => (int) ($r['units_sold'] ?? 0), $daily);

        return [
            'chart_type' => 'line',
            'title' => 'Daily Sales Revenue & Units Trend',
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Net Revenue ($)',
                    'data' => $revenues,
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Units Sold',
                    'data' => $units,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                    'fill' => false,
                    'tension' => 0.3,
                ],
            ],
        ];
    }

    /**
     * Build Chart.js configuration for inventory valuation & stock quantities.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildInventoryChart(array $filters = [], ?User $user = null): array
    {
        $report = $this->inventoryReportQuery->execute($filters, $user);
        $items = array_slice($report['items'] ?? [], 0, 15);

        $labels = array_map(fn ($i) => (string) ($i['product_name'] ?? $i['sku']), $items);
        $onHand = array_map(fn ($i) => (int) ($i['quantity_available'] ?? 0), $items);
        $reorderPts = array_map(fn ($i) => (int) ($i['reorder_point'] ?? 0), $items);
        $valuations = array_map(fn ($i) => (float) ($i['retail_valuation'] ?? 0.0), $items);

        return [
            'chart_type' => 'bar',
            'title' => 'Inventory Stock Levels vs Reorder Points',
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Available Stock (Units)',
                    'data' => $onHand,
                    'backgroundColor' => '#3b82f6',
                ],
                [
                    'label' => 'Reorder Point (ROP)',
                    'data' => $reorderPts,
                    'backgroundColor' => '#f59e0b',
                ],
                [
                    'label' => 'Retail Valuation ($)',
                    'data' => $valuations,
                    'backgroundColor' => '#10b981',
                ],
            ],
        ];
    }

    /**
     * Build Chart.js configuration for demand forecast horizon.
     *
     * @return array<string, mixed>
     */
    public function buildForecastChart(?int $branchId = null, mixed $productId = null): array
    {
        $query = DemandForecast::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($productId, fn ($q) => $q->where('product_id', (int) $productId))
            ->orderBy('forecast_date');

        $rows = $query->get()
            ->groupBy(fn (DemandForecast $f) => $f->forecast_date instanceof \DateTimeInterface
                ? $f->forecast_date->format('Y-m-d')
                : substr((string) $f->forecast_date, 0, 10));

        $labels = [];
        $predicted = [];
        foreach ($rows as $dateStr => $group) {
            $labels[] = (string) $dateStr;
            $predicted[] = round((float) $group->sum('predicted_demand'), 2);
        }

        return [
            'chart_type' => 'line',
            'title' => 'Statistical Demand Forecast Projection',
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Predicted Demand (Units)',
                    'data' => $predicted,
                    'borderColor' => '#6366f1',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.15)',
                    'fill' => true,
                ],
            ],
        ];
    }

    /**
     * Build Chart.js configuration for stock-out risk distribution.
     *
     * @return array<string, mixed>
     */
    public function buildStockRiskChart(?int $branchId = null): array
    {
        $predictions = StockPrediction::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        $high = $predictions->filter(fn ($p) => strtoupper((string) $p->risk_level) === 'HIGH')->count();
        $medium = $predictions->filter(fn ($p) => strtoupper((string) $p->risk_level) === 'MEDIUM')->count();
        $low = $predictions->filter(fn ($p) => strtoupper((string) $p->risk_level) === 'LOW')->count();

        return [
            'chart_type' => 'doughnut',
            'title' => 'Stock-Out Risk Distribution',
            'labels' => ['HIGH Risk', 'MEDIUM Risk', 'LOW Risk'],
            'datasets' => [
                [
                    'label' => 'Products by Risk Tier',
                    'data' => [$high, $medium, $low],
                    'backgroundColor' => ['#ef4444', '#f59e0b', '#10b981'],
                ],
            ],
        ];
    }

    /**
     * Build Chart.js configuration for zero-waste batch markdown surplus.
     *
     * @return array<string, mixed>
     */
    public function buildMarkdownChart(?int $branchId = null): array
    {
        $recs = MarkdownRecommendation::query()
            ->with(['product:id,name', 'batch:id,batch_no'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('surplus_qty')
            ->limit(10)
            ->get();

        $labels = $recs->map(fn ($r) => ($r->product?->name ?? 'Product') . ' (' . ($r->batch?->batch_no ?? 'Batch') . ')')->values()->all();
        $surplus = $recs->map(fn ($r) => (float) $r->surplus_qty)->values()->all();
        $markdownPct = $recs->map(fn ($r) => (float) $r->suggested_markdown_pct)->values()->all();

        return [
            'chart_type' => 'bar',
            'title' => 'Zero-Waste Expiring Batch Surplus & Suggested Markdown %',
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Surplus Quantity (Units)',
                    'data' => $surplus,
                    'backgroundColor' => '#f97316',
                ],
                [
                    'label' => 'Suggested Markdown (%)',
                    'data' => $markdownPct,
                    'backgroundColor' => '#8b5cf6',
                ],
            ],
        ];
    }
}
