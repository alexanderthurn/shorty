<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}
set_time_limit(1800);

// Define flag to prevent included files from executing their direct call logic
define('IN_NIGHTLY', true);

require_once 'config.php';
require_once 'list.php';
require_once 'x_post.php';

try {
    if (!defined('NIGHTLY_MODE') || NIGHTLY_MODE === 'OFF') {
        echo json_encode(['success' => true, 'message' => 'Nightly mode is OFF or not defined.']);
        exit;
    }

    $now = new DateTime();
    if (isset($_GET['test_date'])) {
        $now = new DateTime($_GET['test_date']);
    } elseif (php_sapi_name() === 'cli' && isset($argv[1])) {
        // Support for simulation in CLI: php nightly.php "2026-01-10 12:00:00"
        $now = new DateTime($argv[1]);
    }

    // Use the refactored list.php function to get all data
    $allEntries = getShortyList();

    // Filter and sort for entries that should be published
    $eligibleEntries = [];
    foreach ($allEntries as $entry) {
        $nr = $entry['nr'];
        $publishDate = $entry['datum_raw'];
        $tweetId = $entry['xTweetId'];
        $hasMp4 = $entry['hasMp4'];

        // Logic: older than simulated "now" (+ 2 min buffer), not yet posted, and mp4 exists
        $buffer = new DateInterval('PT2M');
        $simulatedNowWithBuffer = (clone $now)->add($buffer);

        if ($publishDate <= $simulatedNowWithBuffer && empty($tweetId) && $hasMp4) {
            $eligibleEntries[] = $entry;
        }
    }

    if (empty($eligibleEntries)) {
        echo json_encode([
            'success' => true,
            'message' => 'No entries were eligible for publishing.',
            'simulated_now' => $now->format('Y-m-d H:i:s'),
            'checked_count' => count($allEntries)
        ]);
        exit;
    }

    // Sort eligible entries by number (oldest first) to pick the single oldest one
    usort($eligibleEntries, function ($a, $b) {
        return $a['nr'] <=> $b['nr'];
    });

    $oldestEntry = $eligibleEntries[0];
    $nr = $oldestEntry['nr'];
    $isMock = (NIGHTLY_MODE === 'MOCK');

    $results = [];
    try {
        // Use the refactored x_post.php function
        $status = postToX($nr, $isMock);
        $results[] = [
            'nr' => $nr,
            'status' => 'PROCESSED',
            'simulated_now' => $now->format('Y-m-d H:i:s'),
            'detail' => $status
        ];
    } catch (Exception $e) {
        $results[] = [
            'nr' => $nr,
            'status' => 'ERROR',
            'simulated_now' => $now->format('Y-m-d H:i:s'),
            'message' => $e->getMessage()
        ];
    }

    echo json_encode(['success' => true, 'results' => $results]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
