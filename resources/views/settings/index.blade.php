<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>System Settings | IntelliShop</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-5" style="max-width:1000px">
    <div class="mb-4"><h1 class="h3 mb-1">System settings</h1><p class="text-muted mb-0">Algorithm parameters and system defaults.</p></div>
    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="post" action="{{ route('settings.update') }}">@csrf @method('PUT')
        @foreach ($settings as $group => $items)
            <section class="card border-0 shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0 text-capitalize">{{ str_replace('_', ' ', $group) }}</h2></div><div class="card-body">
                @foreach ($items as $setting)
                    <div class="row align-items-start mb-3"><label class="col-md-4 col-form-label" for="setting-{{ $setting->setting_key }}"><span class="fw-semibold">{{ str_replace('_', ' ', $setting->short_key) }}</span><small class="d-block text-muted">{{ $setting->description }}</small></label><div class="col-md-8">
                        @php($value = $setting->value_type === 'json' ? json_encode($setting->typed_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $setting->typed_value)
                        @if ($setting->value_type === 'json')<textarea class="form-control font-monospace @error('settings.'.$setting->setting_key) is-invalid @enderror" rows="3" id="setting-{{ $setting->setting_key }}" name="settings[{{ $setting->setting_key }}]">{{ old('settings.'.$setting->setting_key, $value) }}</textarea>
                        @else<input class="form-control @error('settings.'.$setting->setting_key) is-invalid @enderror" id="setting-{{ $setting->setting_key }}" name="settings[{{ $setting->setting_key }}]" value="{{ old('settings.'.$setting->setting_key, $value) }}" @if ($setting->value_type === 'int') type="number" step="1" @elseif ($setting->value_type === 'decimal') type="number" step="any" @else type="text" @endif>
                        @endif
                        @error('settings.'.$setting->setting_key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div></div>
                @endforeach
            </div></section>
        @endforeach
        <button class="btn btn-primary">Save settings</button>
    </form>
</main></body></html>
