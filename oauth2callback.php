<?php
require_once 'config.php';
$client = getClient();
if (isset($_GET['code'])) {
    $accessToken = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    file_put_contents('token.json', json_encode($accessToken));
    header('Location: index.html');
}