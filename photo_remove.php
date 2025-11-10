<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_login();

header('Content-Type: application/json');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'Method not allowed');
}
if (!can('edit')) {
    fail(403, 'Forbidden');
}
if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
    fail(422, 'Invalid CSRF token');
}

$photoId = (int)($_POST['photo_id'] ?? 0);
if ($photoId <= 0) {
    fail(422, 'Invalid photo');
}

// Optional: make sure the photo exists (and get its task for logging)
$pdo = get_pdo();
$st  = $pdo->prepare('SELECT task_id FROM task_photos WHERE id = ?');
$st->execute([$photoId]);
$row = $st->fetch();
if (!$row) {
    fail(404, 'Photo not found');
}

try {
    remove_photo($photoId);
    log_event('photo.delete', 'photo', (int)$row['task_id'], ['photo_id' => $photoId]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    fail(500, 'Delete failed');
}
