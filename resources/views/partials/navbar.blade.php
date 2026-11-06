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
                <strong>{{ $user->name ?? auth()->user()?->name ?? 'Manager' }}</strong> ({{ $user->role->name ?? auth()->user()?->role?->name ?? 'Manager' }})
            </span>
            <a href="/dashboard" class="btn-nav">&larr; Dashboard</a>
            <form action="{{ route('logout') }}" method="POST">
                @csrf
                <button type="submit" class="btn-logout">Logout</button>
            </form>
        </div>
    </nav>
</header>

