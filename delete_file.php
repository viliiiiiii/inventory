<?php
require_once __DIR__ . '/auth.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Invalid method'], 405);
}

require_post_csrf();

$photoId = (int)($_POST['id'] ?? 0);
if (!$photoId) {
    json_response(['error' => 'Invalid photo'], 422);
}

remove_photo($photoId);
json_response(['success' => true]);
