<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Recommendation Priority Queue — IntelliShop</title>
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
                    <h2>Explainable Recommendation Engine &amp; Priority Queue</h2>
                    <p class="welcome-subtitle">
                        Forward-chaining IF-THEN rules ordered by weighted priority score
                        (Risk: {{ number_format(($weights['risk'] ?? 0.45) * 100, 0) }}%,
                        Value at Stake: {{ number_format(($weights['value'] ?? 0.25) * 100, 0) }}%,
                        Urgency: {{ number_format(($weights['urgency'] ?? 0.30) * 100, 0) }}%).
                    </p>
                </div>
                <form method="POST" action="{{ route('recommendations.run') }}">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                    <button type="submit" style="background: #2563eb; color: #fff; border: none; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 700; cursor: pointer;">
                        Run Expert Rules
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-top: 0;">Ordered Recommendation Inbox ({{ $recommendations->count() }})</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 0.65rem;">Rank</th>
                        <th style="padding: 0.65rem;">Priority Score</th>
                        <th style="padding: 0.65rem;">Type</th>
                        <th style="padding: 0.65rem;">Explainable Rationale &amp; Rule Factors</th>
                        <th style="padding: 0.65rem;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recommendations as $index => $rec)
                        @php
                            $factors = is_array($rec->factors) ? $rec->factors : [];
                        @endphp
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 0.65rem; font-weight: 800;">#{{ $index + 1 }}</td>
                            <td style="padding: 0.65rem;">
                                <span style="font-weight: 800; color: #1e293b;">{{ number_format((float) $rec->priority_score, 2) }}</span>
                                <span style="font-size: 0.75rem; text-transform: uppercase; margin-left: 0.35rem; padding: 0.15rem 0.45rem; border-radius: 4px; background: #fee2e2; color: #991b1b; font-weight: 700;">
                                    {{ strtoupper($rec->priority) }}
                                </span>
                            </td>
                            <td style="padding: 0.65rem; font-weight: 700;">{{ strtoupper($rec->type) }}</td>
                            <td style="padding: 0.65rem;">
                                <div>{{ $rec->recommendation_text }}</div>
                                @if(!empty($factors['rule']))
                                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 0.25rem;">
                                        Rule: <code>{{ $factors['rule'] }}</code>
                                        @if(!empty($factors['action'])) &bull; Action: <code>{{ $factors['action'] }}</code> @endif
                                    </div>
                                @endif
                            </td>
                            <td style="padding: 0.65rem;">{{ ucfirst($rec->status) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="padding: 1rem; color: #64748b;">No open recommendations in the queue.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
    @include('partials.footer')
</body>
</html>
