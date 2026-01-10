<?php
require_once 'vendor/autoload.php';

// --- CONFIGURATION MANAGEMENT ---

function loadAuthData()
{
    $secretPath = __DIR__ . '/client_secret.json';
    if (!file_exists($secretPath)) {
        throw new Exception("client_secret.json not found: " . $secretPath);
    }
    return json_decode(file_get_contents($secretPath), true) ?: [];
}

// Global password check remains relevant
(function () {
    $data = loadAuthData();
    // Support legacy 'app'->'password' or old style
    $pass = $data['app']['password'] ?? $data['app']['hash'] ?? '';
    if (!defined('UPLOAD_PASSWORD_HASH')) {
        define('UPLOAD_PASSWORD_HASH', $pass);
    }
})();

/**
 * Returns the default project ID, if configured.
 */
function getDefaultProject()
{
    $data = loadAuthData();
    return $data['app']['default_project'] ?? null;
}

/**
 * Returns a list of all available projects (ID and Title).
 */
function getProjects()
{
    $data = loadAuthData();
    $projects = $data['projects'] ?? [];

    // Backwards compatibility for single-project config structure
    if (empty($projects) && isset($data['app']['sheet_id'])) {
        return [
            [
                'id' => 'default',
                'title' => 'Default Project',
            ]
        ];
    }

    $list = [];
    foreach ($projects as $p) {
        $list[] = [
            'id' => $p['id'],
            'title' => $p['title'] ?? $p['id']
        ];
    }
    return $list;
}

/**
 * Retrieves the configuration for a specific project ID.
 */
function getProjectConfig($projectId)
{
    if (!$projectId) {
        throw new Exception("No project ID specified.");
    }

    $data = loadAuthData();
    $projects = $data['projects'] ?? [];

    // Fallback logic for legacy single-project config
    if (empty($projects) && isset($data['app']['sheet_id'])) {
        if ($projectId === 'default') {
            return $data['app']; // Return the 'app' block as the project config
        }
    }

    foreach ($projects as $p) {
        if ($p['id'] === $projectId) {
            // Apply defaults if missing
            if (!isset($p['start_date']))
                $p['start_date'] = '2026-01-01 21:21:00';
            if (!isset($p['sheet_name']))
                $p['sheet_name'] = 'Themen';
            return $p;
        }
    }

    throw new Exception("Project configuration not found for ID: " . $projectId);
}

function getClient($requireToken = true)
{
    $client = new Google\Client();

    // client_secret.json laden
    $authData = loadAuthData();

    // 2. Google Client Auth
    if (!isset($authData['google'])) {
        throw new Exception("'google' section missing in client_secret.json");
    }
    $client->setAuthConfig($authData['google']);

    // Scopes
    $client->setScopes([
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive.readonly',
        'https://www.googleapis.com/auth/youtube.force-ssl'
    ]);

    $client->setAccessType('offline');
    $client->setApprovalPrompt('force');
    $client->setPrompt('select_account consent');

    $tokenPath = __DIR__ . '/token.json';
    $tokenLoaded = false;

    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
        $tokenLoaded = true;
    }

    if (!$tokenLoaded) {
        if ($requireToken) {
            throw new Exception("GOOGLE TOKEN EXPIRED (Missing). Please visit auth.php to login.");
        }
        // If not requiring token (e.g. auth.php), return client as is to allow auth flow
        return $client;
    }

    // Refresh Token handling - only if we have a token
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            try {
                $check = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                if (isset($check['error'])) {
                    throw new Exception("Refresh failed: " . ($check['error_description'] ?? $check['error']));
                }
                file_put_contents($tokenPath, json_encode($client->getAccessToken()));
            } catch (Exception $e) {
                $msg = $e->getMessage();
                // Check for common OAuth errors
                if (strpos($msg, 'invalid_grant') !== false || strpos($msg, 'expired or revoked') !== false) {
                    throw new Exception("GOOGLE TOKEN EXPIRED/REVOKED. Please delete 'token.json' and visit auth.php to re-login.");
                }
                throw $e;
            }
        } else {
            // Token expired and no refresh token
            throw new Exception("Google Token expired and no Refresh Token found. Please re-login via auth.php.");
        }
    }
    return $client;
}