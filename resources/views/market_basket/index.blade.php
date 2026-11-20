<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Market Basket Analysis &amp; Cross-Sell Rules — IntelliShop</title>
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
                    <h2>Market Basket Affinity &amp; Cross-Sell Display</h2>
                    <p class="welcome-subtitle">Association rule mining (Support, Confidence, Lift) for POS and product cross-sell widgets.</p>
                </div>
                <form method="POST" action="{{ route('market-basket.run') }}">
                    @csrf
                    <button type="submit" style="background: #2563eb; color: #fff; border: none; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 700; cursor: pointer;">
                        Mine Association Rules
                    </button>
                </form>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            @include('partials.cross-sell-widget', ['product' => $selectedProduct, 'crossSellRules' => $crossSellWidgetRules])
        </div>

        <div class="card">
            <h3 style="margin-top: 0;">Stored Association Rules ({{ $rules->count() }})</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 0.65rem;">Antecedent (If Bought)</th>
                        <th style="padding: 0.65rem;">Consequent (Recommend)</th>
                        <th style="padding: 0.65rem;">Support</th>
                        <th style="padding: 0.65rem;">Confidence</th>
                        <th style="padding: 0.65rem;">Lift</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 0.65rem;"><strong>{{ $rule->antecedent?->name ?? 'Product' }}</strong> ({{ $rule->antecedent?->sku }})</td>
                            <td style="padding: 0.65rem;"><strong>{{ $rule->consequent?->name ?? 'Product' }}</strong> ({{ $rule->consequent?->sku }})</td>
                            <td style="padding: 0.65rem;">{{ number_format((float) $rule->support * 100, 1) }}%</td>
                            <td style="padding: 0.65rem;">{{ number_format((float) $rule->confidence * 100, 1) }}%</td>
                            <td style="padding: 0.65rem; font-weight: 700;">{{ number_format((float) $rule->lift, 2) }}x</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="padding: 1rem; color: #64748b;">No market basket rules stored yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
    @include('partials.footer')
</body>
</html>
