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

    private static array $reachabilityCache = [];

    private function isHttpEndpointReachable(string $baseUrl): bool
    {
        if (array_key_exists($baseUrl, self::$reachabilityCache)) {
            return self::$reachabilityCache[$baseUrl];
        }

        $parts = parse_url($baseUrl);
        if (!$parts || empty($parts['host'])) {
            return self::$reachabilityCache[$baseUrl] = false;
        }

        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));

        // When running on Windows host outside Docker, the docker compose name 'analytics' is never reachable.
        // Avoid slow Windows NetBIOS/DNS resolution timeouts that block the page request.
        if ($host === 'analytics' && (PHP_OS_FAMILY === 'Windows' || !file_exists('/.dockerenv'))) {
            return self::$reachabilityCache[$baseUrl] = false;
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, 0.1);
        if (is_resource($fp)) {
            fclose($fp);
            return self::$reachabilityCache[$baseUrl] = true;
        }

        return self::$reachabilityCache[$baseUrl] = false;
    }

    private static ?string $resolvedPythonBinary = null;

    /**
     * Resolve a working Python executable across Linux/Docker and Windows environments.
     */
    private function resolvePythonBinary(): string
    {
        if (self::$resolvedPythonBinary !== null) {
            return self::$resolvedPythonBinary;
        }

        $configured = (string) config('analytics_service.python_binary', 'python3');
        $candidates = array_values(array_unique(array_filter([
            $configured,
            'C:\\msys64\\ucrt64\\bin\\python.exe',
            'C:\\Python312\\python.exe',
            'C:\\Python311\\python.exe',
            'python3',
            'python',
        ])));

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && !str_contains(strtolower($candidate), 'windowsapps')) {
                self::$resolvedPythonBinary = $candidate;
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = @proc_open(escapeshellcmd($candidate) . ' -c "import sys; print(sys.executable)"', $descriptors, $pipes);
            if (is_resource($proc)) {
                @fclose($pipes[0]);
                $out = trim((string) @stream_get_contents($pipes[1]));
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                $code = @proc_close($proc);
                if ($code === 0 && $out !== '' && !str_contains(strtolower($out), 'windowsapps')) {
                    self::$resolvedPythonBinary = $out;
                    return $out;
                }
            }
        }

        self::$resolvedPythonBinary = $configured;
        return $configured;
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

        $pythonBinary = $this->resolvePythonBinary();
        $cmd = sprintf(
            '%s %s --cli %s',
            escapeshellarg($pythonBinary),
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

        $process = @proc_open($cmd, $descriptors, $pipes, base_path('analytics-service'), $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start Python analytics service process.');
        }

        @fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
        @fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        @fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        @fclose($pipes[2]);

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
