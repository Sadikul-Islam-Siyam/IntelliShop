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
                @if(isset($roleConfig['badge']))
                    <span class="workspace-badge">{{ $roleConfig['badge'] }}</span>
                @endif
            </div>
            <p class="welcome-subtitle">
                Account Status: <span class="status-active">{{ ucfirst($user->status ?? 'active') }}</span>
                | Role: <strong>{{ $user->role->name ?? 'None' }}</strong>
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

                <div class="live-sync-banner">
                    <span><span class="live-sync-dot"></span> <span id="sync-status">REST API Session: Connected (/api/v1/dashboard)</span></span>
                    <span id="sync-role">{{ $roleConfig['role_name'] }} Workspace</span>
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
