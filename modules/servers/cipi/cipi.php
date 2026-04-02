<?php

declare(strict_types=1);

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/CipiApiClient.php';

/**
 * Cipi server module — bridges WHMCS provisioning to the Cipi REST API.
 *
 * Server configuration (WHMCS → Servers):
 * - Hostname: Cipi API base URL, e.g. https://api.example.com (no trailing slash)
 * - Password: Sanctum Bearer token (create with cipi api token create on the server)
 * - Secure: enable TLS verification (recommended)
 *
 * Token abilities: at minimum apps-create, apps-view, apps-delete, deploy-manage for full lifecycle.
 *
 * @see https://github.com/cipi-sh/api
 * @see https://developers.whmcs.com/provisioning-modules/
 */

function cipi_MetaData(): array
{
    return [
        'DisplayName' => 'Cipi (Laravel hosting)',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '443',
        'DefaultSSLPort' => '443',
    ];
}

function cipi_ConfigOptions(): array
{
    return [
        'PHP Version' => [
            'Type' => 'dropdown',
            'Options' => '8.2,8.3,8.4,8.5',
            'Default' => '8.5',
            'Description' => 'PHP version for the Cipi app pool',
        ],
        'App Type' => [
            'Type' => 'dropdown',
            'Options' => 'laravel,custom',
            'Default' => 'laravel',
            'Description' => 'Laravel (Deployer) or custom (htdocs) app',
        ],
        'Git Repository (SSH)' => [
            'Type' => 'text',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Required for Laravel; optional for custom (SFTP-only if empty, Cipi 4.4.4+)',
        ],
        'Git Branch' => [
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'main',
            'Description' => 'Branch to deploy',
        ],
    ];
}

function cipi_TestConnection(array $params): array
{
    try {
        $client = cipi_buildClientFromServerParams($params);
        $client->ping();
        $code = $client->getLastHttpCode();

        return [
            'success' => $code >= 200 && $code < 300,
            'error' => $code >= 400 ? 'HTTP ' . $code : '',
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function cipi_CreateAccount(array $params): string
{
    try {
        $client = cipi_buildClientFromServiceParams($params);

        $appUser = cipi_sanitizeAppUser((string) $params['username']);
        $domain = trim((string) $params['domain']);
        if ($domain === '') {
            return 'Domain is required for Cipi app creation.';
        }

        $php = (string) ($params['configoption1'] ?? '8.5');
        $type = strtolower((string) ($params['configoption2'] ?? 'laravel'));
        $repository = trim((string) ($params['configoption3'] ?? ''));
        $branch = trim((string) ($params['configoption4'] ?? 'main')) ?: 'main';

        $isCustom = $type === 'custom';
        $payload = [
            'user' => $appUser,
            'domain' => $domain,
            'php' => $php,
            'custom' => $isCustom,
        ];

        if ($isCustom) {
            if ($repository !== '') {
                $payload['repository'] = $repository;
                $payload['branch'] = $branch;
            }
        } else {
            if ($repository === '') {
                return 'Git Repository (SSH) is required for Laravel apps.';
            }
            $payload['repository'] = $repository;
            $payload['branch'] = $branch;
        }

        $raw = $client->createApp($payload);
        $decoded = $raw['decoded'];

        if (cipi_asyncAccepted($client)) {
            $jobId = is_array($decoded) ? (string) ($decoded['job_id'] ?? $decoded['id'] ?? '') : '';
            if ($jobId !== '') {
                $wait = $client->waitForJob($jobId);
                if (! $wait['ok']) {
                    return 'Cipi job failed or timed out: ' . ($wait['error'] ?? json_encode($wait['job']));
                }
            }
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_SuspendAccount(array $params): string
{
    // cipi-sh/api: no suspend endpoint (PUT only edits php/branch/repository).
    // Returning success lets WHMCS mark the service suspended (client area / billing).
    // For technical shutdown (nginx/workers), extend via hooks or a custom endpoint — see README.
    if (function_exists('logModuleCall')) {
        logModuleCall(
            'cipi',
            'SuspendAccount',
            $params,
            'success',
            'No Cipi API call (technical suspend not exposed by the API).',
            []
        );
    }

    return 'success';
}

function cipi_UnsuspendAccount(array $params): string
{
    if (function_exists('logModuleCall')) {
        logModuleCall(
            'cipi',
            'UnsuspendAccount',
            $params,
            'success',
            'No Cipi API call (technical unsuspend not exposed by the API).',
            []
        );
    }

    return 'success';
}

function cipi_TerminateAccount(array $params): string
{
    try {
        $client = cipi_buildClientFromServiceParams($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        $raw = $client->deleteApp($appUser);
        if (cipi_asyncAccepted($client)) {
            $decoded = $raw['decoded'];
            $jobId = is_array($decoded) ? (string) ($decoded['job_id'] ?? $decoded['id'] ?? '') : '';
            if ($jobId !== '') {
                $wait = $client->waitForJob($jobId);
                if (! $wait['ok']) {
                    return 'Cipi delete job failed: ' . ($wait['error'] ?? json_encode($wait['job']));
                }
            }
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

// --- internals ---

function cipi_buildClientFromServerParams(array $params): CipiApiClient
{
    $base = (string) ($params['serverhostname'] ?? '');
    $token = (string) ($params['serverpassword'] ?? '');
    $secure = ! empty($params['serversecure']);

    return new CipiApiClient($base, $token, $secure);
}

function cipi_buildClientFromServiceParams(array $params): CipiApiClient
{
    $base = (string) ($params['serverhostname'] ?? '');
    $token = (string) ($params['serverpassword'] ?? '');
    $secure = ! empty($params['serversecure']);

    return new CipiApiClient($base, $token, $secure);
}

function cipi_sanitizeAppUser(string $username): string
{
    $u = strtolower(preg_replace('/[^a-z0-9]/', '', $username) ?? '');
    if ($u === '') {
        $u = 'app' . substr(sha1($username), 0, 8);
    }
    if (strlen($u) > 32) {
        $u = substr($u, 0, 32);
    }

    return $u;
}

function cipi_asyncAccepted(CipiApiClient $client): bool
{
    $code = $client->getLastHttpCode();

    return $code === 202;
}
