<?php

namespace App\Services\Analytics;

use App\Contracts\AnalyticsClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the Python analytics service.
 *
 * Calls the Python analytics service over HTTP when reachable, and seamlessly
 * falls back to executing the local CLI bridge for offline and test environments.
 */
class HttpAnalyticsClient implements AnalyticsClient
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function call(string $endpoint, array $payload): array
    {
        $normalizedEndpoint = '/' . ltrim($endpoint, '/');
        $baseUrl = rtrim((string) config('analytics_service.base_url', ''), '/');
        $token = (string) config('analytics_service.token', '');
        $timeout = max(3, (int) config('analytics_service.timeout_seconds', 15));

        // 1. Try HTTP call if baseUrl is configured and reachable
        if ($baseUrl !== '' && $this->isHttpEndpointReachable($baseUrl)) {
            $request = Http::timeout($timeout)->acceptJson();
            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $normalizedEndpoint === '/health'
                ? $request->get($baseUrl . $normalizedEndpoint)
                : $request->post($baseUrl . $normalizedEndpoint, $payload);

            if ($response->successful()) {
                return (array) $response->json();
            }

            throw new RuntimeException(
                'Analytics service returned HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        // 2. Fallback: invoke local Python analytics service CLI bridge directly
        return $this->callLocalPythonBridge($normalizedEndpoint, $payload);
    }

    private function isHttpEndpointReachable(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        if (!$parts || empty($parts['host'])) {
            return false;
        }
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));

        // Avoid blocking DNS lookup on unresolvable docker host 'analytics' when running outside Docker
        if ($host === 'analytics' && gethostbyname('analytics') === 'analytics') {
            return false;
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, 0.25);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function callLocalPythonBridge(string $endpoint, array $payload): array
    {
        $scriptPath = base_path('analytics-service/api/app.py');
        if (!is_file($scriptPath)) {
            throw new RuntimeException("Python analytics service script not found at [{$scriptPath}].");
        }

        $pythonBinary = (string) config('analytics_service.python_binary', 'python3');
        $cmd = sprintf(
            '%s %s --cli %s',
            escapeshellcmd($pythonBinary),
            escapeshellarg($scriptPath),
            escapeshellarg($endpoint)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, [
            'ANALYTICS_SERVICE_TOKEN' => '',
            'PYTHONDONTWRITEBYTECODE' => '1',
        ]);

        $process = proc_open($cmd, $descriptors, $pipes, base_path('analytics-service'), $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start Python analytics service process.');
        }

        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException("Python analytics bridge exited with code {$exitCode}: {$stderr}");
        }

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded) || !isset($decoded['body'])) {
            throw new RuntimeException("Invalid JSON response from Python analytics bridge: {$stdout}");
        }

        $statusCode = (int) ($decoded['status_code'] ?? 200);
        if ($statusCode >= 400) {
            $errMsg = $decoded['body']['error']['message'] ?? 'Analytics service error.';
            throw new RuntimeException($errMsg, $statusCode);
        }

        return (array) $decoded['body'];
    }
}
