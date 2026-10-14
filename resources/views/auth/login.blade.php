<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login — IntelliShop</title>    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="{{ asset('css/login.css') }}">

    <!-- Axios Client & Login Module -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('js/login.js') }}" defer></script>
</head>
<body>
    <div class="ambient-bg">
        <div class="orb-1"></div>
        <div class="orb-2"></div>
    </div>

    <div class="login-container">
        <div class="login-card">
            <header class="brand-header">
                <h1 class="sr-only">IntelliShop</h1>
                <div class="logo-sticker">
                    <img src="{{ asset('images/logo.jpg') }}" alt="IntelliShop" class="brand-logo-img">
                </div>
            </header>

            <div id="alert-box" class="alert-box alert-danger" role="alert"></div>

            <form id="login-form" action="{{ route('login.post') }}" method="POST" novalidate>
                @csrf
                
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-wrapper">
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input" 
                            placeholder="admin@intellishop.com" 
                            required 
                            autocomplete="email"
                            value="{{ old('email') }}"
                        >
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                            </svg>
                        </span>
                    </div>
                    <div id="email-error" class="field-error"></div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input" 
                            placeholder="••••••••••••" 
                            required 
                            autocomplete="current-password"
                        >
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </span>
                    </div>
                    <div id="password-error" class="field-error"></div>
                </div>

                <div class="options-row">
                    <label class="remember-checkbox">
                        <input type="checkbox" id="remember" name="remember" value="1">
                        <span>Remember session</span>
                    </label>
                </div>

                <button type="submit" id="login-btn" class="btn-submit">
                    <span id="btn-text">Sign In</span>
                    <div id="btn-spinner" class="spinner"></div>
                </button>
            </form>
        </div>
    </div>


</body>
</html>
