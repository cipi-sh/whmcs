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
 * Token abilities required for full functionality:
 * apps-create, apps-view, apps-delete, apps-edit, deploy-manage, ssl-manage,
 * aliases-view, aliases-create, aliases-delete, dbs-view, dbs-create, dbs-delete, dbs-manage
 *
 * @see https://github.com/cipi-sh/api
 * @see https://developers.whmcs.com/provisioning-modules/
 */

// ── Metadata & Config ────────────────────────────────────────────────

function cipi_MetaData(): array
{
    return [
        'DisplayName' => 'Cipi (Laravel hosting)',
        'APIVersion' => '1.6',
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
        'Auto SSL' => [
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'Install Let\'s Encrypt SSL automatically after app creation',
        ],
        'Alias domain (admin)' => [
            'Type' => 'text',
            'Size' => '60',
            'Default' => '',
            'Description' => 'For Add/Remove Alias buttons: full hostname (e.g. www.example.com). Save the service before clicking.',
        ],
    ];
}

// ── Core lifecycle ───────────────────────────────────────────────────

function cipi_TestConnection(array $params): array
{
    try {
        $client = cipi_buildClient($params);
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
        $client = cipi_buildClient($params);

        $appUser = cipi_sanitizeAppUser((string) $params['username']);
        $domain = trim((string) $params['domain']);
        if ($domain === '') {
            return 'Domain is required for Cipi app creation.';
        }

        $php = (string) ($params['configoption1'] ?? '8.5');
        $type = strtolower((string) ($params['configoption2'] ?? 'laravel'));
        $repository = trim((string) ($params['configoption3'] ?? ''));
        $branch = trim((string) ($params['configoption4'] ?? 'main')) ?: 'main';
        $autoSsl = ! empty($params['configoption5']);

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
        $httpCode = $client->getLastHttpCode();

        cipi_log('CreateAccount', $payload, $raw['raw'], $decoded);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($decoded);
        }

        $error = cipi_waitIfAsync($client, $decoded);
        if ($error !== null) {
            return $error;
        }

        if ($autoSsl) {
            $sslResult = cipi_doInstallSsl($client, $appUser);
            if ($sslResult !== 'success') {
                cipi_log('CreateAccount:AutoSSL', ['app' => $appUser], $sslResult, 'SSL install failed after app creation (app was created).');
            }
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_SuspendAccount(array $params): string
{
    cipi_log('SuspendAccount', $params, 'success', 'No Cipi API call (technical suspend not exposed by the API).');

    return 'success';
}

function cipi_UnsuspendAccount(array $params): string
{
    cipi_log('UnsuspendAccount', $params, 'success', 'No Cipi API call (technical unsuspend not exposed by the API).');

    return 'success';
}

function cipi_TerminateAccount(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        $raw = $client->deleteApp($appUser);
        $decoded = $raw['decoded'];

        cipi_log('TerminateAccount', ['app' => $appUser], $raw['raw'], $decoded);

        $error = cipi_waitIfAsync($client, $decoded);
        if ($error !== null) {
            return $error;
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_ChangePackage(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        $php = (string) ($params['configoption1'] ?? '');
        $repository = trim((string) ($params['configoption3'] ?? ''));
        $branch = trim((string) ($params['configoption4'] ?? ''));

        $payload = [];
        if ($php !== '') {
            $payload['php'] = $php;
        }
        if ($repository !== '') {
            $payload['repository'] = $repository;
        }
        if ($branch !== '') {
            $payload['branch'] = $branch;
        }

        if ($payload === []) {
            return 'success';
        }

        $raw = $client->editApp($appUser, $payload);
        $decoded = $raw['decoded'];
        $httpCode = $client->getLastHttpCode();

        cipi_log('ChangePackage', $payload, $raw['raw'], $decoded);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($decoded);
        }

        $error = cipi_waitIfAsync($client, $decoded);
        if ($error !== null) {
            return $error;
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

// ── Admin custom buttons ─────────────────────────────────────────────

function cipi_AdminCustomButtonArray(): array
{
    return [
        'Install SSL' => 'installSsl',
        'Deploy' => 'deploy',
        'Rollback Deploy' => 'rollbackDeploy',
        'Unlock Deploy' => 'unlockDeploy',
        'List Aliases' => 'listAliases',
        'Add Alias' => 'addAlias',
        'Remove Alias' => 'removeAlias',
        'App Info' => 'appInfo',
    ];
}

function cipi_installSsl(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        return cipi_doInstallSsl($client, $appUser);
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_deploy(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        $raw = $client->deployApp($appUser);
        $decoded = $raw['decoded'];
        $httpCode = $client->getLastHttpCode();

        cipi_log('Deploy', ['app' => $appUser], $raw['raw'], $decoded);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($decoded);
        }

        $error = cipi_waitIfAsync($client, $decoded);
        if ($error !== null) {
            return $error;
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_rollbackDeploy(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        $raw = $client->rollbackDeploy($appUser);
        $decoded = $raw['decoded'];
        $httpCode = $client->getLastHttpCode();

        cipi_log('RollbackDeploy', ['app' => $appUser], $raw['raw'], $decoded);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($decoded);
        }

        $error = cipi_waitIfAsync($client, $decoded);
        if ($error !== null) {
            return $error;
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_unlockDeploy(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        $raw = $client->unlockDeploy($appUser);
        $decoded = $raw['decoded'];
        $httpCode = $client->getLastHttpCode();

        cipi_log('UnlockDeploy', ['app' => $appUser], $raw['raw'], $decoded);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($decoded);
        }

        $error = cipi_waitIfAsync($client, $decoded);
        if ($error !== null) {
            return $error;
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_appInfo(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        $app = $client->getApp($appUser);
        $httpCode = $client->getLastHttpCode();

        cipi_log('AppInfo', ['app' => $appUser], json_encode($app), $app);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($app);
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_listAliases(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);

        $list = $client->listAliases($appUser);
        $httpCode = $client->getLastHttpCode();

        cipi_log('ListAliases', ['app' => $appUser], json_encode($list), $list);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($list);
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_addAlias(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);
        $alias = cipi_normalizeAliasDomain((string) ($params['configoption6'] ?? ''));
        $invalid = cipi_validateAliasDomain($alias);
        if ($invalid !== null) {
            return $invalid;
        }

        $raw = $client->addAlias($appUser, $alias);
        $decoded = $raw['decoded'];
        $httpCode = $client->getLastHttpCode();

        cipi_log('AddAlias', ['app' => $appUser, 'alias' => $alias], $raw['raw'], $decoded);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($decoded);
        }

        $error = cipi_waitIfAsync($client, $decoded);
        if ($error !== null) {
            return $error;
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

function cipi_removeAlias(array $params): string
{
    try {
        $client = cipi_buildClient($params);
        $appUser = cipi_sanitizeAppUser((string) $params['username']);
        $alias = cipi_normalizeAliasDomain((string) ($params['configoption6'] ?? ''));
        $invalid = cipi_validateAliasDomain($alias);
        if ($invalid !== null) {
            return $invalid;
        }

        $raw = $client->removeAlias($appUser, $alias);
        $decoded = $raw['decoded'];
        $httpCode = $client->getLastHttpCode();

        cipi_log('RemoveAlias', ['app' => $appUser, 'alias' => $alias], $raw['raw'], $decoded);

        if ($httpCode >= 400) {
            return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($decoded);
        }

        $error = cipi_waitIfAsync($client, $decoded);
        if ($error !== null) {
            return $error;
        }

        return 'success';
    } catch (Throwable $e) {
        return 'Cipi API error: ' . $e->getMessage();
    }
}

// ── Internals ────────────────────────────────────────────────────────

function cipi_buildClient(array $params): CipiApiClient
{
    $base = (string) ($params['serverhostname'] ?? '');
    $token = (string) ($params['serverpassword'] ?? '');
    $secure = ! empty($params['serversecure']);

    return new CipiApiClient($base, $token, $secure);
}

function cipi_sanitizeAppUser(string $username): string
{
    $u = preg_replace('/[^a-z0-9]/', '', strtolower($username)) ?? '';
    if ($u === '') {
        $u = 'app' . substr(sha1($username), 0, 8);
    }
    if (strlen($u) > 32) {
        $u = substr($u, 0, 32);
    }

    return $u;
}

/**
 * Normalise user input for Cipi alias domains (trim, strip scheme, lowercase ASCII labels).
 */
function cipi_normalizeAliasDomain(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $s) === 1) {
        $host = parse_url($s, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $s = $host;
        }
    }
    $s = rtrim($s, '/');
    $s = strtolower($s);

    return $s;
}

/**
 * Basic validation before calling the API. Returns an error message or null if OK.
 */
function cipi_validateAliasDomain(string $domain): ?string
{
    if ($domain === '') {
        return 'Set "Alias domain (admin)" to the full hostname (e.g. www.example.com), save the service, then use Add/Remove Alias.';
    }
    if (strlen($domain) > 253) {
        return 'Alias domain is too long (max 253 characters).';
    }
    if (preg_match('/[\s\x00-\x1f\x7f]/', $domain) === 1) {
        return 'Alias domain must not contain whitespace or control characters.';
    }
    if (preg_match('/^[a-z0-9._-]+$/', $domain) !== 1) {
        return 'Alias domain contains invalid characters. Use letters, numbers, dots, hyphens, and underscores (punycode for IDN).';
    }
    if (! str_contains($domain, '.')) {
        return 'Alias domain must be a fully qualified hostname (include at least one dot, e.g. www.example.com).';
    }

    return null;
}

/**
 * If the last API call returned 202, extract the job ID and wait for completion.
 * Returns an error string on failure, null on success or when not async.
 */
function cipi_waitIfAsync(CipiApiClient $client, mixed $decoded): ?string
{
    if ($client->getLastHttpCode() !== 202) {
        return null;
    }

    $jobId = is_array($decoded) ? (string) ($decoded['job_id'] ?? $decoded['id'] ?? '') : '';
    if ($jobId === '') {
        return null;
    }

    $wait = $client->waitForJob($jobId);
    if (! $wait['ok']) {
        return 'Cipi job failed or timed out: ' . ($wait['error'] ?? json_encode($wait['job']));
    }

    return null;
}

function cipi_doInstallSsl(CipiApiClient $client, string $appUser): string
{
    $raw = $client->installSsl($appUser);
    $decoded = $raw['decoded'];
    $httpCode = $client->getLastHttpCode();

    cipi_log('InstallSSL', ['app' => $appUser], $raw['raw'], $decoded);

    if ($httpCode >= 400) {
        return 'Cipi API returned HTTP ' . $httpCode . cipi_extractApiMessage($decoded);
    }

    $error = cipi_waitIfAsync($client, $decoded);
    if ($error !== null) {
        return $error;
    }

    return 'success';
}

function cipi_log(string $action, mixed $request, mixed $response, mixed $processed, array $replaceVars = []): void
{
    if (function_exists('logModuleCall')) {
        logModuleCall('cipi', $action, $request, $response, $processed, $replaceVars);
    }
}

function cipi_extractApiMessage(mixed $decoded): string
{
    if (! is_array($decoded)) {
        return '';
    }
    $msg = (string) ($decoded['message'] ?? $decoded['error'] ?? '');

    return $msg !== '' ? ': ' . $msg : '';
}
