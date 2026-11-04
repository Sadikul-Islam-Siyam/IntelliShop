<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Stock-Out Risk Classification — IntelliShop</title>

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('css/stock-risk.css') }}">
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
                    <strong>{{ $user->name ?? 'Analyst' }}</strong> ({{ $user->role->name ?? 'System Analyst' }})
                </span>
                <a href="/forecasting" class="btn-nav">Demand Forecasts</a>
                <a href="/dashboard" class="btn-nav">&larr; Dashboard</a>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-logout">Logout</button>
                </form>
            </div>
        </nav>
    </header>

    <div class="container">
        <div id="statusAlert" class="alert-box"></div>

        <div class="page-header">
            <div>
                <h1 class="page-title">Stock-Out Risk Classification</h1>
                <p class="page-subtitle">Continuous timeline projection identifying Expected Stock-Out Dates (ESD) & supplier replenishment exposure.</p>
            </div>
        </div>

        <div class="kpi-row">
            <div class="kpi-card high">
                <div class="kpi-label">High Risk (ESD &le; Today + L)</div>
                <div class="kpi-val">{{ $highCount }} items</div>
            </div>
            <div class="kpi-card medium">
                <div class="kpi-label">Medium Risk (ESD &le; Today + L + R)</div>
                <div class="kpi-val">{{ $mediumCount }} items</div>
            </div>
            <div class="kpi-card low">
                <div class="kpi-label">Low Risk (Safe Buffer)</div>
                <div class="kpi-val">{{ $lowCount }} items</div>
            </div>
        </div>

        <div class="filter-card">
            <form method="GET" action="{{ route('stock-risk.index') }}" class="filter-group">
                <span class="filter-label">Branch:</span>
                <select name="branch_id" class="select-input" onchange="this.form.submit()">
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ $b->id == $selectedBranchId ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>

                <span class="filter-label" style="margin-left: 0.5rem;">Risk Filter:</span>
                <select name="risk_level" class="select-input" onchange="this.form.submit()">
                    <option value="">All Risk Levels</option>
                    <option value="HIGH" {{ $selectedRisk == 'HIGH' ? 'selected' : '' }}>High Risk</option>
                    <option value="MEDIUM" {{ $selectedRisk == 'MEDIUM' ? 'selected' : '' }}>Medium Risk</option>
                    <option value="LOW" {{ $selectedRisk == 'LOW' ? 'selected' : '' }}>Low Risk</option>
                </select>
            </form>

            <div class="filter-group">
                <span style="font-size: 0.8rem; color: var(--success); font-weight: 700; display: flex; align-items: center; gap: 0.35rem;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: var(--success); display: inline-block;"></span>
                    Automatic Sync: Active
                </span>
                <button type="button" class="btn-action" onclick="refreshAllAssessments()" style="background: #4f46e5;">
                    Refresh All
                </button>
            </div>
        </div>

        <div class="data-table-card">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Current Stock</th>
                        <th>Supplier Lead Time (L)</th>
                        <th>Predicted Stock-Out (ESD)</th>
                        <th>Forecasted Demand</th>
                        <th>Risk Level</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($predictions as $pred)
                        <tr>
                            <td><strong>{{ $pred->product?->name ?? 'Unknown' }}</strong></td>
                            <td><code>{{ $pred->product?->sku ?? '—' }}</code></td>
                            <td>{{ number_format((float) $pred->current_stock, 0) }} units</td>
                            <td>{{ $pred->lead_time_days ?? 7 }} days</td>
                            <td>
                                @if($pred->predicted_stockout_date)
                                    <strong>{{ \Carbon\Carbon::parse($pred->predicted_stockout_date)->format('M d, Y') }}</strong>
                                @else
                                    <span style="color: var(--text-muted);">&gt; Horizon (Safe)</span>
                                @endif
                            </td>
                            <td>{{ number_format((float) $pred->predicted_demand, 1) }}</td>
                            <td>
                                <span class="badge-risk badge-{{ $pred->risk_level }}">
                                    {{ $pred->risk_level }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                No products found for this branch. Projections auto-populate automatically on catalog activity.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        async function refreshAllAssessments() {
            const alertBox = document.getElementById('statusAlert');
            alertBox.style.display = 'block';
            alertBox.className = 'alert-box alert-success';
            alertBox.innerText = 'Synchronizing real-time timeline projections for all branch products...';
            setTimeout(() => window.location.reload(), 600);
        }
    </script>
</body>
</html>
