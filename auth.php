<?php
require_once 'config.php';
try {
    $client = getClient(false);
} catch (Exception $e) {
    // If we catch an error (like expired token), delete the token file and retry
    if (file_exists(__DIR__ . '/token.json')) {
        unlink(__DIR__ . '/token.json');
        try {
            // Get fresh client without token
            $client = getClient(false);
            $tokenReset = true;
        } catch (Exception $ex) {
            die("Critical Auth Error: " . $ex->getMessage());
        }
    } else {
        die("Error processing client: " . $e->getMessage());
    }
}

if (!file_exists(__DIR__ . '/token.json') || isset($_GET['new'])) {
    $authUrl = $client->createAuthUrl();
    echo "<h1>Google Login</h1>";
    if (isset($tokenReset) || isset($e)) {
        echo "<p style='color:red; font-weight:bold;'>Previous token was invalid/expired and has been reset. Please login again.</p>";
    }
    echo "<a href='$authUrl' style='font-size:1.2em'>Login with Google (One-time setup)</a>";
} else {
    echo "<h1>Authorized</h1>";
    echo "Successfully connected to Google API.<br><br>";
    echo "<a href='index.html'>Go to Shorty Manager</a> <br><br><br>";
    echo "<small><a href='?new=1'>Re-connect / Switch Account</a></small>";
}