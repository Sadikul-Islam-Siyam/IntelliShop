<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $customer->exists ? 'Edit' : 'Add' }} customer | IntelliShop</title>
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

    <main class="container py-5" style="max-width: 760px">
        <a href="{{ route('customers.index') }}" class="text-decoration-none">&larr; Customers</a>
        <h1 class="h3 my-4">{{ $customer->exists ? 'Edit customer' : 'Add customer' }}</h1>
        <form method="post" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}" class="card card-body border-0 shadow-sm">
            @csrf @if($customer->exists)@method('PUT')@endif
            <label class="form-label" for="name">Name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="100" required value="{{ old('name', $customer->name) }}">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="row"><div class="col-md-6"><label class="form-label mt-3" for="email">Email</label><input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" maxlength="255" value="{{ old('email', $customer->email) }}">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div><div class="col-md-6"><label class="form-label mt-3" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="20" value="{{ old('phone', $customer->phone) }}"></div></div>
            <label class="form-label mt-3" for="address">Address</label><input class="form-control" id="address" name="address" maxlength="255" value="{{ old('address', $customer->address) }}">
            <label class="form-label mt-3" for="segment">Segment</label><input class="form-control @error('segment') is-invalid @enderror" id="segment" name="segment" maxlength="50" value="{{ old('segment', $customer->segment) }}" placeholder="Enter a customer segment">@error('segment')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @if($customer->exists)<p class="small text-muted mt-3 mb-0">Loyalty points: {{ number_format($customer->loyalty_points) }}. Points are managed by the sales workflow. <a href="{{ route('customers.history', $customer) }}">View Purchase History &rarr;</a></p>@endif
            <div class="mt-4"><button class="btn btn-primary">Save customer</button><a class="btn btn-outline-secondary" href="{{ route('customers.index') }}">Cancel</a></div>
        </form>
    </main>

    @include('partials.footer')
</body>
</html>
