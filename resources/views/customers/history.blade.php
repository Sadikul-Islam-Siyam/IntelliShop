<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Purchase History | IntelliShop</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/navbar-footer.css') }}">
    <link rel="stylesheet" href="{{ asset('css/customer-history.css') }}">
</head>
<body class="bg-light app-layout">
    @include('partials.navbar')

    <main class="history-container">
        <div class="history-header">
            <div>
                <a href="{{ route('customers.index') }}" class="text-decoration-none small fw-semibold">&larr; Back to Customers</a>
                <h1 class="history-title mt-1">
                    Customer Purchase History
                    @if($customer)
                        &mdash; {{ $customer->name }}
                    @endif
                </h1>
                <p class="history-subtitle">Complete invoice and line-item transaction history for the selected customer profile.</p>
            </div>
        </div>

        <div class="history-toolbar">
            <form method="GET" action="{{ route('customers.history.index') }}" class="d-flex align-items-center gap-2 flex-wrap">
                <label for="customer_id" class="small fw-bold text-uppercase text-muted">Customer:</label>
                <select name="customer_id" id="customer_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    @foreach($customers as $c)
                        <option value="{{ $c->id }}" @selected($customer && $c->id === $customer->id)>
                            {{ $c->name }} ({{ $c->email ?: $c->phone ?: 'ID #' . $c->id }})
                        </option>
                    @endforeach
                </select>
            </form>

            @if($customer)
                <div class="customer-meta-pills">
                    <span class="meta-pill">Segment: {{ $customer->segment ?: 'Standard' }}</span>
                    <span class="meta-pill">Loyalty Points: {{ number_format($customer->loyalty_points) }}</span>
                    <span class="meta-pill">Orders: {{ number_format($totalOrders) }}</span>
                    <span class="meta-pill">Items Purchased: {{ number_format($totalItems) }}</span>
                    <span class="meta-pill">Total Spent: ${{ number_format($totalSpent, 2) }}</span>
                </div>
            @endif
        </div>

        <div class="history-table-card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Sale Date</th>
                            <th>Branch</th>
                            <th>Items Purchased</th>
                            <th>Payment Method</th>
                            <th>Discount</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sales as $sale)
                            <tr>
                                <td><span class="invoice-badge">{{ $sale->invoice_no }}</span></td>
                                <td>{{ $sale->sale_date?->format('M d, Y H:i') ?? '—' }}</td>
                                <td>{{ $sale->branch?->name ?? 'Main Branch' }}</td>
                                <td>
                                    <div class="item-chip-list">
                                        @foreach($sale->items as $item)
                                            <span class="item-chip">
                                                <strong>{{ $item->product?->name ?? 'Product #' . $item->product_id }}</strong>
                                                &times; {{ $item->quantity }} (${{ number_format((float) $item->subtotal, 2) }})
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-capitalize">{{ str_replace('_', ' ', $sale->payment_method ?? 'cash') }}</td>
                                <td>${{ number_format((float) $sale->discount_amount, 2) }}</td>
                                <td class="fw-bold">${{ number_format((float) $sale->total_amount, 2) }}</td>
                                <td><span class="status-completed">{{ $sale->status }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    No purchase history records found for this customer.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    @include('partials.footer')
</body>
</html>

