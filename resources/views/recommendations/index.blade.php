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
                <form method="POST" action="{{ route('recommendations.run') }}" data-async-action data-refresh-target="#recommendations-results" data-success-message="Recommendation engine executed.">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                    <button type="submit" data-loading-text="Running Rules..." style="background: #2563eb; color: #fff; border: none; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 700; cursor: pointer;">
                        Run Expert Rules
                    </button>
                </form>
            </div>
        </div>

        <div class="card" id="recommendations-results" data-async-target style="padding: 0; overflow: hidden;">
            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Ordered Recommendation Inbox</h3>
                <span class="is-badge is-badge-info">{{ $recommendations->count() }} queued</span>
            </div>
            <div class="is-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Priority</th>
                            <th>Type</th>
                            <th>Explainable Rationale &amp; Rule Factors</th>
                            <th>Status</th>
                            <th style="text-align: right;">Review Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recommendations as $index => $rec)
                            @php
                                $factors = is_array($rec->factors) ? $rec->factors : [];
                                $prioClass = match(strtolower((string) $rec->priority)) {
                                    'high', 'critical' => 'is-badge-danger',
                                    'medium' => 'is-badge-warning',
                                    default => 'is-badge-success',
                                };
                                $stClass = match(strtolower((string) $rec->status)) {
                                    'approved', 'applied' => 'is-badge-success',
                                    'dismissed', 'rejected' => 'is-badge-neutral',
                                    default => 'is-badge-info',
                                };
                            @endphp
                            <tr>
                                <td style="font-weight: 800;">#{{ $index + 1 }}</td>
                                <td>
                                    <span style="font-weight: 800; color: #0f172a; margin-right: 0.4rem;">{{ number_format((float) $rec->priority_score, 2) }}</span>
                                    <span class="is-badge {{ $prioClass }}">{{ strtoupper($rec->priority) }}</span>
                                </td>
                                <td style="font-weight: 700; font-size: 0.8rem;">{{ strtoupper(str_replace('_', ' ', $rec->type)) }}</td>
                                <td>
                                    <div>{{ $rec->recommendation_text }}</div>
                                    @if(!empty($factors['rule']))
                                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 0.25rem;">
                                            Rule: <code>{{ $factors['rule'] }}</code>
                                            @if(!empty($factors['action'])) &bull; Action: <code>{{ $factors['action'] }}</code> @endif
                                        </div>
                                    @endif
                                </td>
                                <td><span class="is-badge {{ $stClass }}">{{ ucfirst($rec->status) }}</span></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    @if(in_array(strtolower((string) $rec->status), ['open', 'pending'], true))
                                        <div style="display: inline-flex; gap: 0.4rem;">
                                            <form method="POST" action="{{ route('recommendations.review', $rec->id) }}" style="display: inline;">
                                                @csrf
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" style="padding: 0.3rem 0.65rem; background: #16a34a; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.8rem;">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('recommendations.review', $rec->id) }}" style="display: inline;">
                                                @csrf
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" style="padding: 0.3rem 0.65rem; background: #dc2626; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.8rem;">Reject</button>
                                            </form>
                                        </div>
                                    @else
                                        <span style="font-size: 0.8rem; color: #64748b; font-weight: 600;">Reviewed</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="is-empty-state">
                                    <div class="is-empty-state-title">No open recommendations in the queue</div>
                                    <p class="is-empty-state-desc">Click <strong>Run Expert Rules</strong> above to evaluate current stock, markdown, and anomaly signals.</p>
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
