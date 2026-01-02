<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

header('Content-Type: application/json; charset=utf-8');
set_time_limit(600);

require_once 'config.php';

// --- OAUTH 1.0a SIGNING HELPER ---
function get_oauth_header($url, $method, $params, $consumer_key, $consumer_secret, $access_token, $access_secret, $is_multipart = false)
{
    $oauth = [
        'oauth_consumer_key' => $consumer_key,
        'oauth_nonce' => md5(uniqid(rand(), true)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => time(),
        'oauth_token' => $access_token,
        'oauth_version' => '1.0'
    ];

    // For signing, we only use non-file parameters
    $sign_params = array_merge($oauth, array_filter($params, function ($v) {
        return !is_array($v) && strpos($v, '@') !== 0;
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
    } else {
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

try {
    $client = getClient();
    if (!isset($_POST['video_num']))
        throw new Exception("Video-Nummer fehlt.");
    if (!isset($_POST['password']) || $_POST['password'] !== UPLOAD_PASSWORD)
        throw new Exception("Passwort falsch.");

    $videoNum = $_POST['video_num'];

    // 1. Metadata & Drive
    $service = new Google\Service\Sheets($client);
    $response = $service->spreadsheets_values->get(SHEET_ID, SHEET_NAME . '!A2:I420');
    $rows = $response->getValues();
    $metadata = null;
    foreach ($rows as $row) {
        if (isset($row[0]) && $row[0] == $videoNum) {
            $metadata = ['desc' => $row[3] ?? ''];
            break;
        }
    }
    if (!$metadata)
        throw new Exception("Video #$videoNum nicht gefunden.");

    $drive = new Google\Service\Drive($client);
    $files = $drive->files->listFiles(['q' => "'" . FOLDER_ID . "' in parents and name = '$videoNum.mp4' and trashed = false"]);
    if (count($files->getFiles()) == 0)
        throw new Exception("MP4 Datei nicht gefunden.");
    $fileId = $files->getFiles()[0]->getId();
    $videoContent = $drive->files->get($fileId, ['alt' => 'media'])->getBody()->getContents();

    $tempFile = __DIR__ . '/temp/x_' . $videoNum . '.mp4';
    if (!is_dir(__DIR__ . '/temp'))
        mkdir(__DIR__ . '/temp', 0777, true);
    file_put_contents($tempFile, $videoContent);

    // 2. X Credentials
    $secrets = json_decode(file_get_contents(__DIR__ . '/client_secret.json'), true)['x'];
    $ck = $secrets['consumer_key'];
    $cs = $secrets['consumer_secret'];
    $at = $secrets['access_token'];
    $as = $secrets['access_secret'];

    // 3. X Video Upload (v1.1)
    $upload_url = 'https://upload.twitter.com/1.1/media/upload.json';

    // - INIT -
    $params = ['command' => 'INIT', 'media_type' => 'video/mp4', 'media_category' => 'tweet_video', 'total_bytes' => filesize($tempFile)];
    $auth = get_oauth_header($upload_url, 'POST', $params, $ck, $cs, $at, $as);
    $res = curl_request($upload_url, 'POST', $params, [$auth]);
    if ($res['code'] < 200 || $res['code'] >= 300)
        throw new Exception("X INIT Fehler (" . $res['code'] . "): " . $res['raw']);
    $mediaId = $res['body']->media_id_string;

    // - APPEND -
    $handle = fopen($tempFile, 'rb');
    $segment = 0;
    while (!feof($handle)) {
        $chunk = fread($handle, 4 * 1024 * 1024); // 4MB
        if (empty($chunk))
            break;
        $params = ['command' => 'APPEND', 'media_id' => $mediaId, 'segment_index' => $segment, 'media' => base64_encode($chunk)];
        $auth = get_oauth_header($upload_url, 'POST', $params, $ck, $cs, $at, $as);
        $res = curl_request($upload_url, 'POST', $params, [$auth]);
        if ($res['code'] < 200 || $res['code'] >= 300)
            throw new Exception("X APPEND Fehler at $segment (" . $res['code'] . "): " . $res['raw']);
        $segment++;
    }
    fclose($handle);

    // - FINALIZE -
    $params = ['command' => 'FINALIZE', 'media_id' => $mediaId];
    $auth = get_oauth_header($upload_url, 'POST', $params, $ck, $cs, $at, $as);
    $res = curl_request($upload_url, 'POST', $params, [$auth]);
    if ($res['code'] < 200 || $res['code'] >= 300)
        throw new Exception("X FINALIZE Fehler (" . $res['code'] . "): " . $res['raw']);

    // - STATUS POLLING -
    $status = 'pending';
    if (isset($res['body']->processing_info)) {
        $status = $res['body']->processing_info->state;
        $attempts = 0;
        while (($status === 'pending' || $status === 'in_progress') && $attempts < 30) {
            $sleep = $res['body']->processing_info->check_after_secs ?? 5;
            sleep($sleep);

            $params = ['command' => 'STATUS', 'media_id' => $mediaId];
            $auth = get_oauth_header($upload_url, 'GET', $params, $ck, $cs, $at, $as);
            $res = curl_request($upload_url, 'GET', $params, [$auth]);

            $status = $res['body']->processing_info->state ?? 'succeeded';
            if ($status === 'failed') {
                throw new Exception("X Video Processing Error: " . json_encode($res['body']->processing_info->error));
            }
            $attempts++;
        }
    }

    // 4. Create Tweet (v2)
    $tweet_url = 'https://api.twitter.com/2/tweets';
    $tweet_params = ['text' => $metadata['desc'], 'media' => ['media_ids' => [$mediaId]]];
    // Signature for v2 with JSON body is just the base URL (X spec)
    $auth = get_oauth_header($tweet_url, 'POST', [], $ck, $cs, $at, $as);
    $res = curl_request($tweet_url, 'POST', $tweet_params, [$auth], true);

    if ($res['code'] != 201)
        throw new Exception("X Tweet Fehler (" . $res['code'] . "): " . $res['raw']);
    $tweetId = $res['body']->data->id;

    // 5. Update Sheet
    $values = [[$tweetId]];
    $body = new Google\Service\Sheets\ValueRange(['values' => $values]);
    $service->spreadsheets_values->update(SHEET_ID, SHEET_NAME . "!I" . ($videoNum + 1), $body, ['valueInputOption' => 'RAW']);

    if (file_exists($tempFile))
        unlink($tempFile);
    echo json_encode(['success' => true, 'message' => "Video #$videoNum erfolgreich gepostet!", 'tweetId' => $tweetId]);

} catch (Throwable $e) {
    if (isset($tempFile) && file_exists($tempFile))
        unlink($tempFile);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
