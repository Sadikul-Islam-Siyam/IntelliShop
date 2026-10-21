<?php

namespace App\Contracts;

interface SettingsService
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;
}
