<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Suppliers | IntelliShop</title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/navbar-footer.css') }}">
</head>
<body class="bg-light app-layout">
    @include('partials.navbar')

    <main class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div><h1 class="h3 mb-1">Suppliers</h1><p class="text-muted mb-0">Supplier directory and contact details.</p></div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-primary" href="{{ route('suppliers.evaluate') }}">SAW Supplier Evaluation</a>
                @if ($canManage)<a class="btn btn-primary" href="{{ route('suppliers.create') }}">Add supplier</a>@endif
            </div>
        </div>
        @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        <form class="is-toolbar" method="get" action="{{ route('suppliers.index') }}" data-async-form data-async-target="#suppliers-results">
            <div class="is-search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" class="form-control" name="search" value="{{ request('search') }}" placeholder="Search supplier name, contact person, or email...">
            </div>
            <button type="submit" class="is-btn">Search</button>
            @if(request()->filled('search'))
                <a href="{{ route('suppliers.index') }}" class="is-btn is-btn-secondary">Reset</a>
            @endif
        </form>

        <div class="card border-0 shadow-sm" id="suppliers-results" data-async-target>
            <div class="is-table-wrap">
                <table class="mb-0">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Contact Person</th>
                            <th>Phone / Email</th>
                            <th>Rating</th>
                            <th>Status</th>
                            @if($canManage)<th style="text-align: right;">Actions</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($suppliers as $supplier)
                            <tr>
                                <td class="fw-semibold">{{ $supplier->name }}</td>
                                <td>{{ $supplier->contact_person ?: '—' }}</td>
                                <td>
                                    <div>{{ $supplier->phone ?: '—' }}</div>
                                    <small class="text-muted">{{ $supplier->email ?: '—' }}</small>
                                </td>
                                <td class="fw-semibold">{{ $supplier->rating === null ? '—' : number_format((float) $supplier->rating, 2) . ' / 5' }}</td>
                                <td>
                                    <span class="is-badge {{ $supplier->status === 'active' ? 'is-badge-success' : 'is-badge-neutral' }}">
                                        {{ ucfirst($supplier->status) }}
                                    </span>
                                </td>
                                @if($canManage)
                                    <td style="text-align: right;">
                                        <div class="is-actions-group">
                                            <a class="is-btn is-btn-sm is-btn-info" href="{{ route('suppliers.edit', $supplier) }}">Edit</a>
                                            @if($supplier->status === 'active')
                                                <form class="d-inline" method="post" action="{{ route('suppliers.destroy', $supplier) }}" data-async-action data-refresh-target="#suppliers-results" data-confirm="Deactivate this supplier?" data-success-message="Supplier deactivated.">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="is-btn is-btn-sm is-btn-danger">Deactivate</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="is-empty-state">
                                    <div class="is-empty-state-icon">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    </div>
                                    <div class="is-empty-state-title">No suppliers found</div>
                                    <p class="is-empty-state-desc">No suppliers matched your current search criteria.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="is-table-footer">
                <div>
                    @if($suppliers->total() > 0)
                        Showing <strong>{{ $suppliers->firstItem() }}</strong>–<strong>{{ $suppliers->lastItem() }}</strong> of <strong>{{ $suppliers->total() }}</strong> suppliers
                    @else
                        Showing 0 suppliers
                    @endif
                </div>
                <div>{{ $suppliers->links() }}</div>
            </div>
        </div>
    </main>

    @include('partials.footer')
</body>
</html>
