<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IntelliShop — Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            <h2>Welcome to IntelliShop, {{ $user->name ?? 'User' }}!</h2>
            <p class="welcome-subtitle">
                Account Status: <span class="status-active">{{ ucfirst($user->status ?? 'active') }}</span>
                | Role: <strong>{{ $user->role->name ?? 'None' }}</strong>
            </p>
        </div>
    </main>
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-brand">
                <strong>IntelliShop</strong> &mdash;Intelligent Super Shop Management & Decision Support System.
            </div>
            <div class="footer-meta">
                <span>&copy; {{ date('Y') }} IntelliShop. All rights reserved.</span>
            </div>
        </div>
    </footer>
</body>
</html>
