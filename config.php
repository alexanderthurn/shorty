<?php
require_once 'vendor/autoload.php';

// --- DEINE KONFIGURATION ---
// Wird jetzt aus client_secret.json ("app" Sektion) geladen.
// ---------------------------

// Initialer Aufruf, um Konstanten zu definieren.
// Wir definieren hier Defaults, falls client_secret.json fehlt oder unvollständig ist.
(function () {
    $secretPath = __DIR__ . '/client_secret.json';
    $authData = [];
    if (file_exists($secretPath)) {
        $authData = json_decode(file_get_contents($secretPath), true) ?: [];
    }

    // Unterstütze sowohl 'app' (neu) als auch 'app_config' (alt) für den Übergang
    $c = $authData['app'] ?? $authData['app_config'] ?? [];

    if (!defined('SHEET_ID'))
        define('SHEET_ID', $c['sheet_id'] ?? '');
    if (!defined('FOLDER_ID'))
        define('FOLDER_ID', $c['folder_id'] ?? '');
    if (!defined('SHEET_NAME'))
        define('SHEET_NAME', $c['sheet_name'] ?? 'Themen');
    if (!defined('PLAYLIST_ID'))
        define('PLAYLIST_ID', $c['playlist_id'] ?? '');

    if (!defined('NIGHTLY_MODE'))
        define('NIGHTLY_MODE', $c['nightly_mode'] ?? 'OFF');
    if (!defined('NIGHTLY_X_ACTIVE'))
        define('NIGHTLY_X_ACTIVE', $c['nightly_x_active'] ?? false);
    if (!defined('NIGHTLY_YOUTUBE_ACTIVE'))
        define('NIGHTLY_YOUTUBE_ACTIVE', $c['nightly_youtube_active'] ?? false);
})();

function getClient()
{
    $client = new Google\Client();

    // client_secret.json laden
    $secretPath = __DIR__ . '/client_secret.json';
    if (!file_exists($secretPath)) {
        throw new Exception("client_secret.json nicht gefunden: " . $secretPath);
    }

    $authData = json_decode(file_get_contents($secretPath), true);
    if (!$authData) {
        throw new Exception("Fehler beim Parsen von client_secret.json");
    }

    // 2. Google Client Auth
    if (!isset($authData['google'])) {
        throw new Exception("'google' Sektion fehlt in client_secret.json");
    }
    $client->setAuthConfig($authData['google']);

    // Diese Scopes benötigen wir für den Zugriff
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

    // Wenn der Token abgelaufen ist, erneuere ihn automatisch
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
    }
    return $client;
}