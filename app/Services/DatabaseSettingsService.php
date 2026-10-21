<?php

namespace App\Services;

use App\Contracts\SettingsService;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DatabaseSettingsService implements SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever($this->cacheKey($key), function () use ($key, $default): mixed {
            $setting = SystemSetting::query()->where('setting_key', $key)->first();

            return $setting ? $this->decode($setting) : $default;
        });
    }

    public function set(string $key, mixed $value): void
    {
        DB::transaction(function () use ($key, $value): void {
            $setting = SystemSetting::query()->where('setting_key', $key)->lockForUpdate()->firstOrFail();
            $encoded = $this->encode($setting->value_type, $value);
            $previous = $this->decode($setting);

            if ((string) $setting->setting_value === $encoded) {
                return;
            }

            $setting->update([
                'setting_value' => $encoded,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);

            AuditLogger::log(
                auth()->id(),
                'settings.update',
                'system_settings',
                $setting->id,
                ['key' => $key, 'old_value' => $previous, 'new_value' => $this->decode($setting->fresh())]
            );
        });

        Cache::forget($this->cacheKey($key));
    }

    private function decode(SystemSetting $setting): mixed
    {
        return match ($setting->value_type) {
            'int' => (int) $setting->setting_value,
            'decimal' => (float) $setting->setting_value,
            'json' => json_decode((string) $setting->setting_value, true, 512, JSON_THROW_ON_ERROR),
            default => $setting->setting_value,
        };
    }

    private function encode(string $type, mixed $value): string
    {
        return match ($type) {
            'int' => $this->encodeInteger($value),
            'decimal' => $this->encodeDecimal($value),
            'json' => $this->encodeJson($value),
            default => $this->encodeString($value),
        };
    }

    private function encodeInteger(mixed $value): string
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
            throw ValidationException::withMessages(['value' => 'This setting must be an integer.']);
        }

        return (string) $value;
    }

    private function encodeDecimal(mixed $value): string
    {
        if (!is_numeric($value)) {
            throw ValidationException::withMessages(['value' => 'This setting must be numeric.']);
        }

        return (string) (float) $value;
    }

    private function encodeJson(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ValidationException::withMessages(['value' => 'This setting must contain valid JSON.']);
            }
            $value = $decoded;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function encodeString(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw ValidationException::withMessages(['value' => 'This setting must be a scalar value.']);
        }

        return (string) $value;
    }

    private function cacheKey(string $key): string
    {
        return 'settings.value.' . $key;
    }
}
