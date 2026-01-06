<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(600);

require_once 'config.php';

function refreshMetadata($videoNum, $config)
{
    $client = getClient();

    $sheetId = $config['sheet_id'];
    $sheetName = $config['sheet_name'] ?? 'Themen';
    $folderId = $config['folder_id'];
    $playlistId = $config['playlist_id'];
    $startDate = $config['start_date'] ?? '2026-01-01 21:21:00';

    // 1. Daten aus Google Sheet holen (inkl Spalte H für VideoID)
    $service = new Google\Service\Sheets($client);
    $response = $service->spreadsheets_values->get($sheetId, $sheetName . '!A2:H420');
    $rows = $response->getValues();
    $metadata = null;
    $videoId = null;

    foreach ($rows as $row) {
        if (isset($row[0]) && $row[0] == $videoNum) {
            $videoId = $row[7] ?? null; // Spalte H = Index 7

            $tagString = $row[6] ?? '';
            $tags = array_filter(array_map('trim', explode(',', $tagString)));
            if (empty($tags))
                $tags = ['Bitcoin'];

            $metadata = [
                'title' => "Tag $videoNum - " . ($row[2] ?? 'Bitcoin Short #' . $videoNum),
                'desc' => $row[3] ?? '', // Spalte D = Index 3
                'tags' => $tags
            ];
            break;
        }
    }

    if (!$videoId)
        throw new Exception("Video-ID für #$videoNum nicht im Sheet gefunden.");
    if (!$metadata)
        throw new Exception("Video-Nummer $videoNum nicht im Sheet gefunden.");

    // 2. Datum berechnen (für Recording Date)
    $publishDate = new DateTime($startDate);
    $publishDate->modify('+' . ($videoNum - 1) . ' days');
    $publishStr = $publishDate->format(DateTime::RFC3339);

    $youtube = new Google\Service\YouTube($client);

    // 3. YouTube Video Metadaten aktualisieren
    // Erst das Video-Objekt holen, um snippet und status zu modifizieren
    $listResponse = $youtube->videos->listVideos('snippet,status,recordingDetails', ['id' => $videoId]);
    if (empty($listResponse->getItems())) {
        throw new Exception("Video mit ID $videoId wurde auf YouTube nicht gefunden.");
    }
    $video = $listResponse->getItems()[0];

    // Snippet aktualisieren
    $snippet = $video->getSnippet();
    $snippet->setTitle($metadata['title']);
    $snippet->setDescription($metadata['desc']);
    $snippet->setTags($metadata['tags']);
    $snippet->setDefaultLanguage('de');
    $snippet->setDefaultAudioLanguage('de');
    $video->setSnippet($snippet);

    // Status (Publish Date)
    $status = $video->getStatus();
    $status->setPublishAt($publishStr);
    $video->setStatus($status);

    // Recording Details
    $recordingDetails = new Google\Service\YouTube\VideoRecordingDetails();
    $recordingDetails->setRecordingDate($publishStr);
    $video->setRecordingDetails($recordingDetails);

    $youtube->videos->update('snippet,status,recordingDetails', $video);

    // 4. Playlist prüfen/hinzufügen
    try {
        $playlistItem = new Google\Service\YouTube\PlaylistItem();
        $playlistSnippet = new Google\Service\YouTube\PlaylistItemSnippet();
        $playlistSnippet->setPlaylistId($playlistId);
        $resourceId = new Google\Service\YouTube\ResourceId();
        $resourceId->setKind('youtube#video');
        $resourceId->setVideoId($videoId);
        $playlistSnippet->setResourceId($resourceId);
        $playlistItem->setSnippet($playlistSnippet);
        $youtube->playlistItems->insert('snippet', $playlistItem);
    } catch (Exception $e) {
        // Ignorieren falls schon drin (meist 400/409 error)
    }

    // 5. SRT prüfen und ggf. hochladen
    $drive = new Google\Service\Drive($client);
    $srtName = $videoNum . '.srt';
    $srtFiles = $drive->files->listFiles(['q' => "'" . $folderId . "' in parents and name = '$srtName' and trashed = false"]);

    $srtStatus = "Kein SRT vorhanden";
    if (count($srtFiles->getFiles()) > 0) {
        // Prüfen ob bereits Captions existieren
        $captions = $youtube->captions->listCaptions('snippet', $videoId);
        $exists = false;
        foreach ($captions->getItems() as $cap) {
            if ($cap->getSnippet()->getLanguage() == 'de') {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $srtId = $srtFiles->getFiles()[0]->getId();
            $srtData = $drive->files->get($srtId, ['alt' => 'media'])->getBody()->getContents();

            $capSnippet = new Google\Service\YouTube\CaptionSnippet();
            $capSnippet->setVideoId($videoId);
            $capSnippet->setLanguage('de');
            $capSnippet->setName('Original');

            $caption = new Google\Service\YouTube\Caption();
            $caption->setSnippet($capSnippet);
            $youtube->captions->insert('snippet', $caption, ['data' => $srtData, 'mimeType' => '*/*', 'uploadType' => 'multipart']);
            $srtStatus = "SRT wurde nachgereicht";
        } else {
            $srtStatus = "SRT bereits vorhanden";
        }
    }

    return [
        'success' => true,
        'message' => "Video #$videoNum aktualisiert. $srtStatus.",
        'videoId' => $videoId
    ];
}

try {
    if (!isset($_POST['video_num'])) {
        throw new Exception("Keine Video-Nummer übergeben.");
    }
    if (!isset($_POST['project'])) {
        throw new Exception("Kein Projekt übergeben.");
    }

    $projectId = $_POST['project'];
    $config = getProjectConfig($projectId);
    $videoNum = $_POST['video_num'];

    // Passwort-Prüfung - Global
    $expectedHash = UPLOAD_PASSWORD_HASH;

    if (!isset($_POST['password']) || $_POST['password'] !== $expectedHash) {
        throw new Exception("Falsches Passwort. Zugriff verweigert.");
    }

    $result = refreshMetadata($videoNum, $config);
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
