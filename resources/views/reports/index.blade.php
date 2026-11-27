<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sales &amp; Inventory Reports — IntelliShop</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
</head>
<body>
    @include('partials.navbar')
    <main class="main-content" style="max-width: 1280px; margin: 0 auto; padding: 2rem 1.5rem;">
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="workspace-header">
                <div>
                    <h2>Sales &amp; Inventory Analytical Reports</h2>
                    <p class="welcome-subtitle">Date-range &amp; branch-scoped financial reconciliation with interactive Chart.js visualizations.</p>
                </div>
            </div>

            <form method="GET" action="{{ route('reports.index') }}" data-async-form data-async-target="#reports-results" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem; align-items: flex-end;">
                <div>
                    <label for="branch_id" style="display: block; font-size: 0.8rem; font-weight: 700; color: #475569;">Branch</label>
                    <select name="branch_id" id="branch_id" style="padding: 0.5rem; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <option value="">All Accessible Branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null) == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="start_date" style="display: block; font-size: 0.8rem; font-weight: 700; color: #475569;">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="{{ $filters['start_date'] ?? '' }}" style="padding: 0.45rem; border-radius: 6px; border: 1px solid #cbd5e1;">
                </div>
                <div>
                    <label for="end_date" style="display: block; font-size: 0.8rem; font-weight: 700; color: #475569;">End Date</label>
                    <input type="date" name="end_date" id="end_date" value="{{ $filters['end_date'] ?? '' }}" style="padding: 0.45rem; border-radius: 6px; border: 1px solid #cbd5e1;">
                </div>
                <div>
                    <button type="submit" style="background: #2563eb; color: #fff; border: none; padding: 0.55rem 1.25rem; border-radius: 6px; font-weight: 700; cursor: pointer;">Apply Filters</button>
                </div>
            </form>
        </div>

        <div id="reports-results" data-async-target>
        <!-- KPI Summary Cards -->
        <div class="is-kpi-grid">
            <div class="is-kpi-card" data-tone="blue">
                <div class="is-kpi-label">Net Sales Revenue</div>
                <div class="is-kpi-value">৳{{ number_format($salesReport['summary']['net_revenue'] ?? 0, 2) }}</div>
            </div>
            <div class="is-kpi-card" data-tone="emerald">
                <div class="is-kpi-label">Completed Transactions</div>
                <div class="is-kpi-value">{{ number_format($salesReport['summary']['total_sales_count'] ?? 0) }}</div>
            </div>
            <div class="is-kpi-card" data-tone="indigo">
                <div class="is-kpi-label">Inventory Units On Hand</div>
                <div class="is-kpi-value">{{ number_format($inventoryReport['summary']['total_units_on_hand'] ?? 0) }}</div>
            </div>
            <div class="is-kpi-card" data-tone="amber">
                <div class="is-kpi-label">Inventory Retail Valuation</div>
                <div class="is-kpi-value">৳{{ number_format($inventoryReport['summary']['total_retail_valuation'] ?? 0, 2) }}</div>
            </div>
        </div>

        <!-- Chart.js Visualizations (#34) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <div class="card">
                <h3 style="margin-top: 0;">Daily Sales Revenue &amp; Units Trend</h3>
                <canvas id="salesTrendChart" height="160"></canvas>
            </div>
            <div class="card">
                <h3 style="margin-top: 0;">Inventory Stock vs Reorder Points</h3>
                <canvas id="inventoryChart" height="160"></canvas>
            </div>
        </div>

        <!-- Sales Daily Breakdown Table -->
        <div class="card" style="margin-bottom: 1.5rem; padding: 0; overflow: hidden;">
            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Sales Breakdown by Date</h3>
                <span class="is-badge is-badge-info">{{ count($salesReport['daily_breakdown'] ?? []) }} days</span>
            </div>
            <div class="is-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Transactions</th>
                            <th>Units Sold</th>
                            <th>Gross Subtotal</th>
                            <th>Discounts</th>
                            <th>Net Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($salesReport['daily_breakdown'] as $row)
                            <tr>
                                <td style="font-weight: 600;">{{ $row['date'] }}</td>
                                <td>{{ $row['transactions'] }}</td>
                                <td>{{ $row['units_sold'] }}</td>
                                <td>৳{{ number_format($row['subtotal'], 2) }}</td>
                                <td>৳{{ number_format($row['discount_amount'], 2) }}</td>
                                <td style="font-weight: 700; color: #2563eb;">৳{{ number_format($row['total_revenue'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="is-empty-state">
                                    <div class="is-empty-state-title">No sales records found</div>
                                    <p class="is-empty-state-desc">Try widening the date range or selecting a different branch.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Inventory Valuation Table -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Inventory Stock &amp; Valuation Breakdown</h3>
                <span class="is-badge is-badge-info">{{ count($inventoryReport['items'] ?? []) }} SKUs</span>
            </div>
            <div class="is-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Branch</th>
                            <th>On Hand</th>
                            <th>Reorder Point</th>
                            <th>Cost Valuation</th>
                            <th>Retail Valuation</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($inventoryReport['items'] as $item)
                            <tr>
                                <td><strong>{{ $item['product_name'] }}</strong> <span style="color: #64748b;">({{ $item['sku'] }})</span></td>
                                <td>{{ $item['branch_name'] }}</td>
                                <td>
                                    @if((int) $item['quantity_available'] <= (int) $item['reorder_point'])
                                        <span class="is-badge is-badge-danger">{{ number_format($item['quantity_available']) }}</span>
                                    @else
                                        <span class="is-badge is-badge-success">{{ number_format($item['quantity_available']) }}</span>
                                    @endif
                                </td>
                                <td>{{ number_format($item['reorder_point']) }}</td>
                                <td>৳{{ number_format($item['cost_valuation'], 2) }}</td>
                                <td style="font-weight: 700;">৳{{ number_format($item['retail_valuation'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="is-empty-state">
                                    <div class="is-empty-state-title">No inventory records found</div>
                                    <p class="is-empty-state-desc">No inventory items matched the selected filter criteria.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @php
            $chartPayload = [
                'sales_trend' => $charts['sales_trend'] ?? ['labels' => [], 'datasets' => []],
                'inventory_valuation' => $charts['inventory_valuation'] ?? ['labels' => [], 'datasets' => []],
            ];
        @endphp
        <script type="application/json" id="reports-chart-data">{!! json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        </div>
    </main>
    @include('partials.footer')
    <script>
        let salesChartInstance = null;
        let inventoryChartInstance = null;

        function renderReportCharts() {
            if (typeof Chart === 'undefined') return;
            const dataEl = document.getElementById('reports-chart-data');
            if (!dataEl) return;

            let payload = {};
            try { payload = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }

            const salesChartData = payload.sales_trend || { labels: [], datasets: [] };
            const inventoryChartData = payload.inventory_valuation || { labels: [], datasets: [] };

            if (salesChartInstance) { salesChartInstance.destroy(); salesChartInstance = null; }
            if (inventoryChartInstance) { inventoryChartInstance.destroy(); inventoryChartInstance = null; }

            const ctxSales = document.getElementById('salesTrendChart');
            if (ctxSales) {
                salesChartInstance = new Chart(ctxSales, {
                    type: 'line',
                    data: { labels: salesChartData.labels || [], datasets: salesChartData.datasets || [] },
                    options: { responsive: true }
                });
            }
            const ctxInv = document.getElementById('inventoryChart');
            if (ctxInv) {
                inventoryChartInstance = new Chart(ctxInv, {
                    type: 'bar',
                    data: { labels: inventoryChartData.labels || [], datasets: inventoryChartData.datasets || [] },
                    options: { responsive: true }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', renderReportCharts);
        document.addEventListener('intellishop:swapped', renderReportCharts);
    </script>
</body>
</html>
