<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $branch->exists ? 'Edit' : 'Add' }} branch | IntelliShop</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-5" style="max-width: 760px">
    <a href="{{ route('branches.index') }}" class="text-decoration-none">&larr; Branches</a>
    <h1 class="h3 my-4">{{ $branch->exists ? 'Edit branch' : 'Add branch' }}</h1>
    <form method="post" action="{{ $branch->exists ? route('branches.update', $branch) : route('branches.store') }}" class="card card-body border-0 shadow-sm">
        @csrf @if ($branch->exists) @method('PUT') @endif
        <label class="form-label" for="name">Branch name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="100" required value="{{ old('name', $branch->name) }}">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <label class="form-label mt-3" for="address">Address</label><input class="form-control @error('address') is-invalid @enderror" id="address" name="address" maxlength="255" value="{{ old('address', $branch->address) }}">@error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <label class="form-label mt-3" for="phone">Phone</label><input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" maxlength="20" value="{{ old('phone', $branch->phone) }}">@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <label class="form-label mt-3" for="manager_id">Branch manager</label><select class="form-select @error('manager_id') is-invalid @enderror" id="manager_id" name="manager_id"><option value="">Unassigned</option>@foreach ($managers as $manager)<option value="{{ $manager->id }}" @selected(old('manager_id', $branch->manager_id) == $manager->id)>{{ $manager->name }} ({{ $manager->email }})</option>@endforeach</select>@error('manager_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @if ($branch->exists)<label class="form-label mt-3" for="status">Status</label><select class="form-select" id="status" name="status"><option value="active" @selected(old('status', $branch->status) === 'active')>Active</option><option value="inactive" @selected(old('status', $branch->status) === 'inactive')>Inactive</option></select>@endif
        <div class="mt-4"><button class="btn btn-primary">Save branch</button><a class="btn btn-outline-secondary" href="{{ route('branches.index') }}">Cancel</a></div>
    </form>
</main></body></html>
