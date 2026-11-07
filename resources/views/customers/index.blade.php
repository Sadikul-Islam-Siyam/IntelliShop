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
        <form class="row g-2 mb-3" method="get">
            <div class="col-md-7"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Search name, email, or phone"></div>
            <div class="col-md-3"><select class="form-select" name="segment"><option value="">All segments</option>@foreach($segments as $segment)<option value="{{ $segment }}" @selected(request('segment') === $segment)>{{ $segment }}</option>@endforeach</select></div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
        </form>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Name</th><th>Contact</th><th>Segment</th><th>Loyalty points</th><th class="text-end">Actions</th></tr></thead><tbody>
        @forelse($customers as $customer)<tr><td class="fw-semibold">{{ $customer->name }}</td><td>{{ $customer->email ?: '—' }}<br><small class="text-muted">{{ $customer->phone ?: '—' }}</small></td><td>@if($customer->segment)<span class="badge text-bg-info">{{ $customer->segment }}</span>@else<span class="text-muted">Unassigned</span>@endif</td><td>{{ number_format($customer->loyalty_points) }}</td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('customers.history', $customer) }}">History</a>@if($canManage) <a class="btn btn-sm btn-outline-primary" href="{{ route('customers.edit', $customer) }}">Edit</a><form class="d-inline" method="post" action="{{ route('customers.destroy', $customer) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this profile? Historical sales will be retained without a customer link.')">Delete</button></form>@endif</td></tr>
        @empty<tr><td colspan="5" class="text-center text-muted py-4">No customers found.</td></tr>@endforelse
        </tbody></table></div><div class="card-body">{{ $customers->links() }}</div></div>
    </main>

    @include('partials.footer')
</body>
</html>
