<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slow-Moving &amp; Overstock Alerts - IntelliShop</title>
    <link rel="stylesheet" href="{{ asset('css/navbar-footer.css') }}">
    <link rel="stylesheet" href="{{ asset('css/overstock.css') }}">
</head>
<body>
    @include('partials.navbar')

    <main class="main-container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Slow-Moving &amp; Overstock Detection</h1>
                <p class="page-subtitle">
                    Evaluating {{ $windowDays }}-day sell-through rate (threshold &lt; {{ number_format($slowMovingThreshold * 100, 0) }}%) and {{ $horizonDays }}-day cumulative forecast demand cover
                </p>
            </div>
            <div>
                <a href="{{ route('reorder.index', ['branch_id' => $selectedBranchId]) }}" class="btn btn-secondary">Replenishment &amp; ROP</a>
            </div>
        </div>

        <div class="summary-strip">
            <div class="summary-card">
                <div class="summary-label">Catalog Evaluated</div>
                <div class="summary-value">{{ number_format($totalProducts) }}</div>
                <div class="summary-subtext">Active products at branch</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Overstock Items</div>
                <div class="summary-value summary-value-danger">{{ number_format($overstockCount) }}</div>
                <div class="summary-subtext">Stock &gt; {{ $horizonDays }}-day forecast demand</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Slow-Moving Items</div>
                <div class="summary-value summary-value-warning">{{ number_format($slowMovingCount) }}</div>
                <div class="summary-subtext">Sell-through &lt; {{ number_format($slowMovingThreshold * 100, 0) }}% ({{ $windowDays }}d)</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Total Surplus Stock</div>
                <div class="summary-value">{{ number_format($totalSurplusUnits, 0) }} units</div>
                <div class="summary-subtext">Above {{ $horizonDays }}-day demand horizon</div>
            </div>
        </div>

        <form method="GET" action="{{ route('overstock.index') }}" class="filter-bar">
            <label for="branch_id" class="text-muted">Branch:</label>
            <select name="branch_id" id="branch_id" class="filter-select">
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (int) $selectedBranchId === (int) $b->id ? 'selected' : '' }}>
                        {{ $b->name }} ({{ $b->code }})
                    </option>
                @endforeach
            </select>

            <label for="filter" class="text-muted">View Filter:</label>
            <select name="filter" id="filter" class="filter-select">
                <option value="all" {{ $filterMode === 'all' ? 'selected' : '' }}>All Products</option>
                <option value="alerts_only" {{ $filterMode === 'alerts_only' ? 'selected' : '' }}>All Flagged Alerts</option>
                <option value="overstock" {{ $filterMode === 'overstock' ? 'selected' : '' }}>Overstock Only</option>
                <option value="slow_moving" {{ $filterMode === 'slow_moving' ? 'selected' : '' }}>Slow-Moving Only</option>
            </select>

            <button type="submit" class="btn btn-primary">Apply Filter</button>
        </form>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>On-Hand Stock</th>
                        <th>Sold ({{ $windowDays }}d)</th>
                        <th>Sell-Through Rate</th>
                        <th>{{ $horizonDays }}d Forecast Demand</th>
                        <th>Surplus Units</th>
                        <th>Days of Cover</th>
                        <th>Classification</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($evaluations as $row)
                        <tr>
                            <td>
                                <div class="product-title">{{ $row['product']['name'] ?? 'Product' }}</div>
                                <div class="sku-code">{{ $row['product']['sku'] ?? '' }}</div>
                            </td>
                            <td>{{ number_format((float) ($row['available_stock'] ?? 0), 0) }}</td>
                            <td>{{ number_format((float) ($row['total_sold'] ?? 0), 0) }}</td>
                            <td>
                                {{ number_format(((float) ($row['sell_through_rate'] ?? 0)) * 100, 1) }}%
                                <div class="text-muted">Threshold: {{ number_format(((float) ($row['slow_moving_threshold'] ?? 0.20)) * 100, 0) }}%</div>
                            </td>
                            <td>{{ number_format((float) ($row['forecast_demand_horizon'] ?? 0), 1) }}</td>
                            <td>
                                @if(($row['overstock_units'] ?? 0) > 0)
                                    <span class="text-surplus">+{{ number_format((float) $row['overstock_units'], 1) }}</span>
                                @else
                                    <span class="text-muted">0</span>
                                @endif
                            </td>
                            <td>
                                @if(($row['days_of_cover'] ?? null) !== null)
                                    {{ number_format((float) $row['days_of_cover'], 1) }} days
                                @else
                                    <span class="text-muted">&infin;</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty($row['is_overstock']) && !empty($row['is_slow_moving']))
                                    <span class="badge badge-danger">Overstock + Slow-Moving</span>
                                @elseif(!empty($row['is_overstock']))
                                    <span class="badge badge-danger">Overstock Alert</span>
                                @elseif(!empty($row['is_slow_moving']))
                                    <span class="badge badge-warning">Slow-Moving</span>
                                @else
                                    <span class="badge badge-healthy">Healthy Turnover</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty-state">No products matched the selected filter criteria.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>

    @include('partials.footer')
</body>
</html>

