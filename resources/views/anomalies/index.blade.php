<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sales Anomaly Review Workflow — IntelliShop</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>
    @include('partials.navbar')
    <main class="main-content" style="max-width: 1280px; margin: 0 auto; padding: 2rem 1.5rem;">
        @if(session('success'))
            <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600;">
                ✓ {{ session('success') }}
            </div>
        @endif

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="workspace-header">
                <div>
                    <h2>Sales Anomaly Detection &amp; Review Workflow</h2>
                    <p class="welcome-subtitle">
                        Modified Z-Score (MAD) and Tukey IQR Fences screening.
                        <strong>Note:</strong> An anomaly alert is a statistical screening signal, not proof of error or fraud.
                    </p>
                </div>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-top: 0;">Detected Sales Anomalies ({{ $anomalies->count() }})</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 0.65rem;">Date</th>
                        <th style="padding: 0.65rem;">Product / Branch</th>
                        <th style="padding: 0.65rem;">Expected vs Actual</th>
                        <th style="padding: 0.65rem;">Deviation Score</th>
                        <th style="padding: 0.65rem;">Operational Context</th>
                        <th style="padding: 0.65rem;">Status &amp; Review</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($anomalies as $anomaly)
                        @php
                            $ctx = $anomaly->context ?? [];
                        @endphp
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 0.65rem;">{{ $anomaly->detected_date?->format('Y-m-d') }}</td>
                            <td style="padding: 0.65rem;">
                                <strong>{{ $anomaly->product?->name ?? 'Product' }}</strong>
                                <div style="font-size: 0.78rem; color: #64748b;">{{ $anomaly->branch?->name ?? 'Branch' }}</div>
                            </td>
                            <td style="padding: 0.65rem;">
                                Baseline: {{ number_format((float) $anomaly->expected_value, 1) }} &rarr;
                                <strong>Actual: {{ number_format((float) $anomaly->actual_value, 1) }}</strong>
                            </td>
                            <td style="padding: 0.65rem; font-weight: 700;">{{ number_format((float) $anomaly->deviation_score, 3) }}</td>
                            <td style="padding: 0.65rem; font-size: 0.82rem;">
                                <div>{{ $ctx['calendar_note'] ?? '' }}</div>
                                <div>Stock-Out: {{ !empty($ctx['stock_out_detected']) ? 'Yes' : 'No' }} &bull; Active Discount: {{ !empty($ctx['has_active_discount']) ? 'Yes' : 'No' }}</div>
                                @if($anomaly->notes)
                                    <div style="color: #334155; margin-top: 0.25rem;"><em>Notes: {{ $anomaly->notes }}</em></div>
                                @endif
                            </td>
                            <td style="padding: 0.65rem;">
                                <div style="font-weight: 700; margin-bottom: 0.35rem;">{{ ucfirst($anomaly->status) }}</div>
                                <form method="POST" action="{{ route('anomalies.review', $anomaly->id) }}" style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                                    @csrf
                                    <input type="text" name="notes" placeholder="Explanation / notes..." style="padding: 0.3rem; font-size: 0.8rem; border: 1px solid #cbd5e1; border-radius: 4px;">
                                    <button type="submit" name="action" value="acknowledged" style="padding: 0.3rem 0.5rem; font-size: 0.75rem; background: #0284c7; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Acknowledge</button>
                                    <button type="submit" name="action" value="explained" style="padding: 0.3rem 0.5rem; font-size: 0.75rem; background: #16a34a; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Explain</button>
                                    <button type="submit" name="action" value="dismissed" style="padding: 0.3rem 0.5rem; font-size: 0.75rem; background: #64748b; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Dismiss</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding: 1rem; color: #64748b;">No sales anomalies found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
    @include('partials.footer')
</body>
</html>
