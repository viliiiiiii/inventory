<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_login();

header('Content-Type: application/json');

$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid task ID']);
    exit;
}

try {
    // If you already have a fetch for photos, keep it. This is explicit by task_id:
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT id, task_id, position, url FROM task_photos WHERE task_id = ? ORDER BY position ASC');
    $stmt->execute([$taskId]);
    $rows = $stmt->fetchAll();

    // Important: return clean URLs (no sanitize() here; JSON is not HTML)
    $photos = array_map(static fn($r) => [
        'id'       => (int)$r['id'],
        'position' => (int)$r['position'],
        'url'      => (string)$r['url'],
    ], $rows);

    echo json_encode(['ok' => true, 'photos' => $photos], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: '.$e->getMessage()]);
}
