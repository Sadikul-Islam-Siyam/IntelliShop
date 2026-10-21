<?php

namespace App\Http\Requests\Settings;

use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array', 'min:1']];

        foreach ((array) $this->input('settings', []) as $key => $value) {
            $setting = SystemSetting::query()->where('setting_key', $key)->first();
            if (!$setting) {
                continue;
            }

            $rules["settings.{$key}"] = match ($setting->value_type) {
                'int' => ['required', 'integer'],
                'decimal' => ['required', 'numeric'],
                'string' => ['required', 'string', 'max:255'],
                'json' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_array($value)) {
                        return;
                    }
                    if (!is_string($value)) {
                        $fail('The setting must be a JSON object or array.');
                        return;
                    }
                    $decoded = json_decode($value, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                        $fail('The setting must contain a valid JSON object or array.');
                    }
                }],
                default => ['prohibited'],
            };
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = SystemSetting::query()->pluck('setting_key')->all();
            foreach (array_keys((array) $this->input('settings', [])) as $key) {
                if (!in_array((string) $key, $allowed, true)) {
                    $validator->errors()->add("settings.{$key}", 'This setting key is not editable.');
                }
            }
        });
    }
}
