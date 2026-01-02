<?php
require_once 'config.php';
$client = getClient();
if (!file_exists('token.json')) {
    $authUrl = $client->createAuthUrl();
    echo "<a href='$authUrl'>Mit Google einloggen (Einmalig nötig)</a>";
} else {
    echo "Bereits autorisiert! <a href='index.html'>Zum Manager</a>";
}