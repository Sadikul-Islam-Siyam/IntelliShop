<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Replenishment &amp; Safety Stock — IntelliShop</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/reorder.css') }}">
</head>
<body>
    <nav class="navbar">
        <a href="/dashboard" class="navbar-brand">
            <img src="/images/logo.jpg" alt="IntelliShop">
            <span>IntelliShop</span>
        </a>
        <div class="navbar-right">
            @if(isset($user))
                <span class="nav-user-info">
                    {{ $user->name }}
                    @if($user->role)
                        <span class="nav-user-role">({{ $user->role->display_name ?? $user->role->name }})</span>
                    @endif
                </span>
            @endif
            <a href="/forecasting" class="nav-link-btn">Demand Forecasts</a>
            <a href="/stock-risk" class="nav-link-btn">Stock-Out Risk</a>
            <a href="/dashboard" class="nav-link-btn">Dashboard</a>
            <form method="POST" action="{{ route('logout') }}" class="nav-logout-form">
                @csrf
                <button type="submit" class="btn-logout">Sign Out</button>
            </form>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Dynamic Replenishment &amp; Safety Stock (ROP + Constraints)</h1>
                <p class="page-subtitle">
                    Automated Safety Stock (SS), Reorder Point (ROP), Target Stock, and Sequential Constraint Pipeline
                </p>
            </div>
            <div class="formula-bar">
                <span class="formula-pill">SS = z &times; &sigma;<sub>d</sub> &times; &radic;L (z = {{ number_format($serviceLevelZ, 3) }})</span>
                <span class="formula-pill">ROP = d&#772; &times; L + SS</span>
                <span class="formula-pill">Target = Forecast(L + R) + SS (R = {{ $reviewPeriod }}d)</span>
            </div>
        </div>

        <!-- Branch & Risk Filter Toolbar (Auto-Submits on Change) -->
        <div class="toolbar-card">
            <form method="GET" action="{{ route('reorder.index') }}" class="filter-form" id="reorderFilterForm">
                <div class="form-group">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select name="branch_id" id="branch_id" class="form-control" onchange="this.form.submit()">
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($branch->id == $selectedBranchId)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="risk_level">Risk Tier</label>
                    <select name="risk_level" id="risk_level" class="form-control" onchange="this.form.submit()">
                        <option value="" @selected($selectedRisk === '')>All Risk Levels</option>
                        <option value="HIGH" @selected($selectedRisk === 'HIGH')>HIGH (&le; L days)</option>
                        <option value="MEDIUM" @selected($selectedRisk === 'MEDIUM')>MEDIUM (&le; L + R days)</option>
                        <option value="LOW" @selected($selectedRisk === 'LOW')>LOW (&gt; L + R days)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="filter">Order Filter</label>
                    <select name="filter" id="filter" class="form-control" onchange="this.form.submit()">
                        <option value="" @selected($filterMode === '')>All Catalog Products</option>
                        <option value="reorder_needed" @selected($filterMode === 'reorder_needed')>Actionable Reorders Only</option>
                        <option value="conflicts" @selected($filterMode === 'conflicts')>Capacity / MOQ Conflicts Only</option>
                    </select>
                </div>
            </form>
            <div class="toolbar-meta">
                Showing {{ count($recommendations) }} products &bull; Review Period (R): {{ $reviewPeriod }} days &bull; Service Level z: {{ number_format($serviceLevelZ, 3) }} (95%)
            </div>
        </div>

        <!-- Manager Review Table Showing Every Parameter & Constraint Step -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-title">Replenishment Parameter &amp; Constraint Review Table</div>
                <div class="section-badge">
                    Sequential Pipeline: 1. MOQ &rarr; 2. Order Multiple &rarr; 3. Max Capacity &rarr; 4. Perishable Shelf-Life
                </div>
            </div>

            @if(empty($recommendations))
                <div class="empty-state">
                    <p>No replenishment records match the selected filters.</p>
                </div>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Product / Supplier</th>
                                <th>Demand (d&#772; / &sigma;<sub>d</sub>)</th>
                                <th>Horizon (L / R / z)</th>
                                <th>Safety Stock (SS)</th>
                                <th>Reorder Point (ROP)</th>
                                <th>Target Stock</th>
                                <th>Available Stock</th>
                                <th>Raw Qty</th>
                                <th>Supplier &amp; Cap Constraints</th>
                                <th>Recommended Qty</th>
                                <th>Risk</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recommendations as $item)
                                @php
                                    $prod = $item['product'];
                                    $supp = $item['supplier'];
                                    $params = $item['parameters'];
                                    $calc = $item['calculation'];
                                    $pred = $item['prediction'];
                                    $isConflict = (bool) ($calc['conflict'] ?? false);
                                    $finalQty = (int) ($calc['final_quantity'] ?? 0);
                                @endphp
                                <tr class="{{ $isConflict ? 'row-conflict' : '' }}">
                                    <td>
                                        <div class="product-name">{{ $prod['name'] }}</div>
                                        <div class="sku-subtext">{{ $prod['sku'] }}</div>
                                        <div class="supplier-subtext">{{ $supp['name'] ?? 'Preferred Supplier' }}</div>
                                    </td>
                                    <td>
                                        <div class="param-stack">
                                            <span class="param-main">d&#772; = {{ number_format((float) $params['avg_daily_demand'], 2) }} /day</span>
                                            <span class="param-sub">&sigma;<sub>d</sub> = {{ number_format((float) $params['demand_stddev'], 2) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="param-stack">
                                            <span class="param-main">L = {{ $params['lead_time_days'] }}d &bull; R = {{ $params['review_period_days'] }}d</span>
                                            <span class="param-sub">z = {{ number_format((float) $params['service_level_z'], 3) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="param-stack">
                                            <span class="ss-value">{{ number_format((float) $calc['safety_stock'], 2) }}</span>
                                            <span class="param-sub">L-Demand: {{ number_format((float) $calc['lead_time_demand'], 1) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="rop-value">{{ number_format((float) $calc['reorder_point'], 2) }}</span>
                                    </td>
                                    <td>
                                        <div class="param-stack">
                                            <span class="target-value">{{ number_format((float) $calc['target_stock'], 2) }}</span>
                                            <span class="param-sub">D(L+R): {{ number_format((float) $calc['forecast_demand_l_plus_r'], 1) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="param-stack">
                                            <span class="param-main">{{ number_format((float) $params['available_stock'], 0) }} units</span>
                                            <span class="param-sub">
                                                On-hand: {{ number_format((float) $params['stock'], 0) }}
                                                @if((float) $params['reserved'] > 0)
                                                    &bull; Res: {{ number_format((float) $params['reserved'], 0) }}
                                                @endif
                                                @if((float) $params['on_order'] > 0)
                                                    &bull; PO: +{{ number_format((float) $params['on_order'], 0) }}
                                                @endif
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="raw-qty-value">{{ number_format((float) $calc['raw_quantity'], 2) }}</span>
                                    </td>
                                    <td>
                                        <div class="constraint-list">
                                            <span class="constraint-tag {{ in_array('moq', $calc['constraints'], true) ? 'constraint-tag-active' : '' }}">
                                                MOQ: {{ $params['moq'] }}
                                            </span>
                                            <span class="constraint-tag {{ in_array('order_multiple', $calc['constraints'], true) ? 'constraint-tag-active' : '' }}">
                                                Mult: &times;{{ $params['order_multiple'] }}
                                            </span>
                                            @if($params['capacity'] !== null)
                                                <span class="constraint-tag {{ in_array('capacity', $calc['constraints'], true) ? 'constraint-tag-active' : '' }}">
                                                    Cap: {{ number_format((float) $params['capacity'], 0) }}
                                                </span>
                                            @endif
                                            @if($params['shelf_life_days'] !== null)
                                                <span class="constraint-tag {{ in_array('shelf_life', $calc['constraints'], true) ? 'constraint-tag-active' : '' }}">
                                                    Shelf: {{ $params['shelf_life_days'] }}d
                                                </span>
                                            @endif
                                        </div>
                                        @if($isConflict)
                                            <div class="conflict-banner">
                                                &#9888; {{ $calc['conflict_flag'] }} (Capped: {{ $calc['capped_quantity'] }} &lt; MOQ {{ $params['moq'] }})
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isConflict)
                                            <span class="final-qty-badge final-qty-conflict">0 (Blocked)</span>
                                        @elseif($finalQty > 0)
                                            <span class="final-qty-badge final-qty-order">{{ number_format($finalQty) }} units</span>
                                        @else
                                            <span class="final-qty-badge final-qty-zero">0 units</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="risk-badge risk-{{ $pred['risk_level'] }}">
                                            {{ $pred['risk_level'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <footer class="app-footer">
        <div class="app-footer-brand">
            <img src="/images/logo.jpg" alt="IntelliShop">
            <span>IntelliShop &mdash; AI-Assisted Smart Inventory &amp; Retail Management System</span>
        </div>
        <div>
            &copy; {{ date('Y') }} IntelliShop. Dynamic Replenishment &amp; Safety Stock Module.
        </div>
    </footer>
</body>
</html>

