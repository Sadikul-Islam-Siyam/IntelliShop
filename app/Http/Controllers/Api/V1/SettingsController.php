<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SettingsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(): JsonResponse
    {
        $data = SystemSetting::query()->orderBy('setting_group')->orderBy('setting_key')->get()
            ->map(fn (SystemSetting $setting) => $this->present($setting));

        return response()->json(['data' => $data]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated('settings') as $key => $value) {
            $setting = SystemSetting::query()->where('setting_key', $key)->firstOrFail();
            if ($setting->value_type === 'json' && is_string($value)) {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
            $this->settings->set($key, $value);
        }

        $data = SystemSetting::query()->orderBy('setting_group')->orderBy('setting_key')->get()
            ->map(fn (SystemSetting $setting) => $this->present($setting->fresh()));

        return response()->json(['data' => $data], Response::HTTP_OK);
    }

    private function present(SystemSetting $setting): array
    {
        return [
            'group' => $setting->setting_group,
            'key' => $setting->setting_key,
            'value' => $this->settings->get($setting->setting_key),
            'value_type' => $setting->value_type,
            'description' => $setting->description,
            'updated_at' => $setting->updated_at,
        ];
    }
}
