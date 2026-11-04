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

    <style>
        :root {
            --bg-canvas: #f8fafc;
            --bg-card: #ffffff;
            --border-subtle: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --primary: #4338ca;
            --primary-hover: #3730a3;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --warning: #d97706;
            --warning-bg: #fffbeb;
            --warning-border: #fde68a;
            --success: #16a34a;
            --success-bg: #f0fdf4;
            --success-border: #bbf7d0;
            --radius-md: 10px;
            --radius-lg: 14px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--bg-canvas);
            color: var(--text-main);
            min-height: 100vh;
            padding-bottom: 3rem;
        }

        .navbar {
            background: #ffffff;
            border-bottom: 1px solid var(--border-subtle);
            padding: 0.85rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-main);
            font-weight: 800;
            font-size: 1.15rem;
        }
        .navbar-logo-sticker {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
        }
        .navbar-logo { width: 100%; height: 100%; object-fit: cover; }
        .nav-actions { display: flex; align-items: center; gap: 1rem; }
        .nav-user-info { font-size: 0.85rem; color: var(--text-muted); }
        .btn-nav {
            font-size: 0.85rem;
            padding: 0.45rem 0.9rem;
            background: #f1f5f9;
            color: var(--text-main);
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.15s ease;
        }
        .btn-nav:hover { background: #e2e8f0; }
        .btn-logout {
            font-size: 0.85rem;
            padding: 0.45rem 0.9rem;
            background: #fee2e2;
            color: #b91c1c;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }

        .container {
            max-width: 1240px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .page-title { font-size: 1.6rem; font-weight: 800; color: var(--text-main); }
        .page-subtitle { font-size: 0.9rem; color: var(--text-muted); margin-top: 0.25rem; }

        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .kpi-card.high { border-left: 4px solid var(--danger); }
        .kpi-card.medium { border-left: 4px solid var(--warning); }
        .kpi-card.low { border-left: 4px solid var(--success); }
        .kpi-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; color: var(--text-muted); }
        .kpi-val { font-size: 1.8rem; font-weight: 800; margin-top: 0.35rem; color: var(--text-main); }

        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .filter-group { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .filter-label { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); }
        .select-input {
            padding: 0.45rem 0.85rem;
            font-size: 0.85rem;
            border: 1px solid var(--border-subtle);
            border-radius: 6px;
            font-family: inherit;
            background: #fff;
        }
        .btn-action {
            padding: 0.55rem 1.15rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-action:hover { background: var(--primary-hover); }

        .data-table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.85rem;
        }
        th {
            background: #f8fafc;
            padding: 0.85rem 1.15rem;
            font-weight: 700;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-subtle);
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.05em;
        }
        td {
            padding: 0.95rem 1.15rem;
            border-bottom: 1px solid var(--border-subtle);
            color: var(--text-main);
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }

        .badge-risk {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .badge-HIGH { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); }
        .badge-MEDIUM { background: var(--warning-bg); color: var(--warning); border: 1px solid var(--warning-border); }
        .badge-LOW { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }

        .btn-run-item {
            padding: 0.35rem 0.75rem;
            background: #f1f5f9;
            color: var(--text-main);
            border: 1px solid var(--border-subtle);
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-run-item:hover { background: #e2e8f0; }

        .alert-box {
            padding: 0.85rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: 0.85rem;
            display: none;
        }
        .alert-success { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
        .alert-error { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); }
    </style>
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
                <select id="quickRunProductId" class="select-input">
                    @foreach($products as $p)
                        <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>
                    @endforeach
                </select>
                <button type="button" class="btn-action" onclick="triggerRiskAssessment()">Run Risk Assessment</button>
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
                        <th>Action</th>
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
                            <td>
                                <button type="button" class="btn-run-item" onclick="triggerRiskAssessment({{ $pred->product_id }})">
                                    Re-assess
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                No stock predictions found for this branch. Select a product and click <strong>Run Risk Assessment</strong> to generate live projections.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        async function triggerRiskAssessment(specificProductId = null) {
            const branchId = {{ $selectedBranchId }};
            const productId = specificProductId || document.getElementById('quickRunProductId').value;
            const alertBox = document.getElementById('statusAlert');

            alertBox.style.display = 'block';
            alertBox.className = 'alert-box';
            alertBox.innerText = 'Calculating stock projection and risk classification...';

            try {
                const response = await fetch('/api/v1/stock-risk/run', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({
                        product_id: parseInt(productId),
                        branch_id: parseInt(branchId)
                    })
                });

                const data = await response.json();

                if (response.ok) {
                    alertBox.className = 'alert-box alert-success';
                    alertBox.innerText = `Success: Risk level for ${data.data.product.name} is ${data.data.prediction.risk_level}. Refreshing...`;
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    alertBox.className = 'alert-box alert-error';
                    alertBox.innerText = data.error?.message || 'Failed to execute stock-out risk assessment.';
                }
            } catch (err) {
                alertBox.className = 'alert-box alert-error';
                alertBox.innerText = 'Network error while triggering stock risk run.';
            }
        }
    </script>
</body>
</html>
