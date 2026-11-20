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
            <form method="GET" action="{{ route('anomalies.index') }}" data-async-form data-async-target="#anomalies-results" style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; align-items: flex-end;">
                <div>
                    <label for="branch_id" style="display: block; font-size: 0.78rem; font-weight: 700; color: #475569;">Branch</label>
                    <select name="branch_id" id="branch_id" style="padding: 0.45rem 0.65rem; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <option value="">All Branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) $selectedBranchId === (int) $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="status" style="display: block; font-size: 0.78rem; font-weight: 700; color: #475569;">Status</label>
                    <select name="status" id="status" style="padding: 0.45rem 0.65rem; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <option value="" @selected($selectedStatus === '')>All Statuses</option>
                        <option value="new" @selected($selectedStatus === 'new')>New</option>
                        <option value="acknowledged" @selected($selectedStatus === 'acknowledged')>Acknowledged</option>
                        <option value="explained" @selected($selectedStatus === 'explained')>Explained</option>
                        <option value="dismissed" @selected($selectedStatus === 'dismissed')>Dismissed</option>
                    </select>
                </div>
            </form>
        </div>

        <div class="card" id="anomalies-results" data-async-target style="padding: 0; overflow: hidden;">
            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Detected Sales Anomalies</h3>
                <span class="is-badge is-badge-info">{{ $anomalies->count() }} records</span>
            </div>
            <div class="is-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product / Branch</th>
                            <th>Expected vs Actual</th>
                            <th>Deviation Score</th>
                            <th>Operational Context</th>
                            <th>Status &amp; Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($anomalies as $anomaly)
                            @php
                                $ctx = $anomaly->context ?? [];
                                $statusBadge = match(strtolower((string) $anomaly->status)) {
                                    'explained' => 'is-badge-success',
                                    'acknowledged' => 'is-badge-info',
                                    'dismissed' => 'is-badge-neutral',
                                    default => 'is-badge-danger',
                                };
                            @endphp
                            <tr>
                                <td>{{ $anomaly->detected_date?->format('Y-m-d') }}</td>
                                <td>
                                    <strong>{{ $anomaly->product?->name ?? 'Product' }}</strong>
                                    <div style="font-size: 0.78rem; color: #64748b;">{{ $anomaly->branch?->name ?? 'Branch' }}</div>
                                </td>
                                <td>
                                    Baseline: {{ number_format((float) $anomaly->expected_value, 1) }} &rarr;
                                    <strong>Actual: {{ number_format((float) $anomaly->actual_value, 1) }}</strong>
                                </td>
                                <td style="font-weight: 700;">{{ number_format((float) $anomaly->deviation_score, 3) }}</td>
                                <td style="font-size: 0.82rem;">
                                    <div>{{ $ctx['calendar_note'] ?? '' }}</div>
                                    <div>Stock-Out: {{ !empty($ctx['stock_out_detected']) ? 'Yes' : 'No' }} &bull; Active Discount: {{ !empty($ctx['has_active_discount']) ? 'Yes' : 'No' }}</div>
                                    @if($anomaly->notes)
                                        <div style="color: #334155; margin-top: 0.25rem;"><em>Notes: {{ $anomaly->notes }}</em></div>
                                    @endif
                                </td>
                                <td>
                                    <div style="margin-bottom: 0.45rem;">
                                        <span class="is-badge {{ $statusBadge }}">{{ ucfirst($anomaly->status) }}</span>
                                    </div>
                                    <form method="POST" action="{{ route('anomalies.review', $anomaly->id) }}" data-async-action data-refresh-target="#anomalies-results" style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                                        @csrf
                                        <input type="text" name="notes" placeholder="Explanation / notes..." style="padding: 0.32rem 0.5rem; font-size: 0.78rem; min-width: 140px;">
                                        <button type="submit" name="action" value="acknowledged" class="is-btn is-btn-sm is-btn-info">Acknowledge</button>
                                        <button type="submit" name="action" value="explained" class="is-btn is-btn-sm is-btn-success">Explain</button>
                                        <button type="submit" name="action" value="dismissed" class="is-btn is-btn-sm is-btn-secondary">Dismiss</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="is-empty-state">
                                    <div class="is-empty-state-title">No sales anomalies found</div>
                                    <p class="is-empty-state-desc">No statistical sales deviations match the selected branch and status filters.</p>
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
