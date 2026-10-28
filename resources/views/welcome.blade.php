<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>IntelliShop</title>

        <!-- Favicon -->
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/favicon-192x192.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/favicon-192x192.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">

        <!-- Styles -->
        <link rel="stylesheet" href="{{ asset('css/welcome.css') }}">
    </head>
    <body class="bg-[#F8FAFC] dark:bg-[#0B1120] text-[#0F172A] dark:text-[#F8FAFC] flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col font-sans">
        <header class="w-full lg:max-w-4xl max-w-[335px] text-sm mb-6">
            <nav class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <img src="{{ asset('images/logo.png') }}" alt="IntelliShop" class="w-9 h-9 rounded-md object-contain border border-slate-200 shadow-sm">
                    <span class="font-bold text-base tracking-tight text-blue-600 dark:text-blue-400">IntelliShop</span>
                </div>
                <div>
                    @auth
                        <a
                            href="{{ url('/dashboard') }}"
                            class="inline-block px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md text-sm transition"
                        >
                            Go to Dashboard
                        </a>
                    @else
                        <a
                            href="{{ route('login') }}"
                            class="inline-block px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md text-sm shadow-sm transition"
                        >
                            Sign In to Portal
                        </a>
                    @endauth
                </div>
            </nav>
        </header>

        <div class="flex items-center justify-center w-full transition-opacity opacity-100 duration-750 lg:grow">
            <main class="flex max-w-[335px] w-full flex-col-reverse lg:max-w-4xl lg:flex-row bg-white dark:bg-[#1E293B] rounded-xl shadow-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="flex-1 p-8 lg:p-14 flex flex-col justify-between">
                    <div>
                        <span class="inline-block px-3 py-1 text-xs font-semibold uppercase tracking-wider text-blue-700 bg-blue-50 dark:bg-blue-900/40 dark:text-blue-300 rounded-full mb-4">
                            Enterprise POS & Retail Platform
                        </span>
                        <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-4">
                            Smart Retail & Multi-Branch Control
                        </h1>
                        <p class="text-slate-600 dark:text-slate-300 text-base leading-relaxed mb-6">
                            IntelliShop powers centralized inventory tracking, role-based workflows, seamless cashier checkouts, and comprehensive auditing across all branch outlets.
                        </p>

                        <div class="space-y-3 mb-8">
                            <div class="flex items-center gap-3 text-sm text-slate-700 dark:text-slate-200">
                                <span class="w-2 h-2 rounded-full bg-blue-500 shrink-0"></span>
                                <span>Multi-tier role routing (Admin, Manager, Cashier, Analyst)</span>
                            </div>
                            <div class="flex items-center gap-3 text-sm text-slate-700 dark:text-slate-200">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>Real-time multi-branch stock synchronization</span>
                            </div>
                            <div class="flex items-center gap-3 text-sm text-slate-700 dark:text-slate-200">
                                <span class="w-2 h-2 rounded-full bg-indigo-500 shrink-0"></span>
                                <span>Automated audit security logging & rate limiting</span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-6 border-t border-slate-100 dark:border-slate-700 flex flex-wrap items-center justify-between gap-4">
                        <a
                            href="{{ route('login') }}"
                            class="inline-flex items-center justify-center px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg text-sm transition shadow-sm"
                        >
                            Access Workspace &rarr;
                        </a>
                        <span class="text-xs text-slate-400">
                            IntelliShop v1.0 &bull; Local Development
                        </span>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-blue-50 via-indigo-50 to-slate-100 dark:from-slate-800 dark:to-slate-900 p-8 lg:p-12 flex flex-col items-center justify-center border-b lg:border-b-0 lg:border-l border-slate-200 dark:border-slate-800 lg:w-[420px] shrink-0">
                    <div class="relative group">
                        <div class="absolute -inset-1 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-2xl blur opacity-25 group-hover:opacity-50 transition duration-1000"></div>
                        <div class="relative bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 text-center">
                            <img
                                src="{{ asset('images/logo.png') }}"
                                alt="IntelliShop Logo"
                                class="max-w-[260px] h-auto object-contain mx-auto rounded-lg"
                            >
                            <h2 class="mt-4 font-bold text-lg text-slate-900 dark:text-white">
                                IntelliShop
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                Secure Intelligent Point of Sale
                            </p>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <footer class="mt-8 text-center text-xs text-slate-400">
            &copy; {{ date('Y') }} IntelliShop. All rights reserved.
        </footer>
    </body>
</html>
