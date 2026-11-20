<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cycle Counts &amp; Variance Reconciliation — IntelliShop</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>
    @include('partials.navbar')
    <main class="main-content" style="max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem;">
        <div class="card" style="margin-bottom: 1.5rem;">
            <h2>Cycle Counts &amp; Physical Stock Reconciliation (#71)</h2>
            <p class="welcome-subtitle">
                Resolve phantom inventory screening alerts and audit discrepancies. Staff enter physical shelf counts; managers review, provide explanation, and approve signed inventory adjustments.
            </p>

            @if(session('success'))
                <div style="background: #dcfce7; color: #166534; padding: 0.85rem 1.25rem; border-radius: 8px; margin-top: 1rem; font-weight: 600;">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div style="background: #fee2e2; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: 8px; margin-top: 1rem;">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
                <h3 style="margin: 0;">Cycle Count Tasks</h3>
                <form method="GET" action="{{ route('cycle-counts.index') }}" style="display: flex; gap: 0.5rem; align-items: center;">
                    <select name="status" style="padding: 0.45rem 0.75rem; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <option value="">All Statuses</option>
                        <option value="open" {{ $selectedStatus === 'open' ? 'selected' : '' }}>Open</option>
                        <option value="pending_approval" {{ $selectedStatus === 'pending_approval' ? 'selected' : '' }}>Pending Approval</option>
                        <option value="approved" {{ $selectedStatus === 'approved' ? 'selected' : '' }}>Approved</option>
                        <option value="rejected" {{ $selectedStatus === 'rejected' ? 'selected' : '' }}>Rejected</option>
                    </select>
                    <button type="submit" style="padding: 0.45rem 0.9rem; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">Filter</button>
                </form>
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; background: #f8fafc; text-align: left;">
                            <th style="padding: 0.75rem 1rem;">ID</th>
                            <th style="padding: 0.75rem 1rem;">Product / SKU</th>
                            <th style="padding: 0.75rem 1rem;">Branch</th>
                            <th style="padding: 0.75rem 1rem;">Recorded Qty</th>
                            <th style="padding: 0.75rem 1rem;">Physical Qty</th>
                            <th style="padding: 0.75rem 1rem;">Variance</th>
                            <th style="padding: 0.75rem 1rem;">Trigger</th>
                            <th style="padding: 0.75rem 1rem;">Status</th>
                            <th style="padding: 0.75rem 1rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($cycleCounts as $cc)
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.75rem 1rem; font-weight: 700;">#{{ $cc->id }}</td>
                                <td style="padding: 0.75rem 1rem;">
                                    <strong>{{ $cc->product->name ?? 'Product' }}</strong>
                                    <div style="font-size: 0.75rem; color: #64748b;">SKU: {{ $cc->product->sku ?? 'N/A' }}</div>
                                </td>
                                <td style="padding: 0.75rem 1rem;">{{ $cc->branch->name ?? 'Branch' }}</td>
                                <td style="padding: 0.75rem 1rem; font-weight: 600;">{{ $cc->system_quantity }}</td>
                                <td style="padding: 0.75rem 1rem;">
                                    {{ $cc->physical_quantity !== null ? $cc->physical_quantity : '—' }}
                                </td>
                                <td style="padding: 0.75rem 1rem;">
                                    @if($cc->variance !== null)
                                        <span style="font-weight: 700; color: {{ $cc->variance < 0 ? '#dc2626' : ($cc->variance > 0 ? '#16a34a' : '#475569') }};">
                                            {{ $cc->variance > 0 ? '+'.$cc->variance : $cc->variance }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="padding: 0.75rem 1rem;">
                                    <span style="background: #e2e8f0; color: #334155; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                        {{ ucfirst(str_replace('_', ' ', $cc->trigger_source ?? 'manual')) }}
                                    </span>
                                </td>
                                <td style="padding: 0.75rem 1rem;">
                                    <span style="padding: 0.25rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;
                                        background: {{ $cc->status === 'approved' ? '#dcfce7' : ($cc->status === 'rejected' ? '#fee2e2' : ($cc->status === 'pending_approval' ? '#fef3c7' : '#e0f2fe')) }};
                                        color: {{ $cc->status === 'approved' ? '#166534' : ($cc->status === 'rejected' ? '#991b1b' : ($cc->status === 'pending_approval' ? '#92400e' : '#0369a1')) }};">
                                        {{ ucfirst(str_replace('_', ' ', $cc->status)) }}
                                    </span>
                                </td>
                                <td style="padding: 0.75rem 1rem;">
                                    @if(in_array($cc->status, ['open', 'pending_approval']))
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                            <form method="POST" action="{{ route('cycle-counts.count', $cc->id) }}" style="display: inline-flex; gap: 0.25rem;">
                                                @csrf
                                                <input type="number" name="physical_quantity" placeholder="Count" required min="0" style="width: 70px; padding: 0.3rem 0.4rem; border: 1px solid #cbd5e1; border-radius: 4px;">
                                                <button type="submit" style="padding: 0.3rem 0.6rem; background: #0284c7; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.8rem;">Save</button>
                                            </form>

                                            @if($cc->physical_quantity !== null)
                                                <form method="POST" action="{{ route('cycle-counts.approve', $cc->id) }}" style="display: inline;">
                                                    @csrf
                                                    <button type="submit" style="padding: 0.3rem 0.6rem; background: #16a34a; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.8rem;">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('cycle-counts.reject', $cc->id) }}" style="display: inline;">
                                                    @csrf
                                                    <button type="submit" style="padding: 0.3rem 0.6rem; background: #dc2626; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.8rem;">Reject</button>
                                                </form>
                                            @endif
                                        </div>
                                    @else
                                        <span style="color: #64748b; font-size: 0.8rem;">Closed</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" style="padding: 2rem; text-align: center; color: #64748b;">No cycle count tasks found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 1rem;">
                {{ $cycleCounts->links() }}
            </div>
        </div>
    </main>
    @include('partials.footer')
</body>
</html>
