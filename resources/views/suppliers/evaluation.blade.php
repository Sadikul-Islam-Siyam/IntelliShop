<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Evaluation &amp; SAW Ranking - IntelliShop</title>
    <link rel="stylesheet" href="{{ asset('css/navbar-footer.css') }}">
    <link rel="stylesheet" href="{{ asset('css/supplier-evaluation.css') }}">
</head>
<body>
    @include('partials.navbar')

    <main class="main-container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Two-Stage Supplier Evaluation &amp; SAW Ranking</h1>
                <p class="page-subtitle">
                    Stage 1 Eligibility Filter (Active, MOQ, Lead Time &le; {{ $evaluation['max_acceptable_lead_days'] ?? 14 }}d) &bull; Stage 2 Simple Additive Weighting (SAW)
                </p>
            </div>
            <div>
                <a href="{{ route('suppliers.index') }}" class="btn btn-secondary">&larr; Supplier Directory</a>
            </div>
        </div>

        <form method="GET" action="{{ route('suppliers.evaluate') }}" class="filter-bar">
            <label for="branch_id" class="text-muted">Branch:</label>
            <select name="branch_id" id="branch_id" class="filter-select">
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (int) $selectedBranchId === (int) $b->id ? 'selected' : '' }}>
                        {{ $b->name }} ({{ $b->code }})
                    </option>
                @endforeach
            </select>

            <label for="product_id" class="text-muted">Product:</label>
            <select name="product_id" id="product_id" class="filter-select">
                @foreach($products as $p)
                    <option value="{{ $p->id }}" {{ (int) $selectedProductId === (int) $p->id ? 'selected' : '' }}>
                        {{ $p->name }} ({{ $p->sku }})
                    </option>
                @endforeach
            </select>

            <label for="required_quantity" class="text-muted">Required Qty:</label>
            <input type="number" name="required_quantity" id="required_quantity" class="filter-input"
                   value="{{ request('required_quantity', (int) ($evaluation['required_quantity'] ?? 10)) }}" min="1">

            <button type="submit" class="btn btn-primary">Evaluate Suppliers</button>
        </form>

        @if(!empty($evaluation['alert']))
            <div class="alert-banner">
                <strong>Procurement Alert:</strong> {{ $evaluation['alert']['message'] ?? 'No eligible suppliers found.' }}
            </div>
        @endif

        @php
            $mode = strtoupper((string) ($evaluation['mode'] ?? 'NORMAL'));
            $weights = $evaluation['weights'] ?? ['price' => 0.70, 'speed' => 0.10, 'reliability' => 0.20];
            $modeBadgeClass = match($mode) {
                'PANIC' => 'badge-panic',
                'WARNING' => 'badge-warning',
                default => 'badge-normal',
            };
        @endphp

        <div class="summary-strip">
            <div class="summary-card">
                <div class="summary-label">Context-Aware Weight Mode</div>
                <div class="summary-value">
                    <span class="badge {{ $modeBadgeClass }}">{{ $mode }}</span>
                </div>
                <div class="summary-subtext">
                    Stock: {{ number_format((float) ($evaluation['stock'] ?? 0), 0) }} vs ROP: {{ number_format((float) ($evaluation['rop'] ?? 0), 1) }}
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Active SAW Weights</div>
                <div class="summary-value">
                    P:{{ number_format(($weights['price'] ?? 0.7) * 100, 0) }}% /
                    L:{{ number_format(($weights['speed'] ?? 0.1) * 100, 0) }}% /
                    R:{{ number_format(($weights['reliability'] ?? 0.2) * 100, 0) }}%
                </div>
                <div class="summary-subtext">Price / Speed / Reliability</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Recommended Supplier</div>
                <div class="summary-value">
                    {{ $evaluation['recommended_supplier']['supplier_name'] ?? 'None Eligible' }}
                </div>
                <div class="summary-subtext">
                    @if(!empty($evaluation['recommended_supplier']))
                        SAW Score: {{ number_format((float) $evaluation['recommended_supplier']['score'], 3) }}
                    @else
                        0 of {{ $evaluation['candidates_count'] ?? 0 }} candidates eligible
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-header">Stage 2: Ranked Eligible Suppliers (SAW Composite Score)</div>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Supplier</th>
                        <th>Unit Price (Cost)</th>
                        <th>Lead Time (Cost)</th>
                        <th>Fulfillment Rate (Benefit)</th>
                        <th>MOQ / Multiple</th>
                        <th>Normalized (r_p / r_l / r_r)</th>
                        <th>SAW Score (S_i)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($evaluation['ranking'] ?? [] as $row)
                        <tr>
                            <td>
                                <strong>#{{ $row['rank'] }}</strong>
                                @if(!empty($row['is_recommended']))
                                    <span class="badge badge-normal">Recommended</span>
                                @endif
                            </td>
                            <td>{{ $row['supplier_name'] }}</td>
                            <td>${{ number_format((float) $row['price'], 2) }}</td>
                            <td>{{ (int) $row['lead_time_days'] }} days</td>
                            <td>{{ number_format(((float) $row['fulfillment_rate']) * 100, 1) }}%</td>
                            <td>MOQ {{ (int) $row['moq'] }} (x{{ (int) ($row['order_multiple'] ?? 1) }})</td>
                            <td class="text-muted">
                                {{ number_format((float) ($row['normalized']['r_price'] ?? 0), 3) }} /
                                {{ number_format((float) ($row['normalized']['r_leadtime'] ?? 0), 3) }} /
                                {{ number_format((float) ($row['normalized']['r_fulfillment'] ?? 0), 3) }}
                            </td>
                            <td>
                                <span class="score-pill">{{ number_format((float) $row['score'], 3) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty-state">No eligible suppliers passed Stage 1 filtering for this product.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(!empty($evaluation['ineligible']))
            <div class="card">
                <div class="section-header">Stage 1: Filtered Out (Ineligible) Suppliers</div>
                <table>
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Unit Price</th>
                            <th>Lead Time</th>
                            <th>MOQ</th>
                            <th>Disqualification Reasons</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($evaluation['ineligible'] as $inel)
                            <tr>
                                <td>{{ $inel['supplier_name'] }}</td>
                                <td>{{ strtoupper((string) ($inel['status'] ?? 'inactive')) }}</td>
                                <td>${{ number_format((float) ($inel['price'] ?? 0), 2) }}</td>
                                <td>{{ (int) ($inel['lead_time_days'] ?? 0) }} days</td>
                                <td>{{ (int) ($inel['moq'] ?? 0) }}</td>
                                <td>
                                    @foreach($inel['ineligible_reasons'] ?? [] as $reason)
                                        <span class="badge badge-panic">{{ $reason }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>

    @include('partials.footer')
</body>
</html>

