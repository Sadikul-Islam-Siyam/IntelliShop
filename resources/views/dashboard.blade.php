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
    @include('partials.navbar')
    <main class="main-content">
        <div class="card">
            <div class="workspace-header">
                <div>
                    <h2>Welcome back, {{ $user->name ?? 'User' }}</h2>
                    <p class="welcome-subtitle">
                        {{ $user->role->name ?? 'User' }} Workspace &bull; Status: <span class="status-active">{{ ucfirst($user->status ?? 'active') }}</span>
                    </p>
                </div>
                <span class="workspace-badge">{{ $roleConfig['badge'] ?? ($user->role->name ?? 'Workspace') }}</span>
            </div>
        </div>

        @if(!empty($widgets))
            @php $tones = ['blue', 'emerald', 'indigo', 'amber']; @endphp
            <div class="is-kpi-grid">
                @foreach($widgets as $idx => $widget)
                    <div class="is-kpi-card" data-tone="{{ $tones[$idx % count($tones)] }}">
                        <div class="is-kpi-label">{{ $widget['label'] }}</div>
                        <div class="is-kpi-value">
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
    @include('partials.footer')

    <!-- Axios and dashboard JavaScript module -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('js/dashboard.js') }}"></script>
</body>
</html>
