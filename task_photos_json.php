<?php
// task_photos_json.php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_login();

header('Content-Type: application/json');

$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if ($taskId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Bad task_id']);
    exit;
}

try {
    // Reuse existing helper to get the 1..3 positions for this task
    $photosIndexed = fetch_task_photos($taskId); // returns [position => row]
    // Normalize to a flat array of URLs in order
    $urls = [];
    foreach ([1,2,3] as $p) {
        if (!empty($photosIndexed[$p]['url'])) {
            $urls[] = $photosIndexed[$p]['url'];
        }
    }
    echo json_encode(['ok' => true, 'urls' => $urls]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
