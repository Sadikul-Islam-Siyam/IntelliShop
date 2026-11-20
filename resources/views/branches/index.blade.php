<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Branches | IntelliShop</title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/navbar-footer.css') }}">
</head>
<body class="bg-light app-layout">
    @include('partials.navbar')

    <main class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div><h1 class="h3 mb-1">Branches</h1><p class="text-muted mb-0">Manage supermarket locations.</p></div>
            @if ($canManage)<a class="btn btn-primary" href="{{ route('branches.create') }}">Add branch</a>@endif
        </div>
        @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        <div class="card border-0 shadow-sm" id="branches-results" data-async-target>
            <div class="is-table-wrap">
                <table class="mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Manager</th>
                            <th>Status</th>
                            @if ($canManage)<th style="text-align: right;">Actions</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($branches as $branch)
                            <tr>
                                <td class="fw-semibold">{{ $branch->name }}</td>
                                <td>{{ $branch->address ?: '—' }}</td>
                                <td>{{ $branch->manager?->name ?: 'Unassigned' }}</td>
                                <td>
                                    <span class="is-badge {{ $branch->status === 'active' ? 'is-badge-success' : 'is-badge-neutral' }}">
                                        {{ ucfirst($branch->status) }}
                                    </span>
                                </td>
                                @if ($canManage)
                                    <td style="text-align: right;">
                                        <div class="is-actions-group">
                                            <a class="is-btn is-btn-sm is-btn-info" href="{{ route('branches.edit', $branch) }}">Edit</a>
                                            @if ($branch->status === 'active')
                                                <form class="d-inline" method="post" action="{{ route('branches.destroy', $branch) }}" data-async-action data-refresh-target="#branches-results" data-confirm="Deactivate this branch?" data-success-message="Branch deactivated.">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="is-btn is-btn-sm is-btn-danger">Deactivate</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="is-empty-state">
                                    <div class="is-empty-state-title">No branches found</div>
                                    <p class="is-empty-state-desc">Add a supermarket branch to get started.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="is-table-footer">
                <div>
                    @if($branches->total() > 0)
                        Showing <strong>{{ $branches->firstItem() }}</strong>–<strong>{{ $branches->lastItem() }}</strong> of <strong>{{ $branches->total() }}</strong> branches
                    @else
                        Showing 0 branches
                    @endif
                </div>
                <div>{{ $branches->links() }}</div>
            </div>
        </div>
    </main>

    @include('partials.footer')
</body>
</html>
