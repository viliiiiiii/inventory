<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php'; // for get_pdo(), sanitize(), log_event()

/* DB helpers */
function db_apps(): PDO { return get_pdo(); }

function ensure_public_token_tables(): void {
    $pdo = db_apps();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_task_tokens (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          task_id BIGINT UNSIGNED NOT NULL,
          token VARCHAR(86) NOT NULL UNIQUE,
          expires_at DATETIME NOT NULL,
          created_by BIGINT UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_used_at DATETIME NULL,
          use_count INT UNSIGNED NOT NULL DEFAULT 0,
          revoked TINYINT(1) NOT NULL DEFAULT 0,
          INDEX idx_task_expires (task_id, expires_at),
          INDEX idx_expires (expires_at),
          INDEX idx_revoked (revoked)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_token_hits (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          token_id BIGINT UNSIGNED NOT NULL,
          task_id BIGINT UNSIGNED NOT NULL,
          ip VARBINARY(16) NULL,
          ua TEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_token (token_id),
          INDEX idx_task_time (task_id, created_at),
          CONSTRAINT fk_hits_token FOREIGN KEY (token_id)
              REFERENCES public_task_tokens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function inet_pton_nullable(?string $ip) {
    if (!$ip) return null;
    $bin = @inet_pton($ip);
    return $bin === false ? null : $bin;
}

function fetch_task_basic(int $taskId): ?array {
    // Use your existing fetch if available; otherwise a light version:
    $pdo = db_apps();
    $st = $pdo->prepare("SELECT t.*, b.name AS building_name, r.room_number, r.label AS room_label
                         FROM tasks t
                         LEFT JOIN buildings b ON b.id = t.building_id
                         LEFT JOIN rooms r ON r.id = t.room_id
                         WHERE t.id = ? LIMIT 1");
    $st->execute([$taskId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetch_photos_map(array $taskIds): array {
    if (!$taskIds) return [];
    $pdo = db_apps();
    // Reuse your existing helper if you have it: fetch_photos_for_tasks($ids)
    if (function_exists('fetch_photos_for_tasks')) {
        return fetch_photos_for_tasks($taskIds);
    }
    $in  = implode(',', array_fill(0, count($taskIds), '?'));
    $st  = $pdo->prepare("SELECT p.* FROM task_photos p WHERE p.task_id IN ($in) ORDER BY p.id ASC");
    $st->execute($taskIds);
    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$r['task_id']][] = $r;
    }
    return $map;
}

/* Ensure tables exist */
ensure_public_token_tables();

/* Read token from query */
$token = (string)($_GET['t'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo 'Bad link.';
    exit;
}

/* Lookup token */
$pdo = db_apps();
$st = $pdo->prepare("SELECT * FROM public_task_tokens WHERE token = ? LIMIT 1");
$st->execute([$token]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row || (int)$row['revoked'] === 1) {
    http_response_code(403);
    echo 'Link invalid.';
    exit;
}
if (strtotime((string)$row['expires_at']) <= time()) {
    http_response_code(403);
    echo 'Link expired.';
    exit;
}

$taskId = (int)$row['task_id'];
$task   = fetch_task_basic($taskId);
if (!$task) {
    http_response_code(404);
    echo 'Task not found.';
    exit;
}

/* Fetch photos */
$photosMap = fetch_photos_map([$taskId]);
$photos = $photosMap[$taskId] ?? [];

/* Log hit (IP/UA) + counters */
$ipHeader = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = $ipHeader ? trim(explode(',', $ipHeader)[0]) : '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ipBin = inet_pton_nullable($ip);

try {
    $pdo->beginTransaction();
    $hit = $pdo->prepare("INSERT INTO public_token_hits (token_id, task_id, ip, ua) VALUES (:tid, :task, :ip, :ua)");
    $hit->bindValue(':tid',  (int)$row['id'], PDO::PARAM_INT);
    $hit->bindValue(':task', (int)$taskId, PDO::PARAM_INT);
    if ($ipBin !== null) {
        $hit->bindValue(':ip', $ipBin, PDO::PARAM_LOB);
    } else {
        $hit->bindValue(':ip', null, PDO::PARAM_NULL);
    }
    $hit->bindValue(':ua', $ua, PDO::PARAM_STR);
    $hit->execute();

    $upd = $pdo->prepare("UPDATE public_task_tokens
                          SET use_count = use_count + 1, last_used_at = NOW()
                          WHERE id = ?");
    $upd->execute([(int)$row['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
}

/* Also log into your activity log if available */
if (function_exists('log_event')) {
    // no user id (public): rely on meta
    log_event('public.photo.view', 'task', $taskId, [
        'token_id' => (int)$row['id'],
        'ip'       => $ip,
        'ua'       => mb_substr($ua, 0, 500),
    ]);
}

$title = 'Task #' . $taskId . ' Photos';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo sanitize($title); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root {
    --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --ring:#e2e8f0;
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text);
         font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
  header { position:sticky; top:0; background:linear-gradient(180deg,#fff,rgba(255,255,255,.92));
           backdrop-filter: saturate(180%) blur(6px);
           border-bottom:1px solid var(--ring); padding:12px 16px; z-index:10; }
  header .row { display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:1100px; margin:0 auto; }
  h1 { font-size:18px; margin:0; }
  .muted { color:var(--muted); }
  main { max-width:1100px; margin:16px auto; padding:0 12px 24px; }
  .info { background:var(--card); border:1px solid var(--ring); border-radius:12px; padding:12px 14px; margin-bottom:14px; }
  .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:12px; }
  .imgcard { background:var(--card); border:1px solid var(--ring); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; }
  .imgwrap { background:#f1f5f9; display:flex; align-items:center; justify-content:center; aspect-ratio: 4 / 3; }
  .imgwrap img { width:100%; height:100%; object-fit:contain; display:block; }
  .meta { font-size:12px; color:var(--muted); padding:8px 10px; border-top:1px solid var(--ring); }
  .actions { display:flex; gap:8px; padding:10px; }
  .btn { display:inline-block; padding:8px 10px; border:1px solid var(--ring); background:#fff; color:#0f172a; border-radius:10px; text-decoration:none; font-size:13px; }
  .btn.primary { background:#0ea5e9; color:#fff; border-color:#0ea5e9; }
  .empty { color:var(--muted); text-align:center; padding:24px; border:1px dashed var(--ring); border-radius:12px; background:var(--card); }
</style>
</head>
<body>
<header>
  <div class="row">
    <h1><?php echo sanitize($title); ?></h1>
    <span class="muted">Public token • expires <?php echo sanitize(substr((string)$row['expires_at'],0,16)); ?></span>
  </div>
</header>
<main>
  <div class="info">
    <strong>Task:</strong> #<?php echo (int)$taskId; ?>
    <?php if (!empty($task['title'])): ?> — <?php echo sanitize((string)$task['title']); ?><?php endif; ?>
    <?php if (!empty($task['building_name'])): ?> • <strong>Building:</strong> <?php echo sanitize((string)$task['building_name']); ?><?php endif; ?>
    <?php
      $roomText = trim(($task['room_number'] ?? '') . (!empty($task['room_label']) ? ' - ' . $task['room_label'] : ''));
      if ($roomText !== '') { echo ' • <strong>Room:</strong> ' . sanitize($roomText); }
    ?>
  </div>

  <?php if (!$photos): ?>
    <div class="empty">No photos for this task.</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($photos as $p): if (empty($p['url'])) continue; ?>
        <article class="imgcard">
          <a class="imgwrap" href="<?php echo sanitize((string)$p['url']); ?>" target="_blank" rel="noopener">
            <img src="<?php echo sanitize((string)$p['url']); ?>" alt="Task photo">
          </a>
          <div class="actions">
            <a class="btn primary" href="<?php echo sanitize((string)$p['url']); ?>" target="_blank" rel="noopener">Open</a>
            <a class="btn" href="<?php echo sanitize((string)$p['url']); ?>" download>Download</a>
          </div>
          <div class="meta"><?php echo !empty($p['created_at']) ? sanitize(substr((string)$p['created_at'],0,16)) : '&nbsp;'; ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
