<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Natural-Language Business Queries — IntelliShop</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>
    @include('partials.navbar')
    <main class="main-content" style="max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem;">
        <div class="card" style="margin-bottom: 1.5rem;">
            <h2>Natural-Language Business Queries (NLQ)</h2>
            <p class="welcome-subtitle">Ask plain-English questions about sales, inventory, stock-out risks, suppliers, anomalies, or expiring batches. All queries map strictly to whitelisted read-only intents.</p>

            <form method="GET" action="{{ route('nlq.index') }}" style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                <input type="text" name="query" value="{{ $query }}" placeholder="e.g., What were total sales at Main Branch in the last 7 days?" style="flex: 1; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.95rem;">
                <button type="submit" style="background: #2563eb; color: #fff; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer;">Resolve Question</button>
            </form>
        </div>

        @if($resolution)
            <div class="card">
                <h3 style="margin-top: 0;">Intent Classification &amp; Extracted Slots</h3>
                <p><strong>Resolved Intent:</strong> <code>{{ $resolution['intent'] }}</code> (Confidence: {{ number_format(($resolution['confidence'] ?? 0) * 100, 0) }}%)</p>
                @if(!empty($resolution['fallback_message']))
                    <div style="background: #fef3c7; color: #92400e; padding: 0.85rem 1rem; border-radius: 8px; margin-top: 0.75rem;">
                        {{ $resolution['fallback_message'] }}
                    </div>
                @endif
                <pre style="background: #0f172a; color: #f8fafc; padding: 1rem; border-radius: 8px; overflow-x: auto;">{{ json_encode($resolution['slots'] ?? [], JSON_PRETTY_PRINT) }}</pre>
            </div>
        @endif
    </main>
    @include('partials.footer')
</body>
</html>
