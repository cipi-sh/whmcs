<?php

declare(strict_types=1);

/**
 * Minimal HTTP client for the Cipi REST API (cipi-sh/api).
 *
 * @see https://github.com/cipi-sh/api
 * @see https://cipi.sh/docs (Advanced → cipi api)
 */
final class CipiApiClient
{
    private string $baseUrl;
    private string $token;
    private bool $secure;
    private int $timeoutSeconds;
    private ?int $lastHttpCode = null;

    public function __construct(
        string $baseUrl,
        string $token,
        bool $secure = true,
        int $timeoutSeconds = 120
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl !== '' && ! preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }
        $this->baseUrl = $baseUrl;
        $this->token = $token;
        $this->secure = $secure;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getLastHttpCode(): ?int
    {
        return $this->lastHttpCode;
    }

    /**
     * Health check: list apps (requires apps-view or similar read scope on token).
     */
    public function ping(): array
    {
        return $this->request('GET', '/api/apps');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{raw: string, decoded: mixed}
     */
    public function createApp(array $payload): array
    {
        return $this->requestRaw('POST', '/api/apps', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function editApp(string $appName, array $payload): array
    {
        return $this->requestRaw('PUT', '/api/apps/' . rawurlencode($appName), $payload);
    }

    public function deleteApp(string $appName): array
    {
        return $this->requestRaw('DELETE', '/api/apps/' . rawurlencode($appName));
    }

    public function deployApp(string $appName): array
    {
        return $this->requestRaw('POST', '/api/apps/' . rawurlencode($appName) . '/deploy');
    }

    public function getJob(string $jobId): array
    {
        return $this->request('GET', '/api/jobs/' . rawurlencode($jobId));
    }

    /**
     * Poll async job until terminal state or timeout.
     *
     * @return array{ok: bool, job: mixed, error?: string}
     */
    public function waitForJob(string $jobId, int $maxWaitSeconds = 300, int $pollSeconds = 3): array
    {
        $deadline = time() + $maxWaitSeconds;
        while (time() < $deadline) {
            $job = $this->getJob($jobId);
            $status = is_array($job) ? ($job['status'] ?? $job['state'] ?? null) : null;
            if (in_array($status, ['completed', 'success', 'failed', 'error'], true)) {
                $ok = $status === 'completed' || $status === 'success';

                return ['ok' => $ok, 'job' => $job];
            }
            sleep($pollSeconds);
        }

        return ['ok' => false, 'job' => null, 'error' => 'Timeout waiting for job ' . $jobId];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $out = $this->requestRaw($method, $path, $json);
        if (! is_array($out['decoded'])) {
            throw new RuntimeException('Unexpected API response (not JSON object).');
        }

        /** @var array<string, mixed> */
        return $out['decoded'];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{raw: string, decoded: mixed}
     */
    private function requestRaw(string $method, string $path, ?array $json = null): array
    {
        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('Base URL is empty. Set Server Hostname to your Cipi API URL.');
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if (! $this->secure) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        if ($json !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = json_encode($json, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

        $this->lastHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if ($decoded === null && trim($raw) !== '') {
            throw new RuntimeException('Invalid JSON response from API.');
        }

        return ['raw' => (string) $raw, 'decoded' => $decoded];
    }
}
