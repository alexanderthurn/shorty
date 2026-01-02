<?php
require_once 'vendor/autoload.php';

// --- DEINE KONFIGURATION ---
define('SHEET_ID', '15mWMEw0JrlCdnIABrUcvTfTCuLRE1coDFCg78xywIFA');
define('FOLDER_ID', '13HVjxYsIzRape4HmeivmdeaqMenNyPFO');
define('SHEET_NAME', 'Themen');
define('PLAYLIST_ID', 'PLizsgj8iO0fNQfYI7oGVGXG6XN1d4jBXP');
define('UPLOAD_PASSWORD', 'anderthurn'); // Password for the upload button
// ---------------------------

function getClient()
{
    $client = new Google\Client();

    // Nutzt direkt die Datei von Google
    $client->setAuthConfig(__DIR__ . '/client_secret.json');

    // Diese Scopes benötigen wir für den Zugriff
    $client->addScope([
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive.readonly',
        'https://www.googleapis.com/auth/youtube'
    ]);

    $client->setAccessType('offline');
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