<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>IntelliShop — {{ $roleConfig['title'] ?? 'Dashboard' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/favicon-192x192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon-192x192.png') }}">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
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
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-logout">Logout</button>
                </form>
            </div>
        </nav>
    </header>
    <main class="main-content">
        <div class="card">
            <div class="workspace-header">
                <div>
                    <h2>Welcome to IntelliShop, {{ $user->name ?? 'User' }}!</h2>
                    <p class="welcome-subtitle">
                        Account Status: <span class="status-active">{{ ucfirst($user->status ?? 'active') }}</span>
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                    <a href="{{ route('suppliers.index') }}" class="workspace-badge" style="text-decoration: none; background: #e0f2fe; color: #0369a1; font-weight: 700; border: 1px solid #bae6fd;">
                        Suppliers &rarr;
                    </a>
                    <a href="{{ route('reorder.index') }}" class="workspace-badge" style="text-decoration: none; background: #e0e7ff; color: #4338ca; font-weight: 700; border: 1px solid #c7d2fe;">
                        Replenishment &rarr;
                    </a>
                    <a href="{{ route('stock-risk.index') }}" class="workspace-badge" style="text-decoration: none; background: #fee2e2; color: #b91c1c; font-weight: 700; border: 1px solid #fecaca;">
                        Stock-Out Risk &rarr;
                    </a>
                    <a href="{{ route('forecasts.index') }}" class="workspace-badge" style="text-decoration: none; background: #f1f5f9; color: #334155; font-weight: 700; border: 1px solid #e2e8f0;">
                        Demand Forecasts &rarr;
                    </a>
                    <a href="{{ route('reports.index') }}" class="workspace-badge" style="text-decoration: none; background: #dcfce7; color: #166534; font-weight: 700; border: 1px solid #bbf7d0;">
                        Reports &amp; Charts &rarr;
                    </a>
                    <a href="{{ route('recommendations.index') }}" class="workspace-badge" style="text-decoration: none; background: #fef3c7; color: #92400e; font-weight: 700; border: 1px solid #fde68a;">
                        Recommendations &rarr;
                    </a>
                    <a href="{{ route('anomalies.index') }}" class="workspace-badge" style="text-decoration: none; background: #fae8ff; color: #86198f; font-weight: 700; border: 1px solid #f5d0fe;">
                        Anomalies &rarr;
                    </a>
                    <a href="{{ route('market-basket.index') }}" class="workspace-badge" style="text-decoration: none; background: #ffedd5; color: #9a3412; font-weight: 700; border: 1px solid #fed7aa;">
                        Market Basket &rarr;
                    </a>
                    <a href="{{ route('nlq.index') }}" class="workspace-badge" style="text-decoration: none; background: #f0fdf4; color: #15803d; font-weight: 700; border: 1px solid #bbf7d0;">
                        NLQ Query &rarr;
                    </a>
                </div>
            </div>
        </div>

        @if(!empty($widgets))
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                @foreach($widgets as $widget)
                    <div style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            {{ $widget['label'] }}
                        </div>
                        <div style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin-top: 0.5rem;">
                            @if(($widget['format'] ?? '') === 'currency')
                                ${{ number_format($widget['value'], 2) }}
                            @else
                                {{ number_format($widget['value']) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if(isset($roleConfig))
            <div class="card">
                <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--text-primary);">
                    {{ $roleConfig['title'] }}
                </h3>
                <p class="workspace-desc">{{ $roleConfig['description'] }}</p>

                <div class="dashboard-section-title">
                    Operational Modules & Quick Actions
                </div>

                <div class="modules-grid">
                    @foreach($roleConfig['modules'] as $module)
                        <a href="{{ $module['route'] ?? '#' }}" class="module-card">
                            <div class="module-title">{{ $module['name'] }}</div>
                            <div class="module-desc">{{ $module['description'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @else
            <div class="card">
                <div class="dashboard-section-title">
                    Operational Modules & Quick Actions
                </div>

                <div class="modules-grid">
                    <a href="{{ route('suppliers.index') }}" class="module-card">
                        <div class="module-title">Suppliers</div>
                        <div class="module-desc">Manage supplier profiles, contact credentials, product terms, and performance evaluations.</div>
                    </a>
                    <a href="{{ route('reorder.index') }}" class="module-card">
                        <div class="module-title">Dynamic Replenishment</div>
                        <div class="module-desc">Automated Safety Stock (SS), Reorder Point (ROP), and constraint-aware replenishment advice.</div>
                    </a>
                    <a href="{{ route('stock-risk.index') }}" class="module-card">
                        <div class="module-title">Stock-Out Risk</div>
                        <div class="module-desc">Timeline days-of-cover projections and supplier risk classifications.</div>
                    </a>
                    <a href="{{ route('forecasts.index') }}" class="module-card">
                        <div class="module-title">Demand Forecasting</div>
                        <div class="module-desc">Statistical demand forecasting pipeline, backtest metrics, and prediction intervals.</div>
                    </a>
                </div>
            </div>
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

    <!-- Axios and dashboard JavaScript module -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('js/dashboard.js') }}"></script>
</body>
</html>
