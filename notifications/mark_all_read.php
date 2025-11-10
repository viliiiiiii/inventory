<?php
require_once __DIR__ . '/../helpers.php';
require_login();
if (!is_post()) { http_response_code(405); exit('Only POST'); }
$pdo = get_pdo();
$userId = (int)(current_user()['id'] ?? 0);
$pdo->prepare("UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL")->execute([$userId]);
header('Content-Type: text/plain; charset=utf-8');
echo "OK";
