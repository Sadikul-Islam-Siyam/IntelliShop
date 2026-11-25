<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Customer Promotions & Marketing Engine — IntelliShop</title>

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/forecasts.css') }}">
    <style>
        .badge { display: inline-block; padding: 4px 10px; font-size: 11px; font-weight: 700; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-warning { background: #fef3c7; color: #b45309; }
        .badge-info { background: #e0f2fe; color: #0369a1; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-label { font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-val { font-size: 26px; font-weight: 800; color: #0f172a; margin-top: 6px; }
        .action-banner { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); border-radius: 12px; padding: 24px; color: #ffffff; display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .btn-dispatch { background: #ffffff; color: #1e3a8a; font-weight: 700; padding: 12px 24px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-dispatch:hover { background: #f8fafc; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    </style>
</head>
<body>
    @include('partials.navbar')

    <main class="container" style="max-width: 1280px; margin: 32px auto; padding: 0 20px;">
        <div style="margin-bottom: 24px;">
            <h1 style="font-size: 28px; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Customer Promotion Email Engine</h1>
            <p style="color: #64748b; font-size: 15px; margin: 0;">Automated customer preference profiling, active discount matching, and email campaigns (#31).</p>
        </div>

        @if(session('success'))
            <div style="background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 600;">
                {{ session('success') }}
            </div>
        @endif

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-label">Total Promoted Sent</div>
                <div class="stat-val">{{ number_format($totalSent) }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Opened / Engaged</div>
                <div class="stat-val">{{ number_format($totalOpened) }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Targetable Customers</div>
                <div class="stat-val">{{ number_format($totalCustomers) }}</div>
            </div>
        </div>

        <div class="action-banner">
            <div>
                <h3 style="margin: 0 0 6px 0; font-size: 18px; font-weight: 700;">Trigger Promotion Run (artisan promotions:send)</h3>
                <p style="margin: 0; opacity: 0.9; font-size: 14px;">Evaluates past customer purchases, matches available active discounts, and queues personalized promotion emails.</p>
            </div>
            <form action="{{ route('promotions.send') }}" method="POST">
                @csrf
                <button type="submit" class="btn-dispatch">Run Promotion Engine</button>
            </form>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <h2 style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0;">Dispatched Promotions Log</h2>
                <span style="font-size: 13px; color: #64748b;">Showing latest {{ $promotions->count() }} records</span>
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 12px; text-transform: uppercase;">
                            <th style="padding: 12px 20px;">Customer</th>
                            <th style="padding: 12px 20px;">Target Product</th>
                            <th style="padding: 12px 20px;">Type</th>
                            <th style="padding: 12px 20px;">Message Preview</th>
                            <th style="padding: 12px 20px;">Status</th>
                            <th style="padding: 12px 20px;">Sent Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($promotions as $promo)
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 14px 20px; font-weight: 600; color: #1e293b;">
                                    {{ $promo->customer->name ?? 'Customer #' . $promo->customer_id }}
                                    <div style="font-size: 12px; color: #64748b; font-weight: normal;">{{ $promo->customer->email ?? '-' }}</div>
                                </td>
                                <td style="padding: 14px 20px;">
                                    <span style="font-weight: 600; color: #0284c7;">{{ $promo->product->name ?? 'Product #' . $promo->product_id }}</span>
                                    <div style="font-size: 12px; color: #64748b;">SKU: {{ $promo->product->sku ?? '-' }}</div>
                                </td>
                                <td style="padding: 14px 20px;">
                                    <span class="badge badge-info">{{ $promo->promotion_type }}</span>
                                </td>
                                <td style="padding: 14px 20px; color: #475569; max-width: 320px;">
                                    {{ $promo->message }}
                                </td>
                                <td style="padding: 14px 20px;">
                                    <span class="badge {{ $promo->status === 'sent' ? 'badge-success' : 'badge-warning' }}">
                                        {{ $promo->status }}
                                    </span>
                                </td>
                                <td style="padding: 14px 20px; color: #64748b; font-size: 13px;">
                                    {{ $promo->sent_at ? $promo->sent_at->format('M d, Y H:i') : $promo->created_at->format('M d, Y H:i') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="padding: 36px 20px; text-align: center; color: #94a3b8;">
                                    No promotions have been dispatched yet. Click "Run Promotion Engine" above to match eligible customers.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($promotions->hasPages())
                <div style="padding: 16px 24px; border-top: 1px solid #e2e8f0;">
                    {{ $promotions->links() }}
                </div>
            @endif
        </div>
    </main>

    @include('partials.footer')
</body>
</html>
