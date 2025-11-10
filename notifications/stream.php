<?php
// /notifications/stream.php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

// Close the session so this long-lived request doesn't block others
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
// Tell Nginx not to buffer SSE
header('X-Accel-Buffering: no');

@set_time_limit(0);

$me = current_user();
$userId = (int)($me['id'] ?? 0);
if (!$userId) { http_response_code(401); exit; }

// Utility to send one SSE message
function sse_send(string $event, array $data, ?string $id = null, ?int $retryMs = 3000): void {
  if ($retryMs !== null) echo "retry: {$retryMs}\n";
  if ($id      !== null) echo "id: {$id}\n";
  if ($event   !== '')   echo "event: {$event}\n";
  echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n\n";
  @ob_flush(); @flush();
}

// Initial push (so the badge updates immediately)
$last = notif_unread_count($userId);
sse_send('count', ['count' => $last], (string)time());

// Cheap heartbeat to keep the connection warm on proxies
function sse_ping(): void { echo ": ping\n\n"; @ob_flush(); @flush(); }

// Main loop: check for changes up to ~2 minutes, then let client reconnect
$start = time();
while (true) {
  // stop after ~120s so PHP-FPM/Nginx/clients rotate the connection safely
  if ((time() - $start) > 120) { break; }

  // IMPORTANT: this query is fast with an index on (user_id, is_read, id)
  $cnt = notif_unread_count($userId);

  if ($cnt !== $last) {
    if ($cnt > $last) {
      $recent = notif_recent_unread($userId, min(5, $cnt));
      if ($recent) {
        sse_send('notifications', ['items' => $recent], (string)time());
      }
    }
    $last = $cnt;
    sse_send('count', ['count' => $cnt], (string)time());
  } else {
    sse_ping();
  }

  // Small sleep to avoid hot loop. 1–2s is usually perfect.
  sleep(2);
}

exit;