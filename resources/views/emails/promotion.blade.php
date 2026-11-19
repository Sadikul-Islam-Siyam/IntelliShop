<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Special Promotion</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; padding: 24px; margin: 0; }
        .card { background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; max-width: 560px; margin: 0 auto; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 16px; margin-bottom: 24px; }
        .title { color: #1e3a8a; font-size: 20px; font-weight: 700; margin: 0; }
        .greeting { font-size: 16px; font-weight: 600; margin-bottom: 12px; }
        .content { font-size: 15px; line-height: 1.6; color: #334155; }
        .promo-box { background: #eff6ff; border: 1px dashed #3b82f6; border-radius: 6px; padding: 16px; margin: 20px 0; text-align: center; }
        .promo-text { font-size: 18px; font-weight: bold; color: #1d4ed8; }
        .footer { margin-top: 32px; font-size: 12px; color: #64748b; border-top: 1px solid #f1f5f9; padding-top: 16px; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1 class="title">IntelliShop Rewards</h1>
        </div>
        <div class="greeting">Hello {{ $customer->name }},</div>
        <div class="content">
            <p>{{ $promoMessage }}</p>
            <div class="promo-box">
                <div class="promo-text">{{ $product->name }}</div>
                <div style="margin-top: 6px; color: #475569;">
                    @if($discount->type === 'percentage')
                        Enjoy <strong>{{ $discount->value }}% OFF</strong>
                    @else
                        Save <strong>${{ number_format($discount->value, 2) }}</strong>
                    @endif
                </div>
            </div>
            <p>Visit any of our branch locations today and present this email at the counter to claim your discount.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} IntelliShop Retail System. All rights reserved.
        </div>
    </div>
</body>
</html>
