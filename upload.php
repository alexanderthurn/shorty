<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
$client = getClient();
$videoNum = $_POST['video_num'];

// 1. Daten aus Sheet holen
$service = new Google\Service\Sheets($client);
$response = $service->spreadsheets_values->get(SHEET_ID, 'Themen!A2:F416');
$rows = $response->getValues();
$metadata = null;

foreach ($rows as $row) {
    if ($row[0] == $videoNum) {
        $metadata = ['title' => $row[2], 'desc' => $row[5]];
        break;
    }
}

if (!$metadata) die("Video-Nummer nicht im Sheet gefunden.");

// 2. Datum berechnen (Tag 1 = 01.01.2026)
$publishDate = new DateTime('2026-01-01 21:21:00');
$publishDate->modify('+' . ($videoNum - 1) . ' days');
$publishStr = $publishDate->format(DateTime::RFC3339);

// 3. Datei von Drive laden (temporär auf Server)
$drive = new Google\Service\Drive($client);
$files = $drive->files->listFiles(['q' => "'".FOLDER_ID."' in parents and name = '$videoNum.mp4'"]);
if (count($files->getFiles()) == 0) die("MP4 Datei nicht im Drive Ordner gefunden.");

$fileId = $files->getFiles()[0]->getId();
$content = $drive->files->get($fileId, ['alt' => 'media']);
file_put_contents('temp.mp4', $content->getBody()->getContents());

// 4. YouTube Upload
$youtube = new Google\Service\YouTube($client);
$video = new Google\Service\YouTube\Video();
$status = new Google\Service\YouTube\VideoStatus();
$status->privacyStatus = 'private';
$status->publishAt = $publishStr;
$video->setStatus($status);

$snippet = new Google\Service\YouTube\VideoSnippet();
$snippet->setTitle($metadata['title']);
$snippet->setDescription($metadata['desc']);
$video->setSnippet($snippet);

$result = $youtube->videos->insert('snippet,status', $video, [
    'data' => file_get_contents('temp.mp4'),
    'mimeType' => 'video/mp4',
    'uploadType' => 'multipart'
]);

unlink('temp.mp4'); // Aufräumen
echo "Erfolg! Video #" . $videoNum . " hochgeladen und geplant für " . $publishStr;