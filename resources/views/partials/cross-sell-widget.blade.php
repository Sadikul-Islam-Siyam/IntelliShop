<div class="cross-sell-widget card" id="pos-cross-sell-widget" data-product-id="{{ $product->id ?? '' }}">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
        <h4 style="margin: 0; font-size: 1rem; font-weight: 700; color: #0f172a;">
            Frequently Bought Together (Cross-Sell Recommendations)
        </h4>
        <span style="font-size: 0.75rem; background: #e0f2fe; color: #0369a1; padding: 0.2rem 0.6rem; border-radius: 999px; font-weight: 700;">
            Association Rules
        </span>
    </div>
    <div id="cross-sell-items-container">
        @if(!empty($crossSellRules) && count($crossSellRules) > 0)
            <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 0.5rem;">
                @foreach($crossSellRules as $rule)
                    <li style="display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.85rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <div>
                            <strong>{{ $rule->consequent?->name ?? 'Recommended Item' }}</strong>
                            <span style="color: #64748b; font-size: 0.8rem;">({{ $rule->consequent?->sku ?? '' }})</span>
                        </div>
                        <div style="font-size: 0.8rem; color: #334155;">
                            Confidence: <strong>{{ number_format((float) $rule->confidence * 100, 1) }}%</strong> &bull;
                            Lift: <strong>{{ number_format((float) $rule->lift, 2) }}x</strong>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p style="margin: 0; color: #64748b; font-size: 0.85rem;">Scan or select a product to view top association rules.</p>
        @endif
    </div>
</div>
