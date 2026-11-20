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
    <main class="main-content" style="max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem;">
        <div class="card" style="margin-bottom: 1.5rem;">
            <h2>Natural-Language Business Queries (NLQ)</h2>
            <p class="welcome-subtitle">Ask plain-English questions about sales, inventory, stock-out risks, suppliers, anomalies, or expiring batches. Queries map strictly to whitelisted, parameterised read-only database intents with verified evidence.</p>

            <form method="POST" action="{{ route('nlq.ask') }}" data-async-form data-async-target="#nlq-results" style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                @csrf
                <input type="text" name="query" data-no-debounce="1" value="{{ $query }}" placeholder="e.g., What were total sales in the last 7 days? or Show top 5 best selling products" style="flex: 1; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.95rem;">
                <button type="submit" style="background: #2563eb; color: #fff; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer;">Ask Question</button>
            </form>

            <div style="margin-top: 1rem; font-size: 0.85rem; color: #64748b; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <span>Try asking:</span>
                <a href="{{ route('nlq.index', ['query' => 'What were total sales in the last 7 days?']) }}" style="color: #2563eb; text-decoration: underline;">Total sales last 7 days</a> &bull;
                <a href="{{ route('nlq.index', ['query' => 'Show top 5 best selling products this month']) }}" style="color: #2563eb; text-decoration: underline;">Top selling products</a> &bull;
                <a href="{{ route('nlq.index', ['query' => 'Which is the best supplier ranking?']) }}" style="color: #2563eb; text-decoration: underline;">Supplier rankings</a> &bull;
                <a href="{{ route('nlq.index', ['query' => 'List products with low stock alerts']) }}" style="color: #2563eb; text-decoration: underline;">Low-stock alerts</a>
            </div>
        </div>

        <div id="nlq-results" data-async-target>
        @if($error)
            <div class="card" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 1.25rem; margin-bottom: 1.5rem;">
                <h4 style="margin: 0 0 0.5rem 0;">Query Blocked / Unauthorized</h4>
                <p style="margin: 0;">{{ $error }}</p>
            </div>
        @endif

        @if($answer)
            <div class="card" style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <span class="is-badge is-badge-info">Intent: {{ strtoupper(str_replace('_', ' ', $answer['intent'])) }}</span>
                    <span style="font-size: 0.8rem; color: #64748b;">Confidence: {{ number_format(($answer['confidence'] ?? 1.0) * 100, 0) }}% &bull; Latency: {{ number_format($answer['metadata']['execution_time_ms'] ?? 0, 1) }}ms</span>
                </div>

                <div style="background: #f8fafc; border-left: 4px solid #2563eb; padding: 1rem 1.25rem; border-radius: 4px; margin-bottom: 1.5rem;">
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem; color: #0f172a;">Executive Narrative Answer</h3>
                    <p style="margin: 0; font-size: 1rem; color: #334155; line-height: 1.5;">{{ $answer['narrative_answer'] }}</p>
                </div>

                @if(!empty($answer['table']['rows']) && count($answer['table']['rows']) > 0)
                    <h4 style="margin-bottom: 0.75rem;">Supporting Tabular Evidence ({{ count($answer['table']['rows']) }} records)</h4>
                    <div class="is-table-wrap" style="overflow-x: auto; margin-bottom: 1.5rem;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f1f5f9;">
                                    @foreach($answer['table']['columns'] as $col)
                                        <th style="padding: 0.65rem 0.85rem; text-align: left; font-size: 0.8rem; text-transform: uppercase; color: #475569; border-bottom: 1px solid #cbd5e1;">{{ str_replace('_', ' ', $col) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($answer['table']['rows'] as $row)
                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                        @foreach($answer['table']['columns'] as $col)
                                            <td style="padding: 0.65rem 0.85rem; font-size: 0.875rem;">{{ $row[$col] ?? '—' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if(!empty($answer['sources']))
                    <div style="background: #f1f5f9; padding: 0.85rem 1rem; border-radius: 6px; font-size: 0.8rem; color: #475569;">
                        <strong>Evidence Citations &amp; Audit Trace:</strong>
                        <span style="margin-left: 0.5rem;">Tables: <code>{{ $answer['sources']['primary_source_tables'] ?? 'database' }}</code></span>
                        &bull; <span style="margin-left: 0.5rem;">Filters: <code>{{ $answer['sources']['filter_constraints'] ?? 'none' }}</code></span>
                        &bull; <span style="margin-left: 0.5rem; color: #16a34a; font-weight: 600;">✓ Audit Logged to immutable trail</span>
                    </div>
                @endif
            </div>
        @endif
        </div>
    </main>
    @include('partials.footer')
</body>
</html>
