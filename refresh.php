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
            
            // Ensure all tags are strings and filter out empty/null values
            $tags = array_filter(array_map(function($tag) {
                return is_string($tag) ? trim($tag) : (string)$tag;
            }, $tags), function($tag) {
                return !empty($tag) && is_string($tag);
            });
            
            // Re-index array to ensure it's a proper sequential array (not associative)
            $tags = array_values($tags);
            
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

    if (!$videoId)
        throw new Exception("Video-ID für #$videoNum nicht im Sheet gefunden.");

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

    // --- Logic to preserve manual sections ---
    $currentDesc = $video->getSnippet()->getDescription();

    // Support both old (34 dashes) and new (21 dashes) separators for reading
    $separatorOld = "----------------------------------";
    $separatorNew = "---------------------"; // 21 dashes

    $useSeparatorForSplit = $separatorNew;
    if (strpos($currentDesc, $separatorOld) !== false) {
        $useSeparatorForSplit = $separatorOld;
    }

    $parts = explode($useSeparatorForSplit, $currentDesc);
    $parts = array_map('trim', $parts); // Cleanup whitespace

    $preservedMiddle = "";

    // Case: At least 3 parts (Top, Middle(s), Bottom)
    // Example: [Desc, Manual, Footer]
    if (count($parts) >= 3) {
        // We preserve everything between the first and the last element
        $middleParts = array_slice($parts, 1, -1);
        $preservedMiddle = implode("\n\n" . $separatorNew . "\n", $middleParts);
    }
    // Note: If count is 2 (Desc, Footer), middle is empty.
    // If count is 1 (Desc only), middle is empty and we overwrite desc.

    // Construct new Description
    $finalDesc = $metadata['desc'];

    // Add Middle if exists
    if (!empty($preservedMiddle)) {
        $finalDesc .= "\n\n" . $separatorNew . "\n" . $preservedMiddle;
    }

    // Add Footer
    // Config global footer
    $footerText = $config['footer_text'] ?? '';
    if (!empty($footerText)) {
        $finalDesc .= "\n\n" . $separatorNew . "\n" . $footerText;
    }
    // ----------------------------------------

    // Snippet aktualisieren
    $snippet = $video->getSnippet();
    $snippet->setTitle($metadata['title']);
    $snippet->setDescription($finalDesc);
    $snippet->setTags($metadata['tags']);
    $snippet->setCategoryId('27'); // 27 = Education (Bildung)
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
        // Wenn Datum in Vergangenheit: PublishAt nicht setzen
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

    // IF PREVIEW: We simulate what would happen, but we used LIVE data for accurate preview.
    // But wait, the preview block was earlier in the function. We moved fetching $video down here?
    // Optimization: If preview, we usually want to show what WILL happen.
    // The previous code had the Preview return block BEFORE fetching YouTube data.
    // But now we NEED YouTube data (currentDesc) to construct the preview correctly.
    // So we must fetch the video even in preview mode nicely.

    if ($isPreview) {
        // Get current video status for preview
        $currentStatus = $video->getStatus();
        $currentSnippet = $video->getSnippet();
        $currentCategoryId = $currentSnippet->getCategoryId();
        
        // Format publish date for display
        $publishDateDisplay = $publishDate->format('d.m.Y H:i');
        $publishAt = null;
        if ($publishDate > $now) {
            $publishAt = $publishStr;
        } else {
            // If date is in past, show current status
            $publishAt = $currentStatus->getPublishAt();
        }
        
        // Category mapping
        $categoryNames = [
            '27' => 'Bildung',
            '10' => 'Musik',
            '24' => 'Entertainment',
            '22' => 'People & Blogs',
            '25' => 'News & Politics',
            '26' => 'Howto & Style',
            '28' => 'Science & Technology'
        ];
        
        // Privacy status mapping
        $privacyStatusNames = [
            'private' => 'Privat',
            'unlisted' => 'Nicht gelistet',
            'public' => 'Öffentlich'
        ];
        
        return [
            'success' => true,
            'preview' => true,
            'title' => $metadata['title'],
            'desc' => $finalDesc,
            'tags' => $metadata['tags'],
            'driveLink' => $videoId ? "https://youtu.be/$videoId" : null,
            'videoNum' => $videoNum,
            'isRefresh' => true,
            'category' => '27', // Will be set to Education
            'categoryName' => 'Bildung',
            'currentCategory' => $currentCategoryId,
            'currentCategoryName' => $categoryNames[$currentCategoryId] ?? 'Unbekannt',
            'language' => 'de',
            'languageName' => 'Deutsch',
            'privacyStatus' => $currentStatus->getPrivacyStatus() ?? 'private',
            'privacyStatusName' => $privacyStatusNames[$currentStatus->getPrivacyStatus() ?? 'private'] ?? 'Unbekannt',
            'publishDate' => $publishAt,
            'publishDateDisplay' => $publishAt ? (new DateTime($publishAt))->format('d.m.Y H:i') : 'Nicht geplant'
        ];
    }

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
