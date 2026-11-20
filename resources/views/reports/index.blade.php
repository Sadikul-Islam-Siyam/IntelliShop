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

            <form method="GET" action="{{ route('reports.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem; align-items: flex-end;">
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

        <!-- KPI Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div class="card">
                <div style="font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Net Sales Revenue</div>
                <div style="font-size: 1.65rem; font-weight: 800; color: #0f172a; margin-top: 0.35rem;">${{ number_format($salesReport['summary']['net_revenue'] ?? 0, 2) }}</div>
            </div>
            <div class="card">
                <div style="font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Completed Transactions</div>
                <div style="font-size: 1.65rem; font-weight: 800; color: #0f172a; margin-top: 0.35rem;">{{ number_format($salesReport['summary']['total_sales_count'] ?? 0) }}</div>
            </div>
            <div class="card">
                <div style="font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Inventory Units On Hand</div>
                <div style="font-size: 1.65rem; font-weight: 800; color: #0f172a; margin-top: 0.35rem;">{{ number_format($inventoryReport['summary']['total_units_on_hand'] ?? 0) }}</div>
            </div>
            <div class="card">
                <div style="font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Inventory Retail Valuation</div>
                <div style="font-size: 1.65rem; font-weight: 800; color: #0f172a; margin-top: 0.35rem;">${{ number_format($inventoryReport['summary']['total_retail_valuation'] ?? 0, 2) }}</div>
            </div>
        </div>

        <!-- Chart.js Visualizations (#34) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
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
        <div class="card" style="margin-bottom: 1.5rem;">
            <h3 style="margin-top: 0;">Sales Breakdown by Date</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 0.65rem;">Date</th>
                        <th style="padding: 0.65rem;">Transactions</th>
                        <th style="padding: 0.65rem;">Units Sold</th>
                        <th style="padding: 0.65rem;">Gross Subtotal</th>
                        <th style="padding: 0.65rem;">Discounts</th>
                        <th style="padding: 0.65rem;">Net Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($salesReport['daily_breakdown'] as $row)
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 0.65rem;">{{ $row['date'] }}</td>
                            <td style="padding: 0.65rem;">{{ $row['transactions'] }}</td>
                            <td style="padding: 0.65rem;">{{ $row['units_sold'] }}</td>
                            <td style="padding: 0.65rem;">${{ number_format($row['subtotal'], 2) }}</td>
                            <td style="padding: 0.65rem;">${{ number_format($row['discount_amount'], 2) }}</td>
                            <td style="padding: 0.65rem; font-weight: 700;">${{ number_format($row['total_revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding: 1rem; color: #64748b;">No sales records found for the selected filter criteria.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Inventory Valuation Table -->
        <div class="card">
            <h3 style="margin-top: 0;">Inventory Stock &amp; Valuation Breakdown</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 0.65rem;">Product</th>
                        <th style="padding: 0.65rem;">Branch</th>
                        <th style="padding: 0.65rem;">On Hand</th>
                        <th style="padding: 0.65rem;">Reorder Point</th>
                        <th style="padding: 0.65rem;">Cost Valuation</th>
                        <th style="padding: 0.65rem;">Retail Valuation</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($inventoryReport['items'] as $item)
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 0.65rem;"><strong>{{ $item['product_name'] }}</strong> <span style="color: #64748b;">({{ $item['sku'] }})</span></td>
                            <td style="padding: 0.65rem;">{{ $item['branch_name'] }}</td>
                            <td style="padding: 0.65rem;">{{ number_format($item['quantity_available']) }}</td>
                            <td style="padding: 0.65rem;">{{ number_format($item['reorder_point']) }}</td>
                            <td style="padding: 0.65rem;">${{ number_format($item['cost_valuation'], 2) }}</td>
                            <td style="padding: 0.65rem; font-weight: 700;">${{ number_format($item['retail_valuation'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding: 1rem; color: #64748b;">No inventory records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
    @include('partials.footer')
    <script>
        const salesChartData = @json($charts['sales_trend'] ?? ['labels' => [], 'datasets' => []]);
        const inventoryChartData = @json($charts['inventory_valuation'] ?? ['labels' => [], 'datasets' => []]);

        if (typeof Chart !== 'undefined') {
            const ctxSales = document.getElementById('salesTrendChart');
            if (ctxSales) {
                new Chart(ctxSales, {
                    type: 'line',
                    data: { labels: salesChartData.labels || [], datasets: salesChartData.datasets || [] },
                    options: { responsive: true }
                });
            }
            const ctxInv = document.getElementById('inventoryChart');
            if (ctxInv) {
                new Chart(ctxInv, {
                    type: 'bar',
                    data: { labels: inventoryChartData.labels || [], datasets: inventoryChartData.datasets || [] },
                    options: { responsive: true }
                });
            }
        }
    </script>
</body>
</html>
