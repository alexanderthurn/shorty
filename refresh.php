<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(600);

require_once 'config.php';

function refreshMetadata($videoNum, $config, $isPreview = false)
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

    if (!$videoId && !$isPreview)
        throw new Exception("Video-ID für #$videoNum nicht im Sheet gefunden.");
    if (!$metadata)
        throw new Exception("Video-Nummer $videoNum nicht im Sheet gefunden.");

    // Config global footer
    $footerText = $config['footer_text'] ?? '';
    if (!empty($footerText)) {
        $metadata['desc'] .= "\n\n----------------------------------\n" . $footerText . "\n----------------------------------";
    }

    if ($isPreview) {
        return [
            'success' => true,
            'preview' => true,
            'title' => $metadata['title'],
            'desc' => $metadata['desc'],
            'tags' => $metadata['tags'],
            'driveLink' => $videoId ? "https://youtu.be/$videoId" : null, // Show YT link if available
            'videoNum' => $videoNum,
            'isRefresh' => true // Hint for UI to show it's existing video
        ];
    }

    if (!$videoId)
        throw new Exception("Video-ID für #$videoNum nicht im Sheet gefunden.");

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

    // Nur setzen, wenn Datum in der Zukunft liegt, sonst wirft YouTube Fehler (invalidPublishAt)
    // oder wenn Video noch private ist und scheduled werden soll.
    // Aber sicherheitshalber: Past dates sind für Scheduling verboten.
    $now = new DateTime();
    if ($publishDate > $now) {
        $status->setPublishAt($publishStr);
    } else {
        // Wenn Datum in Vergangenheit: PublishAt nicht setzen (löschen/nullen geht via API oft nicht direkt so einfach,
        // aber wir senden es einfach nicht erneut, wenn es schon public ist).
        // Falls das Video 'private' ist und eigentlich veröffentlich sein SOLLTE, müssten wir status->privacyStatus = 'public' setzen.
        // Das Skript ging bisher davon aus, dass wir schedulen.
        // Wir lassen PublishAt hier weg, um den Fehler zu vermeiden.
        // Optional: $status->setPrivacyStatus('public'); falls gewünscht, aber Refresh sollte primär Metadaten fixen.
    }

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
    $isPreview = isset($_POST['preview']) && $_POST['preview'] === 'true';

    // Passwort-Prüfung - Global
    $expectedHash = UPLOAD_PASSWORD_HASH;

    if (!isset($_POST['password']) || $_POST['password'] !== $expectedHash) {
        throw new Exception("Falsches Passwort. Zugriff verweigert.");
    }

    $result = refreshMetadata($videoNum, $config, $isPreview);
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
