<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>System Audit Logs — IntelliShop</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/favicon-192x192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon-192x192.png') }}">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="{{ asset('css/audit-logs.css') }}">
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
                    <strong>{{ $user->name ?? 'Administrator' }}</strong> ({{ $user->role->name ?? 'Admin' }})
                </span>
                <a href="/dashboard" class="btn-nav">
                    &larr; Dashboard
                </a>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-logout">Logout</button>
                </form>
            </div>
        </nav>
    </header>

    <main class="page-container">
        <div class="page-header">
            <div class="header-title-group">
                <h1>
                    System Audit Logs
                    <span class="security-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        Append-Only Ledger
                    </span>
                </h1>
                <p>Inspect immutable event logs, administrative actions, security violations, and authentication audits.</p>
            </div>
        </div>

        <!-- Filter Card -->
        <section class="filter-card">
            <form action="{{ route('audit-logs.index') }}" method="GET" class="filter-form" id="audit-filter-form">
                <div class="form-group">
                    <label for="filter-search">Search Keyword</label>
                    <input
                        type="text"
                        name="search"
                        id="filter-search"
                        class="form-control"
                        placeholder="Search action, IP, entity or payload..."
                        value="{{ $filters['search'] ?? '' }}"
                    >
                </div>

                <div class="form-group">
                    <label for="filter-action">Action Key</label>
                    <select name="action" id="filter-action" class="form-control">
                        <option value="">All Actions</option>
                        @foreach ($actions as $actionKey)
                            <option value="{{ $actionKey }}" {{ ($filters['action'] ?? '') === $actionKey ? 'selected' : '' }}>
                                {{ $actionKey }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label for="filter-entity">Entity Type</label>
                    <select name="entity_type" id="filter-entity" class="form-control">
                        <option value="">All Entities</option>
                        @foreach ($entityTypes as $type)
                            <option value="{{ $type }}" {{ ($filters['entity_type'] ?? '') === $type ? 'selected' : '' }}>
                                {{ ucfirst($type) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label for="filter-from">From Date</label>
                    <input
                        type="date"
                        name="from"
                        id="filter-from"
                        class="form-control"
                        value="{{ $filters['from'] ?? '' }}"
                    >
                </div>

                <div class="form-group">
                    <label for="filter-to">To Date</label>
                    <input
                        type="date"
                        name="to"
                        id="filter-to"
                        class="form-control"
                        value="{{ $filters['to'] ?? '' }}"
                    >
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Filter</button>
                    @if (!empty(array_filter($filters ?? [])))
                        <a href="{{ route('audit-logs.index') }}" class="btn-reset">Reset</a>
                    @endif
                </div>
            </form>
        </section>

        <!-- Audit Records Table Card -->
        <section class="table-card">
            <div class="table-responsive">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">ID</th>
                            <th style="width: 170px;">Timestamp</th>
                            <th>Actor / User</th>
                            <th>Action</th>
                            <th>Target Entity</th>
                            <th>IP Address</th>
                            <th style="text-align: right;">Details</th>
                        </tr>
                    </thead>
                    <tbody id="audit-table-body">
                        @forelse ($logs as $log)
                            @php
                                $action = $log->action;
                                $badgeClass = 'badge-neutral';
                                if (str_contains($action, 'login') || str_contains($action, 'create')) {
                                    $badgeClass = 'badge-success';
                                } elseif (str_contains($action, 'failed') || str_contains($action, 'lockout') || str_contains($action, 'deactivate')) {
                                    $badgeClass = 'badge-danger';
                                } elseif (str_contains($action, 'update') || str_contains($action, 'edit')) {
                                    $badgeClass = 'badge-warning';
                                } elseif (str_contains($action, 'view') || str_contains($action, 'read')) {
                                    $badgeClass = 'badge-info';
                                }
                            @endphp
                            <tr>
                                <td><span class="log-id">#{{ $log->id }}</span></td>
                                <td>
                                    <div class="log-time">
                                        <span class="time-relative">{{ $log->created_at->diffForHumans() }}</span>
                                        <span class="time-absolute">{{ $log->created_at->format('Y-m-d H:i:s') }}</span>
                                    </div>
                                </td>
                                <td>
                                    @if ($log->user)
                                        <div class="actor-chip">
                                            <span class="actor-name">{{ $log->user->name }}</span>
                                            <span class="actor-email">{{ $log->user->email }}</span>
                                        </div>
                                    @else
                                        <span class="system-actor">System / Guest</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge-action {{ $badgeClass }}">
                                        {{ $log->action }}
                                    </span>
                                </td>
                                <td>
                                    @if ($log->entity_type)
                                        <div class="entity-info">
                                            <span class="entity-type">{{ $log->entity_type }}</span>
                                            @if ($log->entity_id)
                                                <span class="entity-id">#{{ $log->entity_id }}</span>
                                            @endif
                                        </div>
                                    @else
                                        <span style="color: #94a3b8;">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="ip-pill">{{ $log->ip_address ?? '—' }}</span>
                                </td>
                                <td style="text-align: right;">
                                    @if ($log->details)
                                        <button
                                            type="button"
                                            class="btn-details"
                                            data-details="{{ is_array($log->details) ? json_encode($log->details, JSON_PRETTY_PRINT) : $log->details }}"
                                            data-action="{{ $log->action }}"
                                            data-id="{{ $log->id }}"
                                        >
                                            View Payload
                                        </button>
                                    @else
                                        <span style="color: #94a3b8; font-size: 0.8rem;">None</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <div class="empty-icon">&#128269;</div>
                                        <h3>No audit logs found</h3>
                                        <p>No recorded system events match your current filter criteria.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($logs->hasPages())
                <div class="pagination-wrapper">
                    <div>
                        Showing {{ $logs->firstItem() ?? 0 }} to {{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }} audit records
                    </div>
                    <div>
                        {{ $logs->appends($filters)->links() }}
                    </div>
                </div>
            @endif
        </section>
    </main>

    <!-- Details Modal -->
    <div id="details-modal" class="modal-backdrop">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="modal-title">Audit Record Payload</h3>
                <button type="button" class="btn-close-modal" id="btn-close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <pre class="modal-pre" id="modal-content"></pre>
            </div>
        </div>
    </div>

    <footer>
        &copy; {{ date('Y') }} IntelliShop. All rights reserved.
    </footer>

    <!-- Axios Client & Audit Logs Interactive Module -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="{{ asset('js/audit-logs.js') }}" defer></script>
</body>
</html>
