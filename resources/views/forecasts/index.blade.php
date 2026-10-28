<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Upcoming Demand Forecast — IntelliShop</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="{{ asset('css/forecasts.css') }}">
</head>
<body>
    <header>
        <nav class="navbar">
            <a href="/dashboard" class="brand">
                <div class="navbar-logo-sticker">
                    <img src="{{ asset('images/logo.jpg') }}" alt="IntelliShop" class="navbar-logo">
                </div>
                <span class="brand-name">IntelliShop</span>
            </a>
            <div class="nav-actions">
                <span class="nav-user-info">
                    <strong>{{ $user->name ?? 'Manager' }}</strong> ({{ $user->role->name ?? 'Manager' }})
                </span>
                <a href="/dashboard" class="btn-nav">&larr; Dashboard</a>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-logout">Logout</button>
                </form>
            </div>
        </nav>
    </header>

    <main class="page-container">
        <div class="page-header">
            <div>
                <h1>
                    Upcoming Demand Forecast
                    <span class="tag-badge">{{ $branch?->name ?? 'Main Branch' }}</span>
                </h1>
                <p class="page-subtitle">
                    Required product quantities (in units) for the upcoming {{ $horizonDays }} days.
                </p>
            </div>
        </div>

        @php
            $totalBranchUnits = collect($productForecasts)->sum('total_horizon_units');
            $totalNext7Units = collect($productForecasts)->sum('next_7_days_units');
            $totalTomorrowUnits = collect($productForecasts)->sum('next_day_units');
            $datesHeader = $productForecasts[0]['daily_schedule'] ?? [];
        @endphp

        <!-- Summary KPI Cards -->
        <section class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Tomorrow's Required Units</div>
                <div class="kpi-value">{{ $totalTomorrowUnits }} units</div>
                <div class="kpi-sub">Across all active products</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Next 7 Days Requirement</div>
                <div class="kpi-value">{{ $totalNext7Units }} units</div>
                <div class="kpi-sub">Total weekly demand</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Next {{ $horizonDays }} Days Requirement</div>
                <div class="kpi-value">{{ $totalBranchUnits }} units</div>
                <div class="kpi-sub">Full upcoming horizon</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Forecasted Products</div>
                <div class="kpi-value">{{ count($productForecasts) }} items</div>
                <div class="kpi-sub">Active catalog items</div>
            </div>
        </section>

        <!-- Product Summary Table -->
        <section class="card-panel">
            <div class="card-panel-header">
                <div class="card-panel-title">Required Quantity by Product (Upcoming {{ $horizonDays }} Days)</div>
                <span class="pill pill-selected">Units Required</span>
            </div>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Tomorrow</th>
                            <th>Next 7 Days</th>
                            <th>Next {{ $horizonDays }} Days Total</th>
                            <th>Daily Average</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($productForecasts as $item)
                            <tr>
                                <td style="font-weight: 700;">{{ $item['product_name'] }}</td>
                                <td><span class="pill pill-baseline">{{ $item['sku'] }}</span></td>
                                <td style="font-weight: 600;">{{ $item['next_day_units'] }} units</td>
                                <td style="font-weight: 700; color: var(--accent);">{{ $item['next_7_days_units'] }} units</td>
                                <td style="font-weight: 800; color: var(--success); font-size: 0.95rem;">{{ $item['total_horizon_units'] }} units</td>
                                <td>{{ $item['avg_daily_units'] }} units / day</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="text-align: center; color: #64748b;">No products available.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Day-by-Day Required Units Breakdown -->
        @if(!empty($datesHeader))
            <section class="card-panel">
                <div class="card-panel-header">
                    <div class="card-panel-title">Day-by-Day Required Demand Schedule (Units)</div>
                    <span class="pill pill-baseline">Upcoming {{ count($datesHeader) }} Days</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="min-width: 190px;">Product</th>
                                @foreach($datesHeader as $dayCol)
                                    <th style="text-align: center; white-space: nowrap;">{{ $dayCol['day'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($productForecasts as $item)
                                <tr>
                                    <td style="font-weight: 700;">
                                        {{ $item['product_name'] }}
                                        <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500;">{{ $item['sku'] }}</div>
                                    </td>
                                    @foreach($item['daily_schedule'] as $daySlot)
                                        <td style="text-align: center; font-weight: 700; color: var(--success);">
                                            {{ $daySlot['units'] }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </main>

    <footer class="dashboard-footer">
        <div class="footer-container">
            <div>
                <strong>IntelliShop</strong> &mdash; Intelligent Super Shop Management &amp; Decision Support System.
            </div>
            <div>
                <span>&copy; {{ date('Y') }} IntelliShop. All rights reserved.</span>
            </div>
        </div>
    </footer>
</body>
</html>
