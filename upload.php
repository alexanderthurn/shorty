<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Global error handler for fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Fatal Error: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

require_once 'config.php';

/**
 * Kern-Logik für den Video-Upload zu YouTube.
 */
function uploadToYouTube($videoNum, $config, $isMock = false, $isPreview = false)
{
    $client = getClient();
    $service = new Google\Service\Sheets($client);

    $sheetId = $config['sheet_id'];
    $sheetName = $config['sheet_name'] ?? 'Themen';
    $folderId = $config['folder_id'];
    $playlistId = $config['playlist_id'];
    $startDate = $config['start_date'] ?? '2026-01-01 21:21:00';

    // 1. Daten aus Google Sheet holen
    $response = $service->spreadsheets_values->get($sheetId, $sheetName . '!A2:H420');
    $rows = $response->getValues();
    $metadata = null;

    foreach ($rows as $row) {
        if (isset($row[0]) && $row[0] == $videoNum) {
            // Tags extrahieren (Spalte G = Index 6)
            $tagString = $row[6] ?? '';
            $videoTags = array_filter(array_map('trim', explode(',', $tagString)));

            // Merge with default tags from config
            $defaultTagString = $config['default_tags'] ?? '';
            $defaultTags = array_filter(array_map('trim', explode(',', $defaultTagString)));

            // Combine: default tags first, then video-specific tags, remove duplicates
            $tags = array_unique(array_merge($defaultTags, $videoTags));
            
            // Validate and clean tags
            $validatedTags = [];
            $invalidTags = [];
            
            foreach ($tags as $tag) {
                // Convert to string if not already
                if (!is_string($tag) && !is_numeric($tag)) {
                    $invalidTags[] = ['value' => $tag, 'type' => gettype($tag), 'reason' => 'Not a string or number'];
                    continue;
                }
                
                $tagStr = trim((string)$tag);
                
                // Check if empty after trimming
                if (empty($tagStr)) {
                    continue;
                }
                
                // YouTube tag validation: max 30 characters per tag
                if (mb_strlen($tagStr) > 30) {
                    $invalidTags[] = ['value' => $tagStr, 'reason' => 'Tag exceeds 30 characters'];
                    continue;
                }
                
                $validatedTags[] = $tagStr;
            }
            
            // Throw error if invalid tags found
            if (!empty($invalidTags)) {
                $errorMsg = "Invalid tags found for video #$videoNum:\n";
                $errorMsg .= "Raw tag string from sheet: " . json_encode($tagString) . "\n";
                $errorMsg .= "Default tags from config: " . json_encode($defaultTagString) . "\n";
                $errorMsg .= "Invalid tags: " . json_encode($invalidTags, JSON_PRETTY_PRINT) . "\n";
                $errorMsg .= "Valid tags that would be sent: " . json_encode($validatedTags);
                throw new Exception($errorMsg);
            }
            
            // Re-index array to ensure it's a proper sequential array (not associative)
            $tags = array_values($validatedTags);
            
            if (empty($tags)) {
                $tags = ['Bitcoin']; // Fallback if no tags at all
            }
            
            // Log tags being sent (for debugging)
            $tagsDebug = [
                'raw_tag_string' => $tagString,
                'default_tag_string' => $defaultTagString,
                'final_tags' => $tags,
                'tags_count' => count($tags),
                'tags_json' => json_encode($tags)
            ];

            $metadata = [
                'title' => "Tag $videoNum - " . ($row[2] ?? 'Bitcoin Short #' . $videoNum),
                'desc' => $row[3] ?? '', // Spalte D = Index 3
                'tags' => $tags,
                'isUploaded' => !empty($row[7]), // Spalte H = Index 7
                '_tags_debug' => $tagsDebug // Debug info for error reporting
            ];

            // 1.5 Footer Text anhängen
            if (!empty($config['footer_text'])) {
                $metadata['desc'] .= "\n\n---------------------\n" . $config['footer_text'];
            }
            break;
        }
    }

    if (!$metadata) {
        throw new Exception("Video-Nummer $videoNum nicht im Sheet gefunden.");
    }

    if ($metadata['isUploaded'] && !$isPreview) {
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
    $publishDate = new DateTime($startDate);
    $publishDate->modify('+' . ($videoNum - 1) . ' days');
    $publishStr = $publishDate->format(DateTime::RFC3339);

    // 3. Datei von Google Drive laden (für Preview Link holen)
    $drive = new Google\Service\Drive($client);
    $fileName = $videoNum . '.mp4';
    $files = $drive->files->listFiles([
        'q' => "'" . $folderId . "' in parents and name = '$fileName' and trashed = false",
        'fields' => 'files(id, name, webViewLink)'
    ]);

    $fileId = null;
    $webViewLink = null;

    if (count($files->getFiles()) > 0) {
        $driveFile = $files->getFiles()[0];
        $fileId = $driveFile->getId();
        $webViewLink = $driveFile->getWebViewLink();
    } elseif (!$isPreview) {
        throw new Exception("Datei $fileName nicht gefunden.");
    }

    if ($isPreview) {
        // Format publish date for display
        $publishDateDisplay = $publishDate->format('d.m.Y H:i');
        
        return [
            'success' => true,
            'preview' => true,
            'title' => $metadata['title'],
            'desc' => $metadata['desc'],
            'tags' => $metadata['tags'],
            'driveLink' => $webViewLink,
            'videoNum' => $videoNum,
            'category' => '27', // Education (Bildung)
            'categoryName' => 'Bildung',
            'language' => 'de',
            'languageName' => 'Deutsch',
            'privacyStatus' => 'private',
            'privacyStatusName' => 'Privat',
            'publishDate' => $publishStr,
            'publishDateDisplay' => $publishDateDisplay
        ];
    }

    if (!$fileId) {
        throw new Exception("Datei $fileName nicht gefunden (Upload Check).");
    }

    // Download from Drive in chunks to temp file (memory-efficient)
    $tempDir = __DIR__ . '/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    $tempFile = $tempDir . '/temp_' . $videoNum . '.mp4';

    // Use streaming download to avoid memory issues
    $response = $drive->files->get($fileId, ['alt' => 'media']);
    $body = $response->getBody();
    $handle = fopen($tempFile, 'w');
    while (!$body->eof()) {
        fwrite($handle, $body->read(1024 * 1024)); // 1MB chunks
    }
    fclose($handle);

    $fileSize = filesize($tempFile);

    // 4. YouTube Upload - use resumable upload for large files
    $youtube = new Google\Service\YouTube($client);
    $video = new Google\Service\YouTube\Video();

    $snippet = new Google\Service\YouTube\VideoSnippet();
    $snippet->setTitle($metadata['title']);
    $snippet->setDescription($metadata['desc']);
    
    // Validate tags before setting (double-check)
    $tagsToSet = $metadata['tags'];
    if (!is_array($tagsToSet)) {
        $debugInfo = $metadata['_tags_debug'] ?? [];
        throw new Exception("Tags must be an array. Got: " . gettype($tagsToSet) . "\nDebug info: " . json_encode($debugInfo, JSON_PRETTY_PRINT));
    }
    
    // Log what we're sending (for debugging)
    $tagsDebugInfo = $metadata['_tags_debug'] ?? [];
    $tagsDebugInfo['tags_being_sent'] = $tagsToSet;
    $tagsDebugInfo['tags_php_type'] = gettype($tagsToSet);
    $tagsDebugInfo['tags_is_associative'] = array_keys($tagsToSet) !== range(0, count($tagsToSet) - 1);
    
    try {
        $snippet->setTags($tagsToSet);
    } catch (Exception $e) {
        // If setTags itself fails, include debug info
        $errorMsg = "Failed to set tags on YouTube snippet.\n";
        $errorMsg .= "Error: " . $e->getMessage() . "\n";
        $errorMsg .= "Tags debug info: " . json_encode($tagsDebugInfo, JSON_PRETTY_PRINT);
        throw new Exception($errorMsg);
    }
    
    $snippet->setCategoryId('27'); // 27 = Education (Bildung)
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

    // Use resumable upload with chunks
    try {
        $client->setDefer(true);
        $insertRequest = $youtube->videos->insert('snippet,status,recordingDetails', $video);

        $chunkSize = 8 * 1024 * 1024; // 8MB chunks
        $media = new Google\Http\MediaFileUpload(
            $client,
            $insertRequest,
            'video/mp4',
            null,
            true, // resumable
            $chunkSize
        );
        $media->setFileSize($fileSize);

        // Upload in chunks
        $uploadHandle = fopen($tempFile, 'rb');
        $result = false;
        while (!$result && !feof($uploadHandle)) {
            $chunk = fread($uploadHandle, $chunkSize);
            $result = $media->nextChunk($chunk);
        }
        fclose($uploadHandle);
        $client->setDefer(false);

        if (!$result) {
            throw new Exception("YouTube Upload fehlgeschlagen - keine Antwort erhalten.");
        }
    } catch (Google\Service\Exception $e) {
        // Enhance Google API errors with tag debug information
        $tagsDebugInfo = $metadata['_tags_debug'] ?? [];
        $errorMsg = "YouTube API Error for video #$videoNum:\n";
        $errorMsg .= $e->getMessage() . "\n\n";
        $errorMsg .= "Tags Debug Information:\n";
        $errorMsg .= json_encode($tagsDebugInfo, JSON_PRETTY_PRINT) . "\n\n";
        $errorMsg .= "Tags that were attempted: " . json_encode($metadata['tags'], JSON_PRETTY_PRINT) . "\n";
        
        // Include original error details
        $errors = $e->getErrors();
        if (!empty($errors)) {
            $errorMsg .= "\nAPI Error Details:\n";
            foreach ($errors as $err) {
                $errorMsg .= "- " . ($err['reason'] ?? '') . ': ' . ($err['message'] ?? '') . "\n";
            }
        }
        
        throw new Exception($errorMsg);
    }

    $videoId = $result->getId();

    // 4.5 Video zur Playlist hinzufügen
    $playlistItem = new Google\Service\YouTube\PlaylistItem();
    $playlistSnippet = new Google\Service\YouTube\PlaylistItemSnippet();
    $playlistSnippet->setPlaylistId($playlistId);
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
    $service->spreadsheets_values->update($sheetId, $sheetName . "!H$rowInSheet", $body, $params);

    // 5. Optionaler SRT Upload
    $srtName = $videoNum . '.srt';
    $srtFiles = $drive->files->listFiles(['q' => "'" . $folderId . "' in parents and name = '$srtName' and trashed = false"]);
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
        if (!isset($_POST['project'])) {
            throw new Exception("Kein Projekt übergeben.");
        }

        $projectId = $_POST['project'];
        $config = getProjectConfig($projectId);

        // Passwort-Prüfung - Global
        $expectedHash = UPLOAD_PASSWORD_HASH;

        if (!isset($_POST['password']) || $_POST['password'] !== $expectedHash) {
            throw new Exception("Falsches Passwort. Upload nicht erlaubt.");
        }

        $isPreview = isset($_POST['preview']) && $_POST['preview'] === 'true';
        $result = uploadToYouTube($_POST['video_num'], $config, false, $isPreview);
        echo json_encode($result);

    } catch (Google\Service\Exception $e) {
        // Google API specific errors - extract details
        http_response_code(400);
        $errors = $e->getErrors();
        $details = [];
        foreach ($errors as $err) {
            $details[] = ($err['reason'] ?? '') . ': ' . ($err['message'] ?? '');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Google API Error: ' . $e->getMessage(),
            'details' => $details,
            'code' => $e->getCode()
        ]);
    } catch (Throwable $e) {
        // Catch all other errors including PHP errors
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'trace' => array_slice(array_map(function ($t) {
                return ($t['file'] ?? '?') . ':' . ($t['line'] ?? '?') . ' ' . ($t['function'] ?? '');
            }, $e->getTrace()), 0, 5)
        ]);
    }
}