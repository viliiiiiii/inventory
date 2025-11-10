<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php'; // NO require_login() here

// ---- Security & meta ----
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

// ---------------------------
// Helpers for this endpoint
// ---------------------------
function pr_table_exists(PDO $pdo, string $tbl): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$tbl]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function pr_s(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// Tables for tokens & hits (room scope)
function pr_ensure_public_room_token_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_room_tokens (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          room_id BIGINT UNSIGNED NOT NULL,
          token VARBINARY(32) NOT NULL,
          expires_at DATETIME NOT NULL,
          revoked TINYINT(1) NOT NULL DEFAULT 0,
          use_count INT UNSIGNED NOT NULL DEFAULT 0,
          last_used_at DATETIME NULL,
          UNIQUE KEY uniq_token (token),
          INDEX idx_room_exp (room_id, expires_at),
          INDEX idx_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_room_hits (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          token_id BIGINT UNSIGNED NOT NULL,
          room_id BIGINT UNSIGNED NOT NULL,
          ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          ip VARBINARY(16) NULL,
          ua VARCHAR(255) NULL,
          INDEX idx_token (token_id),
          INDEX idx_room (room_id),
          CONSTRAINT fk_room_hits_token FOREIGN KEY (token_id)
            REFERENCES public_room_tokens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// Optional: light wrapper for photo fetching if your helper doesn’t exist
function pr_fetch_photos_for_tasks(PDO $pdo, array $taskIds): array {
    if (!$taskIds) return [];
    // If your project already has fetch_photos_for_tasks(), prefer it:
    if (function_exists('fetch_photos_for_tasks')) {
        return fetch_photos_for_tasks($taskIds);
    }
    // Fallback to a direct query on task_photos
    if (!pr_table_exists($pdo, 'task_photos')) return [];
    $in = implode(',', array_fill(0, count($taskIds), '?'));
    $sql = "SELECT task_id, url FROM task_photos WHERE task_id IN ($in) ORDER BY id";
    $st = $pdo->prepare($sql);
    $st->execute($taskIds);
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$r['task_id'];
        $out[$tid][] = ['url' => (string)$r['url']];
    }
    return $out;
}

// ---------------------------
// 1) Read + validate token
// ---------------------------
$token = (string)($_GET['t'] ?? '');
$token = trim($token);

// Basic URL-safe Base64 token sanity (matches what we generated)
if ($token === '' || !preg_match('/^[A-Za-z0-9\-_]{10,}$/', $token)) {
    http_response_code(404);
    echo "<!doctype html><meta charset='utf-8'><title>Not found</title><p>Invalid or missing link.</p>";
    exit;
}

$pdo = get_pdo();
pr_ensure_public_room_token_tables($pdo);

// Find token row (not expired, not revoked)
$st = $pdo->prepare("SELECT * FROM public_room_tokens WHERE token = :tok AND revoked = 0 AND expires_at > NOW() LIMIT 1");
$st->bindValue(':tok', $token, PDO::PARAM_STR); // token stored as VARBINARY; we inserted ASCII bytes earlier, so direct bind matches the bytes
$st->execute();
$trow = $st->fetch(PDO::FETCH_ASSOC);

if (!$trow) {
    http_response_code(410);
    echo "<!doctype html><meta charset='utf-8'><title>Link expired</title><p>This link is invalid or has expired.</p>";
    exit;
}

$tokenId = (int)$trow['id'];
$roomId  = (int)$trow['room_id'];

// ---------------------------
// 2) Log the hit
// ---------------------------
try {
    $ip = null;
    $ipRaw = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $pton = @inet_pton($ipRaw);
    if ($pton !== false) $ip = $pton;
    $ua  = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $ins = $pdo->prepare("INSERT INTO public_room_hits (token_id, room_id, ip, ua) VALUES (:tid, :rid, :ip, :ua)");
    $ins->bindValue(':tid', $tokenId, PDO::PARAM_INT);
    $ins->bindValue(':rid', $roomId, PDO::PARAM_INT);
    if ($ip === null) { $ins->bindValue(':ip', null, PDO::PARAM_NULL); } else { $ins->bindValue(':ip', $ip, PDO::PARAM_LOB); }
    $ins->bindValue(':ua', $ua, PDO::PARAM_STR);
    $ins->execute();

    $upd = $pdo->prepare("UPDATE public_room_tokens SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?");
    $upd->execute([$tokenId]);
} catch (Throwable $e) {
    // soft fail on logging
}

// ---------------------------
// 3) Load room + tasks + photos
// ---------------------------
$room = null;
try {
    $rs = $pdo->prepare("SELECT r.*, b.name AS building_name FROM rooms r JOIN buildings b ON b.id = r.building_id WHERE r.id = ? LIMIT 1");
    $rs->execute([$roomId]);
    $room = $rs->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$room) {
    http_response_code(404);
    echo "<!doctype html><meta charset='utf-8'><title>Not found</title><p>Room not found.</p>";
    exit;
}

// Tasks in this room (show newest first)
$tasks = [];
try {
    $ts = $pdo->prepare("
        SELECT t.*
        FROM tasks t
        WHERE t.room_id = ?
        ORDER BY t.created_at DESC, t.id DESC
    ");
    $ts->execute([$roomId]);
    $tasks = $ts->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$taskIds = array_map(static fn($t) => (int)$t['id'], $tasks);
$photosByTask = pr_fetch_photos_for_tasks($pdo, $taskIds);

// ---------------------------
// 4) Render public gallery
// ---------------------------
$roomLabel = trim((string)$room['room_number'] . (empty($room['label']) ? '' : ' - ' . $room['label']));
$expiresAt = pr_s((string)$trow['expires_at']);
$building  = pr_s((string)$room['building_name']);
$title     = "Room $roomLabel • $building — Photos";
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title; ?></title>
<meta name="robots" content="noindex,nofollow">
<style>
  :root{
    --ink:#0f172a; --muted:#667085; --bg:#f7f8fb; --card:#ffffff; --line:#e6e9ef;
    --brand:#1d4ed8; --chip:#eef2ff; --chip-ink:#3730a3;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--ink);font:400 16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif}
  .wrap{max-width:980px;margin:0 auto;padding:20px}
  header.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 4px 14px rgba(16,24,40,.05)}
  h1{font-size:20px;margin:0 0 4px 0}
  .sub{color:var(--muted);font-size:14px;margin:0}
  .chips{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
  .chip{background:var(--chip);color:var(--chip-ink);padding:6px 10px;border-radius:999px;font-weight:600;font-size:12px;border:1px solid #e9e8ff}
  .notice{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:8px 12px;font-size:13px;margin-top:10px}

  .task{background:var(--card);border:1px solid var(--line);border-radius:14px;margin:16px 0;overflow:hidden}
  .task-h{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:14px 14px;border-bottom:1px solid var(--line)}
  .task-title{font-weight:700}
  .task-meta{color:var(--muted);font-size:13px}
  .grid{display:grid;gap:10px;padding:14px}
  .grid.photos{grid-template-columns: repeat(auto-fill, minmax(180px,1fr));}
  .ph{background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:6px;transition:transform .15s}
  .ph img{display:block;width:100%;height:180px;object-fit:cover;border-radius:8px}
  .ph:hover{transform:translateY(-2px)}

  /* Lightbox */
  .lb{position:fixed;inset:0;background:rgba(15,23,42,.7);display:none;align-items:center;justify-content:center;padding:24px;z-index:50}
  .lb.open{display:flex}
  .lb-inner{background:#0b1220;border-radius:12px;max-width:95vw;max-height:90vh;padding:10px;position:relative}
  .lb-inner img{display:block;max-width:90vw;max-height:80vh;width:auto;height:auto}
  .lb-x{position:absolute;top:8px;right:8px;background:#111827;color:#fff;border:none;border-radius:999px;width:36px;height:36px;font-size:20px;cursor:pointer}

  @media (min-width:1000px){
    .wrap{max-width:1200px}
    .grid.photos .ph img{height:210px}
  }
</style>
</head>
<body>
<div class="wrap">

  <header class="card">
    <h1><?= pr_s("Room $roomLabel"); ?></h1>
    <p class="sub"><?= $building; ?></p>
    <div class="chips">
      <span class="chip"><?= count($tasks); ?> tasks</span>
      <span class="chip">Public link</span>
    </div>
    <div class="notice">Link valid until <strong><?= $expiresAt; ?></strong>. Accesses are logged.</div>
  </header>

  <?php if (!$tasks): ?>
    <section class="card" style="padding:18px;">
      <p class="sub">No tasks found for this room.</p>
    </section>
  <?php else: ?>
    <?php foreach ($tasks as $t): ?>
      <?php
        $tid    = (int)$t['id'];
        $ptitle = pr_s((string)($t['title'] ?? 'Task #'.$tid));
        $status = function_exists('status_label') ? pr_s(status_label($t['status'] ?? '')) : pr_s((string)($t['status'] ?? ''));
        $prior  = function_exists('priority_label') ? pr_s(priority_label($t['priority'] ?? '')) : pr_s((string)($t['priority'] ?? ''));
        $due    = !empty($t['due_date']) ? pr_s((string)$t['due_date']) : '—';
        $created= !empty($t['created_at']) ? pr_s(substr((string)$t['created_at'],0,16)) : '';
        $photos = $photosByTask[$tid] ?? [];
      ?>
      <article class="task" id="task-<?= $tid; ?>">
        <div class="task-h">
          <div class="task-title"><?= $ptitle; ?></div>
          <div class="task-meta">
            Status: <strong><?= $status; ?></strong> •
            Priority: <strong><?= $prior; ?></strong> •
            Due: <strong><?= $due; ?></strong>
            <?php if ($created): ?> • Created: <span><?= $created; ?></span><?php endif; ?>
          </div>
        </div>

        <?php if (!empty($t['description'])): ?>
          <div style="padding:0 14px 8px 14px;color:#3a4759;white-space:pre-wrap;"><?= pr_s((string)$t['description']); ?></div>
        <?php endif; ?>

        <?php if ($photos): ?>
          <div class="grid photos">
            <?php foreach ($photos as $p): if (empty($p['url'])) continue; $u = (string)$p['url']; ?>
              <a class="ph" href="<?= pr_s($u); ?>" data-lb>
                <img src="<?= pr_s($u); ?>" alt="Task photo">
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="padding:12px 14px;color:#94a3b8;font-size:14px">No photos for this task.</div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<!-- Lightbox -->
<div class="lb" id="lb">
  <div class="lb-inner">
    <button class="lb-x" type="button" aria-label="Close">&times;</button>
    <img id="lbImg" src="" alt="">
  </div>
</div>

<script>
  // Simple lightbox
  (function(){
    const lb = document.getElementById('lb');
    const lbImg = document.getElementById('lbImg');
    const close = () => { lb.classList.remove('open'); lbImg.src=''; }
    document.addEventListener('click', (e) => {
      const a = e.target.closest('[data-lb]');
      if (!a) return;
      e.preventDefault();
      lbImg.src = a.getAttribute('href');
      lb.classList.add('open');
    });
    lb.addEventListener('click', (e) => {
      if (e.target === lb || e.target.classList.contains('lb-x')) close();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  })();
</script>
</body>
</html>
