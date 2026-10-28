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
                <h2>Welcome to IntelliShop, {{ $user->name ?? 'User' }}!</h2>
            </div>
            <p class="welcome-subtitle">
                Account Status: <span class="status-active">{{ ucfirst($user->status ?? 'active') }}</span>
            </p>
        </div>

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
        @endif

        @if(!empty($upcomingDemand))
            <div class="card">
                <div class="workspace-header" style="margin-bottom: 1rem;">
                    <div>
                        <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary);">
                            Upcoming Demand Requirements
                        </h3>
                        <p class="welcome-subtitle" style="margin-top: 0.2rem; font-size: 0.875rem;">
                            Required product quantities (in units) for the upcoming days.
                        </p>
                    </div>
                    <a href="{{ route('forecasts.index') }}" class="workspace-badge" style="text-decoration: none;">
                        View Daily Breakdown &rarr;
                    </a>
                </div>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: #f8fafc; text-align: left;">
                                <th style="padding: 0.75rem 0.9rem; font-size: 0.76rem; text-transform: uppercase; color: var(--text-secondary);">Product</th>
                                <th style="padding: 0.75rem 0.9rem; font-size: 0.76rem; text-transform: uppercase; color: var(--text-secondary);">SKU</th>
                                <th style="padding: 0.75rem 0.9rem; font-size: 0.76rem; text-transform: uppercase; color: var(--text-secondary);">Tomorrow</th>
                                <th style="padding: 0.75rem 0.9rem; font-size: 0.76rem; text-transform: uppercase; color: var(--text-secondary);">Next 7 Days</th>
                                <th style="padding: 0.75rem 0.9rem; font-size: 0.76rem; text-transform: uppercase; color: var(--text-secondary);">Next {{ $upcomingDemand[0]['horizon_days'] ?? 14 }} Days Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($upcomingDemand as $item)
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 0.75rem 0.9rem; font-weight: 700; color: var(--text-primary);">{{ $item['product_name'] }}</td>
                                    <td style="padding: 0.75rem 0.9rem; color: var(--text-secondary);">{{ $item['sku'] }}</td>
                                    <td style="padding: 0.75rem 0.9rem; font-weight: 600;">{{ $item['next_day_units'] }} units</td>
                                    <td style="padding: 0.75rem 0.9rem; font-weight: 700; color: var(--accent);">{{ $item['next_7_days_units'] }} units</td>
                                    <td style="padding: 0.75rem 0.9rem; font-weight: 800; color: var(--success);">{{ $item['total_horizon_units'] }} units</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </main>
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-brand">
                <strong>IntelliShop</strong> &mdash; Intelligent Super Shop Management & Decision Support System.
            </div>
            <div class="footer-meta">
                <span>&copy; {{ date('Y') }} IntelliShop. All rights reserved.</span>
            </div>
        </div>
    </footer>

    <!-- Axios and dashboard JavaScript module -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('js/dashboard.js') }}"></script>
</body>
</html>
