<?php

namespace App\Http\Controllers\Web;

use App\Contracts\SettingsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\SystemSetting;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(): View
    {
        $settings = SystemSetting::query()->orderBy('setting_group')->orderBy('setting_key')->get();
        foreach ($settings as $setting) {
            $setting->typed_value = $this->settings->get($setting->setting_key);
            $setting->short_key = \Illuminate\Support\Str::after(
                $setting->setting_key,
                $setting->setting_group . '_'
            );
        }

        return view('settings.index', ['settings' => $settings->groupBy('setting_group')]);
    }

    public function update(UpdateSettingsRequest $request)
    {
        foreach ($request->validated('settings') as $key => $value) {
            $setting = SystemSetting::query()->where('setting_key', $key)->firstOrFail();
            if ($setting->value_type === 'json' && is_string($value)) {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
            $this->settings->set($key, $value);
        }

        return redirect()->route('settings.index')->with('status', 'Settings saved and audited.');
    }
}
