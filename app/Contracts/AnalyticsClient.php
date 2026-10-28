<?php

namespace App\Contracts;

interface AnalyticsClient
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function call(string $endpoint, array $payload): array;
}
