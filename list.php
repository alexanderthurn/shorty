<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

try {
    $client = getClient();
    $sheets = new Google\Service\Sheets($client);
    $drive = new Google\Service\Drive($client);

    // 1. Daten aus Sheet holen (inkl. Spalte G für Video-ID)
    $range = SHEET_NAME . '!A2:G420';
    $response = $sheets->spreadsheets_values->get(SHEET_ID, $range);
    $sheetData = $response->getValues() ?: [];

    // 2. Drive Dateien scannen für Links
    $optParams = [
        'q' => "'" . FOLDER_ID . "' in parents and trashed = false",
        'fields' => 'files(id, name)',
        'pageSize' => 1000
    ];
    $driveFiles = $drive->files->listFiles($optParams)->getFiles();

    $filesFound = [];
    foreach ($driveFiles as $file) {
        $name = $file->getName();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $num = pathinfo($name, PATHINFO_FILENAME);
        if (in_array($ext, ['mp4', 'srt'])) {
            $filesFound[$num][$ext] = $file->getId();
        }
    }

    // 3. Daten kombinieren
    $results = [];
    foreach ($sheetData as $index => $row) {
        $nr = $row[0] ?? null;
        if (!$nr)
            continue;

        // Datum berechnen
        $pDate = (new DateTime('2026-01-01 21:21:00'))->modify('+' . ($nr - 1) . ' days');

        // Zeile im Sheet (Index 0 = Zeile 2)
        $sheetRow = $index + 2;

        $results[] = [
            'nr' => $nr,
            'titel' => $row[2] ?? 'Kein Titel',
            'datum' => $pDate->format('d.m.Y H:i'),
            'hasMp4' => isset($filesFound[$nr]['mp4']),
            'hasSrt' => isset($filesFound[$nr]['srt']),
            'isUploaded' => !empty($row[6]),
            'youtubeId' => $row[6] ?? null,
            // Links
            'sheetLink' => "https://docs.google.com/spreadsheets/d/" . SHEET_ID . "/edit#range=A$sheetRow",
            'mp4Link' => isset($filesFound[$nr]['mp4']) ? "https://drive.google.com/file/d/" . $filesFound[$nr]['mp4'] . "/view" : null,
            'srtLink' => isset($filesFound[$nr]['srt']) ? "https://drive.google.com/file/d/" . $filesFound[$nr]['srt'] . "/view" : null
        ];
    }

    // Sortierung: Neueste oben
    usort($results, function ($a, $b) {
        return $b['nr'] <=> $a['nr']; });

    echo json_encode(['success' => true, 'data' => $results]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}