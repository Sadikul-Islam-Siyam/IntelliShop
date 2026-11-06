<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Replenishment &amp; Purchase Orders — IntelliShop</title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/reorder.css') }}">
</head>
<body>
    <header>
        <nav class="navbar">
            <a href="/dashboard" class="brand">
                <div class="navbar-logo-sticker">
                    <img src="{{ asset('images/logo.jpg') }}" alt="IntelliShop" class="navbar-logo">
                </div>
                <span class="brand-name">IntelliShop</span>
            </a>
            <div class="nav-actions">
                <span class="nav-user-info">
                    <strong>{{ $user->name ?? 'Manager' }}</strong> ({{ $user->role->name ?? 'Manager' }})
                </span>
                <a href="/dashboard" class="btn-nav">&larr; Dashboard</a>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-logout">Logout</button>
                </form>
            </div>
        </nav>
    </header>

    <div class="container">
        @if(session('success'))
            <div class="alert-box alert-success" style="display: block; margin-bottom: 1.5rem; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.85rem 1.25rem; border-radius: 8px; font-weight: 600;">
                ✓ {{ session('success') }}
            </div>
        @endif

        <div class="page-header">
            <div>
                <h1 class="page-title">
                    Dynamic Replenishment &amp; Purchase Advice
                    <span class="sr-only">Safety Stock (SS), Reorder Point (ROP), Target Stock</span>
                </h1>
                <p class="page-subtitle">
                    Automated replenishment quantities, verified supplier contract terms, and purchase order approval.
                </p>
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
                        <option value="HIGH" @selected($selectedRisk === 'HIGH')>High Risk</option>
                        <option value="MEDIUM" @selected($selectedRisk === 'MEDIUM')>Medium Risk</option>
                        <option value="LOW" @selected($selectedRisk === 'LOW')>Low Risk</option>
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
                Showing {{ count($recommendations) }} products for selected branch
            </div>
        </div>

        <!-- Clean Manager Replenishment Review Table -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-title">Replenishment Order Recommendations</div>
                <div class="section-badge">
                    Supplier-Verified Replenishment
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
                                <th>Product</th>
                                <th>Available Supplier</th>
                                <th>Current Stock</th>
                                <th>Unit Price</th>
                                <th>Replenishment Quantity</th>
                                <th>Total Estimated Price</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recommendations as $item)
                                @php
                                    $prod = $item['product'];
                                    $supp = $item['supplier'];
                                    $params = $item['parameters'];
                                    $calc = $item['calculation'];
                                    $isConflict = (bool) ($calc['conflict'] ?? false);
                                    $finalQty = (int) ($calc['final_quantity'] ?? 0);
                                    $unitPrice = (float) ($prod['unit_price'] ?? 0.0);
                                    $totalCost = round($finalQty * $unitPrice, 2);
                                @endphp
                                <tr class="{{ $isConflict ? 'row-conflict' : '' }}">
                                    <td>
                                        <div class="product-name">{{ $prod['name'] }}</div>
                                        <div class="sku-subtext">{{ $prod['sku'] }}</div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: var(--text);">{{ $supp['name'] ?? 'Preferred Supplier' }}</div>
                                        <div class="supplier-subtext">{{ $supp['lead_time_days'] }}d lead time &bull; MOQ: {{ $supp['moq'] }} &bull; Mult: &times;{{ $supp['order_multiple'] }}</div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: var(--text);">{{ number_format((float) $params['available_stock'], 0) }} units</div>
                                        @if((float) $params['reserved'] > 0)
                                            <div class="param-sub">Reserved: {{ number_format((float) $params['reserved'], 0) }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="price-text">${{ number_format($unitPrice, 2) }}</div>
                                        <div class="price-sub">per unit</div>
                                    </td>
                                    <td>
                                        @if($isConflict)
                                            <span class="final-qty-badge final-qty-conflict">0 (Capacity Conflict)</span>
                                            <div class="conflict-banner">&#9888; MOQ exceeds capacity</div>
                                        @elseif($finalQty > 0)
                                            <span class="final-qty-badge final-qty-order" style="font-size: 0.95rem;">{{ number_format($finalQty) }} units</span>
                                        @else
                                            <span class="final-qty-badge final-qty-zero">0 units (Stock Sufficient)</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($finalQty > 0)
                                            <div class="total-price">${{ number_format($totalCost, 2) }}</div>
                                        @else
                                            <div style="color: var(--text-muted); font-size: 0.85rem;">$0.00</div>
                                        @endif
                                    </td>
                                    <td style="text-align: right;">
                                        @if($finalQty > 0 && !$isConflict)
                                            <form method="POST" action="{{ route('reorder.approve') }}" style="display: inline-block;">
                                                @csrf
                                                <input type="hidden" name="product_id" value="{{ $prod['id'] }}">
                                                <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                                                <input type="hidden" name="supplier_id" value="{{ $supp['id'] ?? '' }}">
                                                <input type="hidden" name="quantity" value="{{ $finalQty }}">
                                                <input type="hidden" name="unit_price" value="{{ $unitPrice }}">
                                                <button type="submit" class="btn-approve">Approve Order</button>
                                            </form>
                                        @elseif($isConflict)
                                            <button type="button" class="btn-approve-disabled" disabled title="Cannot satisfy MOQ within capacity">Blocked</button>
                                        @else
                                            <button type="button" class="btn-approve-disabled" disabled>Optimal</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <footer class="dashboard-footer">
        <div class="footer-container">
            <div>
                <strong>IntelliShop</strong> &mdash; Intelligent Super Shop Management &amp; Decision Support System.
            </div>
            <div>
                <span>&copy; {{ date('Y') }} IntelliShop. All rights reserved.</span>
            </div>
        </div>
    </footer>
</body>
</html>
