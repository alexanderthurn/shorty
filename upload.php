<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(600); 

require_once 'config.php';

try {
    $client = getClient();
    if (!isset($_POST['video_num'])) {
        throw new Exception("Keine Video-Nummer übergeben.");
    }

    $videoNum = $_POST['video_num'];

    // 1. Daten aus Google Sheet holen
    $service = new Google\Service\Sheets($client);
    $response = $service->spreadsheets_values->get(SHEET_ID, SHEET_NAME . '!A2:F420');
    $rows = $response->getValues();
    $metadata = null;

    foreach ($rows as $row) {
        if (isset($row[0]) && $row[0] == $videoNum) {
            $metadata = [
                'title' => $row[2] ?? 'Bitcoin Short #' . $videoNum,
                'desc'  => $row[5] ?? ''
            ];
            break;
        }
    }

    if (!$metadata) throw new Exception("Video-Nummer $videoNum nicht im Sheet gefunden.");

    // 2. Datum berechnen
    $publishDate = new DateTime('2026-01-01 21:21:00');
    $publishDate->modify('+' . ($videoNum - 1) . ' days');
    $publishStr = $publishDate->format(DateTime::RFC3339);

    // 3. Datei von Google Drive laden
    $drive = new Google\Service\Drive($client);
    $fileName = $videoNum . '.mp4';
    $files = $drive->files->listFiles(['q' => "'" . FOLDER_ID . "' in parents and name = '$fileName' and trashed = false"]);

    if (count($files->getFiles()) == 0) throw new Exception("Datei $fileName nicht gefunden.");

    $fileId = $files->getFiles()[0]->getId();
    $content = $drive->files->get($fileId, ['alt' => 'media']);
    $tempFile = __DIR__ . '/temp_' . $videoNum . '.mp4';
    file_put_contents($tempFile, $content->getBody()->getContents());

    // 4. YouTube Upload
    $youtube = new Google\Service\YouTube($client);
    $video = new Google\Service\YouTube\Video();
    
    $snippet = new Google\Service\YouTube\VideoSnippet();
    $snippet->setTitle($metadata['title']);
    $snippet->setDescription($metadata['desc']);
    $video->setSnippet($snippet);

    $status = new Google\Service\YouTube\VideoStatus();
    $status->setPrivacyStatus('private');
    $status->setPublishAt($publishStr);
    $video->setStatus($status);

    $result = $youtube->videos->insert('snippet,status', $video, [
        'data' => file_get_contents($tempFile),
        'mimeType' => 'video/mp4',
        'uploadType' => 'multipart'
    ]);

    $videoId = $result->getId();

    // --- ÄNDERUNG HIER: Nur die Video-ID zurück ins Sheet schreiben ---
    $values = [[ $videoId ]]; // Hier wurde das Präfix "https://youtu.be/" entfernt
    $body = new Google\Service\Sheets\ValueRange(['values' => $values]);
    $params = ['valueInputOption' => 'RAW'];
    $rowInSheet = $videoNum + 1; 
    $service->spreadsheets_values->update(SHEET_ID, SHEET_NAME . "!G$rowInSheet", $body, $params);

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

    if (file_exists($tempFile)) unlink($tempFile);

    echo json_encode([
        'success' => true,
        'message' => "Video #$videoNum erfolgreich hochgeladen",
        'videoId' => $videoId,
        'plannedFor' => $publishDate->format('d.m.Y H:i'),
        'srt' => $srtUploaded
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}