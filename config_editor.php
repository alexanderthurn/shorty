<?php
require_once 'config.php';

function handleConfigEditorAction($action, $password, $content = null)
{
    // Auth Check
    // Note: UPLOAD_PASSWORD_HASH is defined in config.php
    if ($password !== UPLOAD_PASSWORD_HASH) {
        throw new Exception('Invalid password');
    }

    $file = __DIR__ . '/client_secret.json';
    $exampleFile = __DIR__ . '/client_secret.example.json';

    if ($action === 'load') {
        if (file_exists($file)) {
            return ['success' => true, 'content' => file_get_contents($file)];
        } else {
            return ['success' => true, 'content' => '{}'];
        }
    } elseif ($action === 'load_example') {
        if (file_exists($exampleFile)) {
            return ['success' => true, 'content' => file_get_contents($exampleFile)];
        } else {
            throw new Exception("Example file not found");
        }
    } elseif ($action === 'save') {
        // JSON Syntax Check
        $decoded = json_decode($content);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON formatting: " . json_last_error_msg());
        }

        // Save
        if (file_put_contents($file, $content) === false) {
            throw new Exception("Failed to write to file.");
        }

        return ['success' => true, 'message' => 'Configuration saved successfully.'];

    } else {
        throw new Exception("Unknown action: $action");
    }
}

// Direct execution
if (php_sapi_name() !== 'cli' && !defined('IN_INCLUDED_EXECUTION')) {
    header('Content-Type: application/json');
    try {
        $action = $_POST['action'] ?? '';
        $password = $_POST['password'] ?? '';
        $content = $_POST['content'] ?? null;

        $result = handleConfigEditorAction($action, $password, $content);
        echo json_encode($result);
    } catch (Exception $e) {
        http_response_code(400); // Bad Request / Error
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
