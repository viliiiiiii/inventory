<?php
require_once __DIR__ . '/../helpers.php';
require_login();
$pdo = get_pdo();
$userId = (int)(current_user()['id'] ?? 0);
$count = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL")
                  ->execute([$userId]) ? (int)$pdo->query("SELECT FOUND_ROWS()") : 0;
// Simpler & correct:
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
$stmt->execute([$userId]);
$count = (int)$stmt->fetchColumn();
header('Content-Type: text/plain; charset=utf-8');
echo $count;
