<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$startTime = microtime(true);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}
// Time limit is 600 seconds on the provider side
set_time_limit(600);

// Define flag to prevent included files from executing their direct call logic
define('IN_NIGHTLY', true);

require_once 'config.php';
require_once 'list.php';
require_once 'x_post.php';
require_once 'upload.php';

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

    // Determine what to run (all, x, youtube)
    $runType = $_GET['type'] ?? 'all';
    if (php_sapi_name() === 'cli' && isset($argv[2])) {
        $runType = $argv[2];
    }

    // Use the refactored list.php function to get all data
    $allEntries = getShortyList();

    // Sort entries oldest first for processing
    usort($allEntries, function ($a, $b) {
        return $a['nr'] <=> $b['nr'];
    });

    $results = [
        'youtube' => [],
        'x' => []
    ];

    $isMock = (NIGHTLY_MODE === 'MOCK');

    // --- 1. YOUTUBE UPLOADS ---
    if ((NIGHTLY_YOUTUBE_ACTIVE === true) && ($runType === 'all' || $runType === 'youtube')) {
        foreach ($allEntries as $entry) {
            // Check time limit: stop if more than 500 seconds have passed
            if ((microtime(true) - $startTime) > 500) {
                $results['youtube'][] = ['status' => 'SKIPPED', 'message' => 'Time limit (500s) reached, skipping remaining YouTube uploads.'];
                break;
            }

            $nr = $entry['nr'];
            $isUploaded = $entry['isUploaded'];
            $hasMp4 = $entry['hasMp4'];

            // Logic: not uploaded yet and mp4 exists
            if (!$isUploaded && $hasMp4) {
                try {
                    $status = uploadToYouTube($nr, $isMock);
                    $results['youtube'][] = [
                        'nr' => $nr,
                        'status' => 'PROCESSED',
                        'detail' => $status
                    ];
                } catch (Exception $e) {
                    $results['youtube'][] = [
                        'nr' => $nr,
                        'status' => 'ERROR',
                        'message' => $e->getMessage()
                    ];
                    // Abort YouTube uploads if one fails as requested
                    break;
                }
            }
        }
    }

    // --- 2. X POSTS (Exactly one oldest) ---
    if ((NIGHTLY_X_ACTIVE === true) && ($runType === 'all' || $runType === 'x')) {
        // Filter and sort for entries that should be published
        $eligibleXEntries = [];
        foreach ($allEntries as $entry) {
            $nr = $entry['nr'];
            $publishDate = $entry['datum_raw'];
            $tweetId = $entry['xTweetId'];
            $hasMp4 = $entry['hasMp4'];

            // Logic: older than simulated "now" (+ 2 min buffer), not yet posted, and mp4 exists
            $buffer = new DateInterval('PT2M');
            $simulatedNowWithBuffer = (clone $now)->add($buffer);

            if ($publishDate <= $simulatedNowWithBuffer && empty($tweetId) && $hasMp4) {
                $eligibleXEntries[] = $entry;
            }
        }

        if (!empty($eligibleXEntries)) {
            // Sort eligible entries by number (oldest first) to pick the single oldest one
            usort($eligibleXEntries, function ($a, $b) {
                return $a['nr'] <=> $b['nr'];
            });

            $oldestEntry = $eligibleXEntries[0];
            $nr = $oldestEntry['nr'];

            // Check time limit before starting X post
            if ((microtime(true) - $startTime) < 550) {
                try {
                    $status = postToX($nr, $isMock);
                    $results['x'][] = [
                        'nr' => $nr,
                        'status' => 'PROCESSED',
                        'simulated_now' => $now->format('Y-m-d H:i:s'),
                        'detail' => $status
                    ];
                } catch (Exception $e) {
                    $results['x'][] = [
                        'nr' => $nr,
                        'status' => 'ERROR',
                        'simulated_now' => $now->format('Y-m-d H:i:s'),
                        'message' => $e->getMessage()
                    ];
                }
            } else {
                $results['x'][] = ['status' => 'SKIPPED', 'message' => 'Time limit reached before starting X post.'];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'simulated_now' => $now->format('Y-m-d H:i:s'),
        'results' => $results,
        'execution_time' => round(microtime(true) - $startTime, 2) . 's'
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
