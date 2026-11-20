<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customers | IntelliShop</title>
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
            <div><h1 class="h3 mb-1">Customers</h1><p class="text-muted mb-0">Customer profiles and segments.</p></div>
            @if($canManage)<a class="btn btn-primary" href="{{ route('customers.create') }}">Add customer</a>@endif
        </div>
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        <form class="is-toolbar" method="get" action="{{ route('customers.index') }}" data-async-form data-async-target="#customers-results">
            <div class="is-search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" class="form-control" name="search" value="{{ request('search') }}" placeholder="Search name, email, or phone...">
            </div>
            <select class="form-select" name="segment" style="width: auto; min-width: 170px;">
                <option value="">All Segments</option>
                @foreach($segments as $segment)
                    <option value="{{ $segment }}" @selected(request('segment') === $segment)>{{ $segment }}</option>
                @endforeach
            </select>
            <button type="submit" class="is-btn">Filter</button>
            @if(request()->filled('search') || request()->filled('segment'))
                <a href="{{ route('customers.index') }}" class="is-btn is-btn-secondary">Reset</a>
            @endif
        </form>

        <div class="card border-0 shadow-sm" id="customers-results" data-async-target>
            <div class="is-table-wrap">
                <table class="mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Segment</th>
                            <th>Loyalty Points</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customers as $customer)
                            @php
                                $seg = strtoupper((string) $customer->segment);
                                $segClass = match(true) {
                                    $seg === 'VIP' => 'is-badge-warning',
                                    $seg === 'REGULAR' || $seg === 'LOYAL' => 'is-badge-success',
                                    $seg !== '' => 'is-badge-info',
                                    default => 'is-badge-neutral',
                                };
                            @endphp
                            <tr>
                                <td class="fw-semibold">{{ $customer->name }}</td>
                                <td>
                                    <div>{{ $customer->email ?: '—' }}</div>
                                    <small class="text-muted">{{ $customer->phone ?: '—' }}</small>
                                </td>
                                <td>
                                    <span class="is-badge {{ $segClass }}">{{ $customer->segment ?: 'Unassigned' }}</span>
                                </td>
                                <td class="fw-semibold">{{ number_format($customer->loyalty_points) }} pts</td>
                                <td style="text-align: right;">
                                    <div class="is-actions-group">
                                        <a class="is-btn is-btn-sm is-btn-secondary" href="{{ route('customers.history', $customer) }}">View History</a>
                                        @if($canManage)
                                            <a class="is-btn is-btn-sm is-btn-info" href="{{ route('customers.edit', $customer) }}">Edit</a>
                                            <form class="d-inline" method="post" action="{{ route('customers.destroy', $customer) }}" data-async-action data-refresh-target="#customers-results" data-confirm="Delete this profile? Historical sales will be retained without a customer link." data-success-message="Customer deleted.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="is-btn is-btn-sm is-btn-danger">Delete</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="is-empty-state">
                                    <div class="is-empty-state-icon">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    </div>
                                    <div class="is-empty-state-title">No customers found</div>
                                    <p class="is-empty-state-desc">Try adjusting your search keyword or segment filter.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="is-table-footer">
                <div>
                    @if($customers->total() > 0)
                        Showing <strong>{{ $customers->firstItem() }}</strong>–<strong>{{ $customers->lastItem() }}</strong> of <strong>{{ $customers->total() }}</strong> customers
                    @else
                        Showing 0 customers
                    @endif
                </div>
                <div>{{ $customers->links() }}</div>
            </div>
        </div>
    </main>

    @include('partials.footer')
</body>
</html>
