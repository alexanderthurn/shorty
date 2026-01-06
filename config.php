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

function getClient()
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
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // Refresh Token handling
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
    }
    return $client;
}