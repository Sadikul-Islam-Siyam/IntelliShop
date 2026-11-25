@php
    $navUser = $user ?? auth()->user();
    $navGroups = \App\Support\NavigationBuilder::sidebarGroups($navUser);
    $breadcrumbs = \App\Support\NavigationBuilder::breadcrumbs($navUser);
    $dashboardHref = \App\Support\NavigationBuilder::dashboardUrl($navUser);
    $userInitial = strtoupper(substr((string) ($navUser->name ?? 'U'), 0, 1));
@endphp

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/intellishop-ui.css') }}">
<script>
    (function(){try{if(window.innerWidth>1024&&localStorage.getItem('intellishop.sidebarCollapsed')==='1'){document.documentElement.classList.add('is-sidebar-collapsed');}}catch(e){}})();
</script>

<div id="is-progress-bar" class="is-progress-bar" aria-hidden="true"></div>
<div id="is-sidebar-backdrop" class="is-sidebar-backdrop" aria-hidden="true"></div>

<div class="is-shell">
    <aside id="is-sidebar" class="is-sidebar" aria-label="Primary Navigation">
        <a href="{{ $dashboardHref }}" class="is-sidebar-brand">
            <span class="is-sidebar-logo-box">
                <img src="{{ asset('images/logo.jpg') }}" alt="IntelliShop" class="is-sidebar-logo">
            </span>
            <span class="is-sidebar-title">IntelliShop</span>
        </a>

        <nav class="is-sidebar-scroll">
            @foreach($navGroups as $group)
                <div class="is-nav-group">
                    <div class="is-nav-group-label">{{ $group['group'] }}</div>
                    @foreach($group['items'] as $item)
                        <a
                            href="{{ $item['href'] }}"
                            class="is-nav-link {{ $item['active'] ? 'is-active' : '' }}"
                            @if($item['active']) aria-current="page" @endif
                        >
                            @switch($item['icon'])
                                @case('grid')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                                    @break
                                @case('building')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                                    @break
                                @case('users')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    @break
                                @case('tag')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                                    @break
                                @case('truck')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                    @break
                                @case('award')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                                    @break
                                @case('trending')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                                    @break
                                @case('alert')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    @break
                                @case('refresh')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                    @break
                                @case('layers')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                                    @break
                                @case('bar-chart')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                                    @break
                                @case('sparkles')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                    @break
                                @case('activity')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                    @break
                                @case('shopping-bag')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                                    @break
                                @case('search')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    @break
                                @case('shield')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                    @break
                                @case('sliders')
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/></svg>
                                    @break
                                @default
                                    <svg class="is-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/></svg>
                            @endswitch
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div class="is-sidebar-footer">
            <span>{{ $navUser?->role?->name ?? 'User' }}</span>
            <span style="color: #34d399; font-weight: 600;">&bull; Online</span>
        </div>
    </aside>

    <div class="is-main-wrap">
        <header class="is-topbar">
            <div class="is-topbar-left">
                <button
                    type="button"
                    id="is-menu-toggle"
                    class="is-menu-toggle"
                    title="Toggle sidebar (Ctrl+B)"
                    aria-label="Toggle navigation sidebar"
                    aria-expanded="true"
                    aria-controls="is-sidebar"
                >
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>

                <nav class="is-breadcrumbs" aria-label="Breadcrumb">
                    @foreach($breadcrumbs as $idx => $crumb)
                        @if($idx > 0)
                            <span class="is-breadcrumb-sep" aria-hidden="true">/</span>
                        @endif
                        @if(!empty($crumb['href']))
                            <a href="{{ $crumb['href'] }}">{{ $crumb['label'] }}</a>
                        @else
                            <span class="{{ $idx === count($breadcrumbs) - 1 ? 'is-breadcrumb-current' : '' }}">{{ $crumb['label'] }}</span>
                        @endif
                    @endforeach
                </nav>
            </div>

            <div class="is-topbar-right">
                <div class="is-user-chip">
                    <span class="is-user-avatar" aria-hidden="true">{{ $userInitial }}</span>
                    <strong>{{ $navUser->name ?? 'User' }}</strong>
                    <span class="is-user-role">({{ $navUser->role->name ?? 'User' }})</span>
                </div>
                <form action="{{ route('logout') }}" method="POST" style="margin: 0;">
                    @csrf
                    <button type="submit" class="is-btn-logout">Logout</button>
                </form>
            </div>
        </header>

        <div class="is-page-body">
