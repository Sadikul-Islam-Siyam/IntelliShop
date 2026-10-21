<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Branches | IntelliShop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="h3 mb-1">Branches</h1><p class="text-muted mb-0">Manage supermarket locations.</p></div>
        @if ($canManage)<a class="btn btn-primary" href="{{ route('branches.create') }}">Add branch</a>@endif
    </div>
    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Name</th><th>Address</th><th>Manager</th><th>Status</th>@if ($canManage)<th class="text-end">Actions</th>@endif</tr></thead>
        <tbody>
        @forelse ($branches as $branch)
            <tr><td class="fw-semibold">{{ $branch->name }}</td><td>{{ $branch->address ?: '—' }}</td><td>{{ $branch->manager?->name ?: 'Unassigned' }}</td>
                <td><span class="badge text-bg-{{ $branch->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($branch->status) }}</span></td>
                @if ($canManage)<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('branches.edit', $branch) }}">Edit</a>
                    @if ($branch->status === 'active')<form class="d-inline" method="post" action="{{ route('branches.destroy', $branch) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this branch?')">Deactivate</button></form>@endif
                </td>@endif
            </tr>
        @empty<tr><td colspan="5" class="text-center text-muted py-4">No branches found.</td></tr>@endforelse
        </tbody>
    </table></div><div class="card-body">{{ $branches->links() }}</div></div>
</main>
</body>
</html>
