<?php
require_once 'config.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Holt die Liste aller Einträge aus dem Google Sheet und kombiniert sie mit Drive-Daten.
 */
function getShortyList($config)
{
    $client = getClient();
    $sheets = new Google\Service\Sheets($client);
    $drive = new Google\Service\Drive($client);

    // Config defaults
    $sheetName = $config['sheet_name'] ?? 'Themen';
    $sheetId = $config['sheet_id'];
    $folderId = $config['folder_id'];
    $startDate = $config['start_date'] ?? '2026-01-01 21:21:00';

    // 1. Daten aus Sheet holen
    $range = $sheetName . '!A2:J420';
    $response = $sheets->spreadsheets_values->get($sheetId, $range);
    $sheetData = $response->getValues() ?: [];

    // 2. Drive Dateien scannen
    $optParams = [
        'q' => "'" . $folderId . "' in parents and trashed = false",
        'fields' => 'files(id, name, createdTime)',
        'pageSize' => 1000
    ];
    $driveFiles = $drive->files->listFiles($optParams)->getFiles();

    $filesFound = [];
    foreach ($driveFiles as $file) {
        $name = $file->getName();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $num = pathinfo($name, PATHINFO_FILENAME);
        if (in_array($ext, ['mp4', 'srt'])) {
            $filesFound[$num][$ext] = [
                'id' => $file->getId(),
                'createdTime' => $file->getCreatedTime()
            ];
        }
    }

    // 3. Daten kombinieren
    $results = [];
    foreach ($sheetData as $index => $row) {
        $nr = $row[0] ?? null;
        if (!$nr)
            continue;

        // Datum dynamisch berechnen
        $pDate = (new DateTime($startDate))->modify('+' . ($nr - 1) . ' days');
        $sheetRow = $index + 2;

        $results[] = [
            'nr' => $nr,
            'titel' => "Tag $nr - " . ($row[2] ?? 'Kein Titel'),
            'datum' => $pDate->format('d.m.Y H:i'),
            'datum_raw' => $pDate,
            'hasMp4' => isset($filesFound[$nr]['mp4']),
            'mp4Id' => $filesFound[$nr]['mp4']['id'] ?? null,
            'hasSrt' => isset($filesFound[$nr]['srt']),
            'isUploaded' => !empty($row[7]),
            'youtubeId' => $row[7] ?? null,
            'xTweetId' => $row[8] ?? null,
            'xMediaId' => $row[9] ?? null,
            'sheetLink' => "https://docs.google.com/spreadsheets/d/" . $sheetId . "/edit#range=A$sheetRow",
            'mp4Link' => isset($filesFound[$nr]['mp4']) ? "https://drive.google.com/file/d/" . $filesFound[$nr]['mp4']['id'] . "/view" : null,
            'srtLink' => isset($filesFound[$nr]['srt']) ? "https://drive.google.com/file/d/" . $filesFound[$nr]['srt']['id'] . "/view" : null
        ];
    }

    usort($results, function ($a, $b) {
        return $b['nr'] <=> $a['nr'];
    });

    return $results;
}

if (!defined('IN_NIGHTLY')) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    try {
        $projectId = $_GET['project'] ?? null;

        if (!$projectId) {
            // No project specified? Return list of projects.
            echo json_encode([
                'success' => true,
                'mode' => 'project_list',
                'projects' => getProjects(),
                'default_project' => getDefaultProject() // Added default project
            ]);
            exit;
        }

        // Project specified: configuration load & execute
        $config = getProjectConfig($projectId);
        $results = getShortyList($config);

        // Remove 'datum_raw' for JSON
        foreach ($results as &$item) {
            unset($item['datum_raw']);
        }
        unset($item);

        echo json_encode([
            'success' => true,
            'mode' => 'data',
            'project_title' => $config['title'],
            'sheet_name' => $config['sheet_name'],
            'data' => $results
        ]);
    } catch (Throwable $e) {
        http_response_code(400); // Bad Request / Error
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}