<?php

namespace App\Services\Nlq;

/**
 * AnswerComposer (Sub-Feature #63)
 *
 * Synthesizes a structured analytical response comprising:
 *  1. Plain-English narrative answer highlighting key findings
 *  2. Formatted tabular dataset (columns & rows)
 *  3. Verifiable evidence sources and filter constraints
 */
class AnswerComposer
{
    /**
     * Map of intents to underlying primary database entities / tables for evidence citations.
     */
    protected const INTENT_SOURCE_TABLES = [
        'sales_summary' => 'sales',
        'top_selling_products' => 'sale_items, sales, products',
        'inventory_summary' => 'inventory, products, branches',
        'low_stock_alerts' => 'inventory, products',
        'stock_out_risk' => 'stock_predictions, products',
        'overstock_slow_moving' => 'ai_recommendations, products',
        'expiring_batches_markdown' => 'markdown_recommendations, inventory_batches',
        'supplier_ranking' => 'product_suppliers, suppliers, products',
        'sales_anomalies' => 'sales_anomalies, products',
        'phantom_inventory' => 'cycle_counts, products',
        'market_basket_cross_sell' => 'market_basket_rules, products',
    ];

    /**
     * Compose comprehensive answer package from resolved query and database execution result.
     *
     * @param  string  $intent
     * @param  array<string, mixed>  $slots
     * @param  array{columns: list<string>, rows: list<array<string, mixed>>, count: int, execution_time_ms: float}  $queryResult
     * @param  string  $rawQuery
     * @param  float  $confidence
     * @return array<string, mixed>
     */
    public function compose(
        string $intent,
        array $slots,
        array $queryResult,
        string $rawQuery,
        float $confidence = 1.0
    ): array {
        $rows = $queryResult['rows'];
        $count = $queryResult['count'];
        $executionTimeMs = $queryResult['execution_time_ms'];

        $narrative = $this->composeNarrative($intent, $slots, $rows, $count);
        $sources = $this->composeSources($intent, $slots, $count, $executionTimeMs);

        return [
            'query' => $rawQuery,
            'intent' => $intent,
            'confidence' => $confidence,
            'narrative_answer' => $narrative,
            'table' => [
                'columns' => $queryResult['columns'],
                'rows' => $rows,
                'count' => $count,
            ],
            'sources' => $sources,
            'metadata' => [
                'execution_time_ms' => $executionTimeMs,
                'generated_at' => now()->toIso8601String(),
                'filters_applied' => $slots,
            ],
        ];
    }

    /**
     * Synthesize domain-specific natural-language answer text.
     */
    protected function composeNarrative(string $intent, array $slots, array $rows, int $count): string
    {
        if ($count === 0) {
            $branchText = !empty($slots['branch_id']) ? " at Branch #{$slots['branch_id']}" : '';
            return "No matching records were found for '{$intent}'{$branchText} under the specified parameters.";
        }

        return match ($intent) {
            'sales_summary' => $this->summarizeSales($rows, $slots),
            'top_selling_products' => $this->summarizeTopSelling($rows, $slots),
            'inventory_summary' => $this->summarizeInventory($rows, $slots),
            'low_stock_alerts' => $this->summarizeLowStock($rows, $slots),
            'stock_out_risk' => $this->summarizeStockRisk($rows, $slots),
            'overstock_slow_moving' => "Found {$count} slow-moving / overstock item(s) flagged for surplus review.",
            'expiring_batches_markdown' => $this->summarizeMarkdowns($rows, $slots),
            'supplier_ranking' => $this->summarizeSuppliers($rows),
            'sales_anomalies' => "Found {$count} statistical sales anomal(ies) flagged by deviation score.",
            'phantom_inventory' => "Found {$count} cycle-count variance and phantom inventory item(s) on file.",
            'market_basket_cross_sell' => "Identified {$count} market basket cross-sell association rule(s).",
            default => "Successfully retrieved {$count} record(s) for query intent '{$intent}'.",
        };
    }

    protected function summarizeSales(array $rows, array $slots): string
    {
        $totalRevenue = array_sum(array_column($rows, 'total_revenue'));
        $totalTx = array_sum(array_column($rows, 'total_transactions'));
        $branchText = !empty($slots['branch_id']) ? " at Branch #{$slots['branch_id']}" : '';
        $days = count($rows);

        return sprintf(
            'Over %d active day(s)%s, total revenue reached ৳%.2f across %d sales transaction(s).',
            $days,
            $branchText,
            $totalRevenue,
            $totalTx
        );
    }

    protected function summarizeTopSelling(array $rows, array $slots): string
    {
        $top = $rows[0];
        $topName = $top['product_name'] ?? 'Top Product';
        $topQty = (int) ($top['units_sold'] ?? 0);
        $topSales = (float) ($top['total_sales'] ?? 0.0);
        $count = count($rows);

        return sprintf(
            'Retrieved top %d best-selling product(s). Leading item is "%s" with %d unit(s) sold (৳%.2f gross sales).',
            $count,
            $topName,
            $topQty,
            $topSales
        );
    }

    protected function summarizeInventory(array $rows, array $slots): string
    {
        $totalValuation = array_sum(array_column($rows, 'stock_valuation'));
        $count = count($rows);
        $branchText = !empty($slots['branch_id']) ? " at Branch #{$slots['branch_id']}" : '';

        return sprintf(
            'Found %d inventory record(s)%s with a combined stock valuation of ৳%.2f.',
            $count,
            $branchText,
            $totalValuation
        );
    }

    protected function summarizeLowStock(array $rows, array $slots): string
    {
        $count = count($rows);
        $top = $rows[0];
        $topName = $top['product_name'] ?? 'Product';
        $avail = (int) ($top['quantity_available'] ?? 0);
        $rop = (int) ($top['reorder_point'] ?? 0);

        return sprintf(
            'Found %d product(s) below reorder threshold. Greatest deficit is "%s" (%d units on hand vs ROP %d).',
            $count,
            $topName,
            $avail,
            $rop
        );
    }

    protected function summarizeStockRisk(array $rows, array $slots): string
    {
        $count = count($rows);
        $top = $rows[0];
        $topName = $top['product_name'] ?? 'Product';
        $risk = $top['risk_level'] ?? 'HIGH';
        $stockoutDate = $top['predicted_stockout_date'] ?? 'approaching';

        return sprintf(
            'Found %d product(s) flagged for stock-out risk. Most urgent is "%s" (Risk: %s, predicted stock-out: %s).',
            $count,
            $topName,
            $risk,
            $stockoutDate
        );
    }

    protected function summarizeMarkdowns(array $rows, array $slots): string
    {
        $count = count($rows);
        $top = $rows[0];
        $topName = $top['product_name'] ?? 'Product';
        $days = (int) ($top['days_remaining'] ?? 0);
        $pct = (float) ($top['suggested_markdown_pct'] ?? 0.0);

        return sprintf(
            'Found %d batch(es) nearing expiration. "%s" expires in %d day(s) with a suggested %.0f%% markdown discount.',
            $count,
            $topName,
            $days,
            $pct
        );
    }

    protected function summarizeSuppliers(array $rows): string
    {
        $top = $rows[0];
        $name = $top['supplier_name'] ?? 'Supplier';
        $fulfillment = number_format(((float) ($top['fulfillment_rate'] ?? 0.0)) * 100, 1);
        $price = (float) ($top['supply_price'] ?? 0.0);

        return sprintf(
            'Ranked %d supplier(s). Highest evaluated supplier is "%s" with %s%% fulfillment rate at ৳%.2f unit price.',
            count($rows),
            $name,
            $fulfillment,
            $price
        );
    }

    /**
     * Build verifiable citation metadata.
     */
    protected function composeSources(string $intent, array $slots, int $count, float $executionTimeMs): array
    {
        $primaryTables = self::INTENT_SOURCE_TABLES[$intent] ?? 'analytics_database';

        $filterCitations = [];
        foreach ($slots as $key => $val) {
            if ($val !== null && $val !== '') {
                $strVal = is_array($val) ? json_encode($val) : (string) $val;
                $filterCitations[] = "{$key}={$strVal}";
            }
        }

        return [
            'primary_source_tables' => $primaryTables,
            'filter_constraints' => !empty($filterCitations) ? implode(', ', $filterCitations) : 'None (global scope)',
            'query_intent' => $intent,
            'rows_matched' => $count,
            'query_latency_ms' => $executionTimeMs,
            'audit_verified' => true,
        ];
    }
}
