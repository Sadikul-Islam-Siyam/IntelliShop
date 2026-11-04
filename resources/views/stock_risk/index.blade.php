<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Stock-Out Risk Classification — IntelliShop</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/favicon-192x192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon-192x192.png') }}">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
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
                    <strong>{{ $user->name ?? 'User' }}</strong> ({{ $user->role->name ?? 'User' }})
                </span>
                <a href="{{ route('forecasts.index') }}" class="btn-nav">Demand Forecasts</a>
                <a href="{{ route('reorder.index') }}" class="btn-nav">Reorder Calculations</a>
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
                <h1 class="page-title">
                    Stock-Out Risk Classification
                    <span class="tag-badge">{{ $branch?->name ?? 'Main Branch' }}</span>
                </h1>
                <p class="page-subtitle">Continuous timeline projection identifying Expected Stock-Out Dates (ESD) & supplier replenishment exposure.</p>
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
                <a href="{{ route('reorder.index', ['branch_id' => $selectedBranchId]) }}" class="btn-action">
                    View Full ROP &amp; Constraint Breakdown &rarr;
                </a>
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
                        <th>Safety Stock (SS)</th>
                        <th>Reorder Point (ROP)</th>
                        <th>Predicted Stock-Out (ESD)</th>
                        <th>Forecasted Demand</th>
                        <th>Reorder Qty</th>
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
                            <td>{{ $pred->safety_stock !== null ? number_format((float) $pred->safety_stock, 2) : '—' }}</td>
                            <td>{{ $pred->reorder_point !== null ? number_format((float) $pred->reorder_point, 2) : '—' }}</td>
                            <td>
                                @if($pred->predicted_stockout_date)
                                    <strong>{{ \Carbon\Carbon::parse($pred->predicted_stockout_date)->format('M d, Y') }}</strong>
                                @else
                                    <span style="color: var(--text-muted);">&gt; Horizon (Safe)</span>
                                @endif
                            </td>
                            <td>{{ number_format((float) $pred->predicted_demand, 1) }}</td>
                            <td><strong>{{ number_format((int) ($pred->recommended_reorder_qty ?? 0)) }} units</strong></td>
                            <td>
                                <span class="badge-risk badge-{{ $pred->risk_level }}">
                                    {{ $pred->risk_level }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                No products found for this branch. Projections auto-populate automatically on catalog activity.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-brand">
                <strong>IntelliShop</strong> &mdash; Intelligent Super Shop Management &amp; Decision Support System.
            </div>
            <div class="footer-meta">
                <span>&copy; {{ date('Y') }} IntelliShop. All rights reserved.</span>
            </div>
        </div>
    </footer>

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
