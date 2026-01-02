<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

try {
    $client = getClient();
    $sheets = new Google\Service\Sheets($client);
    $drive = new Google\Service\Drive($client);

    // Hole Daten bis Spalte G (Index 6)
    $response = $sheets->spreadsheets_values->get(SHEET_ID, SHEET_NAME . '!A2:G420');
    $sheetData = $response->getValues() ?: [];

    // Drive scannen
    $driveFiles = $drive->files->listFiles([
        'q' => "'" . FOLDER_ID . "' in parents and trashed = false",
        'fields' => 'files(name)'
    ])->getFiles();

    $filesFound = [];
    foreach ($driveFiles as $file) {
        $name = $file->getName();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $num = pathinfo($name, PATHINFO_FILENAME);
        if (in_array($ext, ['mp4', 'srt'])) $filesFound[$num][$ext] = true;
    }

    $results = [];
    foreach ($sheetData as $row) {
        $nr = $row[0] ?? null;
        if (!$nr) continue;

        $pDate = (new DateTime('2026-01-01 21:21:00'))->modify('+' . ($nr - 1) . ' days');

        $results[] = [
            'nr' => $nr,
            'titel' => $row[2] ?? 'Kein Titel',
            'datum' => $pDate->format('d.m.Y H:i'),
            'hasMp4' => isset($filesFound[$nr]['mp4']),
            'hasSrt' => isset($filesFound[$nr]['srt']),
            'isUploaded' => !empty($row[6]) // Spalte G ist Index 6
        ];
    }

    // Sortierung: Neueste oben
    usort($results, function($a, $b) { return $b['nr'] <=> $a['nr']; });

    echo json_encode(['success' => true, 'data' => $results]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}