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

    $projects = getProjects();
    $overallResults = [];

    foreach ($projects as $projInfo) {
        $projectId = $projInfo['id'];
        $config = getProjectConfig($projectId);

        $nightlyMode = $config['nightly_mode'] ?? 'OFF';

        $projResult = [
            'id' => $projectId,
            'title' => $config['title'] ?? $projectId,
            'youtube' => [],
            'x' => [],
            'status' => 'ACTIVE'
        ];

        if ($nightlyMode === 'OFF') {
            $projResult['status'] = 'OFF';
            $overallResults[$projectId] = $projResult;
            continue;
        }

        $nightlyXActive = $config['nightly_x_active'] ?? false;
        $nightlyYoutubeActive = $config['nightly_youtube_active'] ?? false;
        $isMock = ($nightlyMode === 'MOCK');

        // --- DATA FETCHING ---
        $allEntries = getShortyList($config);

        // Sort entries oldest first for processing
        usort($allEntries, function ($a, $b) {
            return $a['nr'] <=> $b['nr'];
        });

        // --- 1. YOUTUBE UPLOADS ---
        if (($nightlyYoutubeActive === true) && ($runType === 'all' || $runType === 'youtube')) {
            foreach ($allEntries as $entry) {
                // Check time limit
                if ((microtime(true) - $startTime) > 500) {
                    $projResult['youtube'][] = ['status' => 'SKIPPED', 'message' => 'Time limit (500s) reached.'];
                    break;
                }

                $nr = $entry['nr'];
                $isUploaded = $entry['isUploaded'];
                $hasMp4 = $entry['hasMp4'];

                if (!$isUploaded && $hasMp4) {
                    try {
                        $status = uploadToYouTube($nr, $config, $isMock);
                        $projResult['youtube'][] = [
                            'nr' => $nr,
                            'status' => 'PROCESSED',
                            'detail' => $status
                        ];
                    } catch (Exception $e) {
                        $projResult['youtube'][] = [
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
        if (($nightlyXActive === true) && ($runType === 'all' || $runType === 'x')) {
            $eligibleXEntries = [];
            foreach ($allEntries as $entry) {
                $nr = $entry['nr'];
                $publishDate = $entry['datum_raw'];
                $tweetId = $entry['xTweetId'];
                $hasMp4 = $entry['hasMp4'];

                $buffer = new DateInterval('PT2M');
                $simulatedNowWithBuffer = (clone $now)->add($buffer);

                if ($publishDate <= $simulatedNowWithBuffer && empty($tweetId) && $hasMp4) {
                    $eligibleXEntries[] = $entry;
                }
            }

            if (!empty($eligibleXEntries)) {
                // Pick oldest
                $oldestEntry = $eligibleXEntries[0];
                $nr = $oldestEntry['nr'];

                if ((microtime(true) - $startTime) < 550) {
                    try {
                        $status = postToX($nr, $config, $isMock);
                        $projResult['x'][] = [
                            'nr' => $nr,
                            'status' => 'PROCESSED',
                            'detail' => $status
                        ];
                    } catch (Exception $e) {
                        $projResult['x'][] = [
                            'nr' => $nr,
                            'status' => 'ERROR',
                            'message' => $e->getMessage()
                        ];
                    }
                } else {
                    $projResult['x'][] = ['status' => 'SKIPPED', 'message' => 'Time limit reached.'];
                }
            }
        }

        $overallResults[$projectId] = $projResult;
    }

    echo json_encode([
        'success' => true,
        'simulated_now' => $now->format('Y-m-d H:i:s'),
        'projects' => $overallResults,
        'execution_time' => round(microtime(true) - $startTime, 2) . 's'
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
