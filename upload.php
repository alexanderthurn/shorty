<?php
require_once 'config.php';

/**
 * Kern-Logik für den Video-Upload zu YouTube.
 */
function uploadToYouTube($videoNum, $isMock = false)
{
    $client = getClient();
    $service = new Google\Service\Sheets($client);

    // 1. Daten aus Google Sheet holen
    $response = $service->spreadsheets_values->get(SHEET_ID, SHEET_NAME . '!A2:H420');
    $rows = $response->getValues();
    $metadata = null;

    foreach ($rows as $row) {
        if (isset($row[0]) && $row[0] == $videoNum) {
            // Tags extrahieren (Spalte G = Index 6)
            $tagString = $row[6] ?? '';
            $tags = array_filter(array_map('trim', explode(',', $tagString)));
            if (empty($tags)) {
                $tags = ['Bitcoin'];
            }

            $metadata = [
                'title' => "Tag $videoNum - " . ($row[2] ?? 'Bitcoin Short #' . $videoNum),
                'desc' => $row[3] ?? '', // Spalte D = Index 3
                'tags' => $tags,
                'isUploaded' => !empty($row[7]) // Spalte H = Index 7
            ];
            break;
        }
    }

    if (!$metadata) {
        throw new Exception("Video-Nummer $videoNum nicht im Sheet gefunden.");
    }

    if ($metadata['isUploaded']) {
        throw new Exception("Video #$videoNum wurde bereits auf YouTube hochgeladen.");
    }

    if ($isMock) {
        return [
            'success' => true,
            'message' => "[MOCK] Video #$videoNum würde jetzt zu YouTube hochgeladen werden.",
            'mock' => true
        ];
    }

    // 2. Datum berechnen
    $publishDate = new DateTime('2026-01-01 21:21:00');
    $publishDate->modify('+' . ($videoNum - 1) . ' days');
    $publishStr = $publishDate->format(DateTime::RFC3339);

    // 3. Datei von Google Drive laden
    $drive = new Google\Service\Drive($client);
    $fileName = $videoNum . '.mp4';
    $files = $drive->files->listFiles([
        'q' => "'" . FOLDER_ID . "' in parents and name = '$fileName' and trashed = false",
        'fields' => 'files(id, name)'
    ]);

    if (count($files->getFiles()) == 0) {
        throw new Exception("Datei $fileName nicht gefunden.");
    }

    $driveFile = $files->getFiles()[0];
    $fileId = $driveFile->getId();

    $content = $drive->files->get($fileId, ['alt' => 'media']);
    $tempDir = __DIR__ . '/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    $tempFile = $tempDir . '/temp_' . $videoNum . '.mp4';
    file_put_contents($tempFile, $content->getBody()->getContents());

    // 4. YouTube Upload
    $youtube = new Google\Service\YouTube($client);
    $video = new Google\Service\YouTube\Video();

    $snippet = new Google\Service\YouTube\VideoSnippet();
    $snippet->setTitle($metadata['title']);
    $snippet->setDescription($metadata['desc']);
    $snippet->setTags($metadata['tags']);
    $snippet->setDefaultLanguage('de');
    $snippet->setDefaultAudioLanguage('de');
    $video->setSnippet($snippet);

    $status = new Google\Service\YouTube\VideoStatus();
    $status->setPrivacyStatus('private');
    $status->setPublishAt($publishStr);
    $video->setStatus($status);

    $recordingDetails = new Google\Service\YouTube\VideoRecordingDetails();
    $recordingDetails->setRecordingDate($publishStr);
    $video->setRecordingDetails($recordingDetails);

    $result = $youtube->videos->insert('snippet,status,recordingDetails', $video, [
        'data' => file_get_contents($tempFile),
        'mimeType' => 'video/mp4',
        'uploadType' => 'multipart'
    ]);

    $videoId = $result->getId();

    // 4.5 Video zur Playlist hinzufügen
    $playlistItem = new Google\Service\YouTube\PlaylistItem();
    $playlistSnippet = new Google\Service\YouTube\PlaylistItemSnippet();
    $playlistSnippet->setPlaylistId(PLAYLIST_ID);
    $resourceId = new Google\Service\YouTube\ResourceId();
    $resourceId->setKind('youtube#video');
    $resourceId->setVideoId($videoId);
    $playlistSnippet->setResourceId($resourceId);
    $playlistItem->setSnippet($playlistSnippet);
    $youtube->playlistItems->insert('snippet', $playlistItem);

    // --- Video-ID zurück ins Sheet schreiben (Spalte H) ---
    $values = [[$videoId]];
    $body = new Google\Service\Sheets\ValueRange(['values' => $values]);
    $params = ['valueInputOption' => 'RAW'];
    $rowInSheet = $videoNum + 1;
    $service->spreadsheets_values->update(SHEET_ID, SHEET_NAME . "!H$rowInSheet", $body, $params);

    // 5. Optionaler SRT Upload
    $srtName = $videoNum . '.srt';
    $srtFiles = $drive->files->listFiles(['q' => "'" . FOLDER_ID . "' in parents and name = '$srtName' and trashed = false"]);
    $srtUploaded = false;
    if (count($srtFiles->getFiles()) > 0) {
        $srtId = $srtFiles->getFiles()[0]->getId();
        $srtData = $drive->files->get($srtId, ['alt' => 'media'])->getBody()->getContents();

        $capSnippet = new Google\Service\YouTube\CaptionSnippet();
        $capSnippet->setVideoId($videoId);
        $capSnippet->setLanguage('de');
        $capSnippet->setName('Original');

        $caption = new Google\Service\YouTube\Caption();
        $caption->setSnippet($capSnippet);
        $youtube->captions->insert('snippet', $caption, ['data' => $srtData, 'mimeType' => '*/*', 'uploadType' => 'multipart']);
        $srtUploaded = true;
    }

    if (file_exists($tempFile)) {
        unlink($tempFile);
    }

    return [
        'success' => true,
        'message' => "Video #$videoNum erfolgreich hochgeladen",
        'videoId' => $videoId,
        'plannedFor' => $publishDate->format('d.m.Y H:i'),
        'srt' => $srtUploaded
    ];
}

// Direkter Aufruf via HTTP (z.B. vom Browser/Frontend)
if (!defined('IN_NIGHTLY')) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    set_time_limit(600);

    try {
        if (!isset($_POST['video_num'])) {
            throw new Exception("Keine Video-Nummer übergeben.");
        }

        // Passwort-Prüfung gegen Hash in client_secret.json (unterstützt alt/neu)
        $secrets = json_decode(file_get_contents(__DIR__ . '/client_secret.json'), true);
        $c = $secrets['app'] ?? $secrets['app_config'] ?? [];
        $expectedHash = $c['password'] ?? '';

        if (!isset($_POST['password']) || $_POST['password'] !== $expectedHash) {
            throw new Exception("Falsches Passwort. Upload nicht erlaubt.");
        }

        $result = uploadToYouTube($_POST['video_num']);
        echo json_encode($result);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}