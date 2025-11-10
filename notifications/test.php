<?php
require_once __DIR__ . '/../helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = get_pdo();
  $userId = (int)(current_user()['id'] ?? 0);
  if (!$userId) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no user']); exit; }

  // Detect available columns
  $cols = [];
  $types = [];
  $stmt = $pdo->prepare("
    SELECT COLUMN_NAME, DATA_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
  ");
  $stmt->execute();
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $name = strtolower($c['COLUMN_NAME']);
    $cols[$name] = true;
    $types[$name] = strtolower($c['DATA_TYPE']);
  }

  if (empty($cols['user_id'])) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'notifications.user_id is required in schema']); exit;
  }

  // Build dynamic payload based on existing columns
  $insCols = ['user_id'];
  $params  = [':user_id' => $userId];

  if (!empty($cols['type']))       { $insCols[]='type';       $params[':type']       = 'note.shared'; }
  if (!empty($cols['title']))      { $insCols[]='title';      $params[':title']      = 'Test notification'; }
  if (!empty($cols['body']))       { $insCols[]='body';       $params[':body']       = 'Hello from test.php at '.date('Y-m-d H:i:s'); }
  if (!empty($cols['link']))       { $insCols[]='link';       $params[':link']       = '/notes/index.php'; }
  if (!empty($cols['payload']))    {
    $insCols[]='payload';
    $json = json_encode(['env'=>'debug'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    // If column is JSON type, send as text (PDO will cast fine); same for TEXT/VARCHAR.
    $params[':payload'] = $json;
  }
  if (!empty($cols['created_at'])) { $insCols[]='created_at'; $params[':created_at'] = date('Y-m-d H:i:s'); }

  $sql = 'INSERT INTO notifications (' . implode(',', $insCols) . ') VALUES (' .
         implode(',', array_map(fn($c)=>':'.$c, array_map(fn($c)=>trim($c, '`'), $insCols))) . ')';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
