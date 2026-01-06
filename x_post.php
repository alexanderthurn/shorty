<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}
set_time_limit(600);

require_once 'config.php';

// --- OAUTH 1.0a SIGNING HELPER ---
if (!function_exists('get_oauth_header')) {
    function get_oauth_header($url, $method, $params, $consumer_key, $consumer_secret, $access_token, $access_secret)
    {
        $oauth = [
            'oauth_consumer_key' => $consumer_key,
            'oauth_nonce' => md5(uniqid(rand(), true)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_token' => $access_token,
            'oauth_version' => '1.0'
        ];

        $sign_params = array_merge($oauth, array_filter($params, function ($v) {
            return !is_array($v) && strpos((string) $v, '@') !== 0;
        }));
        uksort($sign_params, 'strcmp');

        $base_string = strtoupper($method) . "&" . rawurlencode($url) . "&" . rawurlencode(http_build_query($sign_params, '', '&', PHP_QUERY_RFC3986));
        $signing_key = rawurlencode($consumer_secret) . "&" . rawurlencode($access_secret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));

        uksort($oauth, 'strcmp');
        $header = 'Authorization: OAuth ';
        $values = [];
        foreach ($oauth as $k => $v)
            $values[] = rawurlencode($k) . '="' . rawurlencode($v) . '"';
        $header .= implode(', ', $values);
        return $header;
    }
}

if (!function_exists('curl_request')) {
    function curl_request($url, $method, $params, $headers = [], $is_json = false)
    {
        $ch = curl_init();
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($is_json) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                $headers[] = 'Content-Type: application/json';
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        } elseif ($method == 'GET') {
            if (!empty($params))
                $url .= '?' . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        return ['body' => json_decode($response), 'code' => $info['http_code'], 'raw' => $response];
    }
}

/**
 * Kern-Logik für den Post auf X.
 */
function postToX($videoNum, $config, $isMock = false)
{
    $client = getClient();
    $service = new Google\Service\Sheets($client);

    $sheetId = $config['sheet_id'];
    $sheetName = $config['sheet_name'] ?? 'Themen';
    $folderId = $config['folder_id'];

    $response = $service->spreadsheets_values->get($sheetId, $sheetName . '!A2:J420');
    $rows = $response->getValues();
    $metadata = null;
    $mediaId = null;
    $tweetId = null;

    foreach ($rows as $row) {
        if (isset($row[0]) && $row[0] == $videoNum) {
            $nr = $row[0];
            $title = $row[2] ?? ''; // Spalte C ist Index 2
            $desc = $row[3] ?? '';
            $tagsRaw = $row[6] ?? '';
            $tweetId = $row[8] ?? null;
            $mediaId = $row[9] ?? null;

            // Hashtags generieren
            $tagList = array_filter(array_map('trim', explode(',', $tagsRaw)));
            if (empty($tagList))
                $tagList = ['Bitcoin'];
            $hashtags = "";
            foreach ($tagList as $tag)
                $hashtags .= "#" . str_replace(' ', '', ltrim($tag, '#')) . " ";

            $metadata = ['full_text' => "Tag $nr - $title\n\n$desc\n\n" . trim($hashtags)];
            break;
        }
    }

    if (!$metadata)
        throw new Exception("Video #$videoNum nicht gefunden.");
    if ($tweetId)
        throw new Exception("Dieses Video wurde bereits auf X gepostet (ID: $tweetId)");

    if ($isMock) {
        return ['success' => true, 'message' => "[MOCK] Video #$videoNum würde jetzt gepostet werden.", 'mock' => true];
    }

    $secrets = json_decode(file_get_contents(__DIR__ . '/client_secret.json'), true)['x'];
    $ck = $secrets['consumer_key'];
    $cs = $secrets['consumer_secret'];
    $at = $secrets['access_token'];
    $as = $secrets['access_secret'];
    $upload_url = 'https://upload.twitter.com/1.1/media/upload.json';

    // --- LOGIK: REFRESH ODER NEU-UPLOAD ---
    $needsUpload = true;
    $statusResult = null;

    if ($mediaId) {
        // Status der existierenden Media-ID prüfen
        $params = ['command' => 'STATUS', 'media_id' => $mediaId];
        $auth = get_oauth_header($upload_url, 'GET', $params, $ck, $cs, $at, $as);
        $res = curl_request($upload_url, 'GET', $params, [$auth]);

        if ($res['code'] == 200 && isset($res['body']->processing_info)) {
            $state = $res['body']->processing_info->state;
            if ($state === 'succeeded' || $state === 'pending' || $state === 'in_progress') {
                $needsUpload = false;
                $statusResult = $res['body'];
            }
        }
    }

    if ($needsUpload) {
        // 1. Datei von Drive laden
        $drive = new Google\Service\Drive($client);
        $files = $drive->files->listFiles(['q' => "'" . $folderId . "' in parents and name = '$videoNum.mp4' and trashed = false"]);
        if (count($files->getFiles()) == 0)
            throw new Exception("MP4 Datei nicht gefunden.");
        $fileId = $files->getFiles()[0]->getId();
        $videoContent = $drive->files->get($fileId, ['alt' => 'media'])->getBody()->getContents();
        $tempFile = __DIR__ . '/temp/x_' . $videoNum . '.mp4';
        if (!is_dir(__DIR__ . '/temp'))
            mkdir(__DIR__ . '/temp', 0777, true);
        file_put_contents($tempFile, $videoContent);

        // 2. INIT
        $params = ['command' => 'INIT', 'media_type' => 'video/mp4', 'media_category' => 'tweet_video', 'total_bytes' => filesize($tempFile)];
        $auth = get_oauth_header($upload_url, 'POST', $params, $ck, $cs, $at, $as);
        $res = curl_request($upload_url, 'POST', $params, [$auth]);
        if (!isset($res['body']->media_id_string)) {
            if (file_exists($tempFile))
                unlink($tempFile);
            throw new Exception("X INIT Fehler: " . $res['raw']);
        }
        $mediaId = $res['body']->media_id_string;

        // 3. Media-ID SOFORT im Sheet speichern (Spalte J)
        $service->spreadsheets_values->update($sheetId, $sheetName . "!J" . ($videoNum + 1), new Google\Service\Sheets\ValueRange(['values' => [[$mediaId]]]), ['valueInputOption' => 'RAW']);

        // 4. APPEND
        $handle = fopen($tempFile, 'rb');
        $segment = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 4 * 1024 * 1024);
            if (empty($chunk))
                break;
            $params = ['command' => 'APPEND', 'media_id' => $mediaId, 'segment_index' => $segment, 'media' => base64_encode($chunk)];
            $auth = get_oauth_header($upload_url, 'POST', $params, $ck, $cs, $at, $as);
            $res = curl_request($upload_url, 'POST', $params, [$auth]);
            if ($res['code'] != 204) {
                fclose($handle);
                if (file_exists($tempFile))
                    unlink($tempFile);
                throw new Exception("X APPEND Fehler at $segment");
            }
            $segment++;
        }
        fclose($handle);

        // 5. FINALIZE
        $params = ['command' => 'FINALIZE', 'media_id' => $mediaId];
        $auth = get_oauth_header($upload_url, 'POST', $params, $ck, $cs, $at, $as);
        $statusResult = curl_request($upload_url, 'POST', $params, [$auth])['body'];
        if (file_exists($tempFile))
            unlink($tempFile);
    }

    // --- STATUS CHECK & TWEET ---
    $state = $statusResult->processing_info->state ?? 'succeeded';
    $attempts = 0;
    while (($state === 'pending' || $state === 'in_progress') && $attempts < 10) {
        $sleep = $statusResult->processing_info->check_after_secs ?? 5;
        if ($sleep > 15)
            sleep(15);
        else
            sleep($sleep);

        $params = ['command' => 'STATUS', 'media_id' => $mediaId];
        $auth = get_oauth_header($upload_url, 'GET', $params, $ck, $cs, $at, $as);
        $res = curl_request($upload_url, 'GET', $params, [$auth]);
        $statusResult = $res['body'];
        $state = $statusResult->processing_info->state ?? 'succeeded';
        $attempts++;
    }

    if ($state === 'succeeded') {
        $tweet_url = 'https://api.twitter.com/2/tweets';
        $auth = get_oauth_header($tweet_url, 'POST', [], $ck, $cs, $at, $as);
        $res = curl_request($tweet_url, 'POST', ['text' => $metadata['full_text'], 'media' => ['media_ids' => [$mediaId]]], [$auth], true);
        if ($res['code'] != 201)
            throw new Exception("X Tweet Fehler: " . $res['raw']);

        $tweetId = $res['body']->data->id;
        // Tweet-ID speichern (I) und Media-ID löschen (J)
        $service->spreadsheets_values->update(
            $sheetId,
            $sheetName . "!I" . ($videoNum + 1) . ":J" . ($videoNum + 1),
            new Google\Service\Sheets\ValueRange(['values' => [[$tweetId, '']]]),
            ['valueInputOption' => 'RAW']
        );

        return ['success' => true, 'message' => "Erfolgreich gepostet!", 'tweetId' => $tweetId];
    } else {
        return ['success' => true, 'message' => "Upload fertig. Video wird noch verarbeitet.", 'processing' => true];
    }
}

// Direkter Aufruf via HTTP oder CLI (z.B. vom Browser/Frontend oder Cron)
if (!defined('IN_NIGHTLY')) {
    try {
        if (!isset($_POST['video_num']))
            throw new Exception("Video-Nummer fehlt.");
        if (!isset($_POST['project']))
            throw new Exception("Projekt fehlt.");

        $projectId = $_POST['project'];
        $config = getProjectConfig($projectId);

        // Global Password Check
        $expectedHash = UPLOAD_PASSWORD_HASH;

        if (!isset($_POST['password']) || $_POST['password'] !== $expectedHash)
            throw new Exception("Passwort falsch.");

        $result = postToX($_POST['video_num'], $config);
        echo json_encode($result);

    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>