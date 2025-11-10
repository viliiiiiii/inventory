<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_login();

set_time_limit(180);

/* ============================
   Settings (via GET)
   - ttl: token validity in days (default 30)
   - qr:  QR size in px (120–300; default 180)
   - photos: 0/1 -> whether to embed photos in the PDF (default 0 to keep it light)
   ============================ */
$ttlDays    = max(1, min(365, (int)($_GET['ttl'] ?? 30)));
$qrSize     = max(120, min(300, (int)($_GET['qr'] ?? 180)));
$showPhotos = ((string)($_GET['photos'] ?? '0') === '1');

/* ============================
   Token + logging tables
   (created on first run if missing)
   ============================ */
function ensure_public_token_tables(PDO $pdo): void {
    // Minimal tokens table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_task_tokens (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          task_id BIGINT UNSIGNED NOT NULL,
          token VARBINARY(32) NOT NULL,
          expires_at DATETIME NOT NULL,
          revoked TINYINT(1) NOT NULL DEFAULT 0,
          use_count INT UNSIGNED NOT NULL DEFAULT 0,
          last_used_at DATETIME NULL,
          UNIQUE KEY uniq_token (token),
          INDEX idx_task_exp (task_id, expires_at),
          INDEX idx_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Optional hits table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_token_hits (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          token_id BIGINT UNSIGNED NOT NULL,
          task_id BIGINT UNSIGNED NOT NULL,
          ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          ip VARBINARY(16) NULL,
          ua VARCHAR(255) NULL,
          INDEX idx_token (token_id),
          INDEX idx_task (task_id),
          CONSTRAINT fk_hits_token FOREIGN KEY (token_id) REFERENCES public_task_tokens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

/* ============================
   Base URL detection for public links
   ============================ */
function base_url_for_pdf(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (strpos($host, ':') === false) {
        $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
        if (($https && $port !== 443) || (!$https && $port !== 80)) {
            $host .= ':' . $port;
        }
    }
    return $scheme . '://' . $host;
}

/* ============================
   Token helpers
   ============================ */
function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function random_token(): string {
    return b64url(random_bytes(16));
}

/** Fetch latest non-expired, non-revoked token per task in bulk. */
function fetch_valid_tokens(PDO $pdo, array $taskIds): array {
    if (!$taskIds) return [];
    $in = implode(',', array_fill(0, count($taskIds), '?'));
    $sql = "SELECT t1.*
            FROM public_task_tokens t1
            JOIN (
              SELECT task_id, MAX(id) AS max_id
              FROM public_task_tokens
              WHERE revoked = 0 AND expires_at > NOW() AND task_id IN ($in)
              GROUP BY task_id
            ) t2 ON t1.id = t2.max_id";
    $st = $pdo->prepare($sql);
    $st->execute($taskIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['task_id']] = $r;
    }
    return $out;
}

function insert_token(PDO $pdo, int $taskId, int $ttlDays): array {
    $token  = random_token();
    $expiry = (new DateTimeImmutable('now'))->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');
    $st = $pdo->prepare("INSERT INTO public_task_tokens (task_id, token, expires_at) VALUES (:task, :tok, :exp)");
    $st->execute([
        ':task' => $taskId,
        ':tok'  => $token,
        ':exp'  => $expiry,
    ]);
    $id = (int)$pdo->lastInsertId();
    return [
        'id'          => $id,
        'task_id'     => $taskId,
        'token'       => $token,
        'expires_at'  => $expiry,
        'revoked'     => 0,
        'use_count'   => 0,
        'last_used_at'=> null,
    ];
}

/* ============================
   QR generator (data URI)
   ============================ */
function qr_data_uri(string $url, int $size = 180): ?string {
    static $cache = [];
    $size = max(120, min(300, $size));
    if (isset($cache[$url][$size])) return $cache[$url][$size];

    $endpoint = 'https://quickchart.io/qr';
    $qs = http_build_query([
        'text'   => $url,
        'size'   => $size,
        'margin' => 1,
        'format' => 'png',
    ]);
    $png = @file_get_contents($endpoint . '?' . $qs);
    if ($png === false) return null;

    $data = 'data:image/png;base64,' . base64_encode($png);
    $cache[$url][$size] = $data;
    return $data;
}

/* ============================
   1) Collect tasks (selected vs filters)
   ============================ */
$selectedIds = [];
if (!empty($_REQUEST['selected'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', (string)$_REQUEST['selected'])));
}

if ($selectedIds) {
    $tasks   = fetch_tasks_by_ids($selectedIds);
    $filters = [];
    $summary = 'Selected tasks: ' . implode(', ', $selectedIds);
} else {
    $filters = get_filter_values();
    $tasks   = export_tasks($filters);
    $summary = filter_summary($filters);
}

/* Photos map (won't be embedded unless ?photos=1) */
$taskIds = array_column($tasks, 'id');
$photos  = $taskIds ? fetch_photos_for_tasks($taskIds) : [];

/* ============================
   2) Tokens + public URLs + QR data URIs
   ============================ */
$pdo = get_pdo();
ensure_public_token_tables($pdo);

$existing   = fetch_valid_tokens($pdo, $taskIds);
$base       = base_url_for_pdf();
$publicPath = '/public_task_photos.php'; // The public viewer endpoint (no login)

$publicLinks = [];   // task_id -> url
$qrMap       = [];   // task_id -> data URI

foreach ($tasks as $t) {
    $tid   = (int)$t['id'];
    $tokRow= $existing[$tid] ?? insert_token($pdo, $tid, $ttlDays);
    $token = is_string($tokRow['token']) ? $tokRow['token'] : (string)$tokRow['token'];

    $url = $base . $publicPath . '?t=' . rawurlencode($token);
    $publicLinks[$tid] = $url;

    $qr = qr_data_uri($url, $qrSize);
    if ($qr) $qrMap[$tid] = $qr;
}

/* ============================
   3) Build HTML for wkhtmltopdf
   ============================ */
ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Punch List (QR & Cards)</title>
<style>
  @page { size: A4; margin: 18mm 12mm 18mm 12mm; }

  body { font-family: DejaVu Sans, Arial, sans-serif; color:#162029; font-size:11px; line-height:1.35; }
  h1 { font-size:18px; margin:0 0 6px; }
  .muted { color:#6b7280; }
  .meta { font-size:10px; color:#6b7280; margin-bottom:8px; }
  .summary { border:1px solid #e6e9ef; background:#f6f8fb; border-radius:6px; padding:8px; font-size:10px; margin-bottom:10px; }

  /* Task card */
  .task {
    border:1px solid #e6e9ef; border-radius:8px; background:#fff;
    padding:10px; margin-bottom:10px;
    page-break-inside: avoid;
  }
  .task-head {
    display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px;
  }
  .task-title { font-weight:700; font-size:13px; }
  .badges { display:flex; gap:6px; flex-wrap:wrap; }
  .badge { padding:2px 6px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; }
  .priority-none { background:#f3f4f6; color:#6b7280; }
  .priority-low { background:#ecfdf5; color:#065f46; }
  .priority-mid { background:#fffbeb; color:#92400e; }
  .priority-high { background:#fff1f2; color:#991b1b; }
  .priority-lowmid { background:#e0f2fe; color:#075985; }
  .priority-midhigh { background:#fde2e1; color:#b91c1c; }
  .status-open { background:#e0f2fe; color:#075985; }
  .status-in_progress { background:#fffbeb; color:#92400e; }
  .status-done { background:#ecfdf5; color:#065f46; }

  /* Meta two-column table */
  table.meta { width:100%; border-collapse:collapse; margin:4px 0 6px 0; }
  table.meta td { border:1px solid #e6e9ef; font-size:10px; padding:6px 8px; vertical-align:top; }
  table.meta .key { width:25%; background:#fbfdff; color:#6b7280; font-weight:700; }
  table.meta .val { width:75%; }

  .section-title { font-weight:700; font-size:12px; margin:8px 0 6px; color:#0f172a; }
  .desc { white-space:pre-wrap; }

  /* Layout for QR + details */
  .split {
    display:flex; gap:12px; align-items:flex-start; justify-content:space-between;
  }
  .split .col-info { flex: 1 1 auto; }
  .split .col-qr   { flex: 0 0 auto; text-align:center; }
  .qr-box {
    border:1px dashed #d1d5db; border-radius:8px; padding:6px 8px; width: <?php echo (int)($qrSize + 16); ?>px;
  }
  .qr-box img {
    display:block; width: <?php echo (int)$qrSize; ?>px; height: <?php echo (int)$qrSize; ?>px;
    margin: 0 auto 6px;
  }
  .qr-url {
    font-size:9px; line-height:1.25;
    word-break: break-all;
  }
  .qr-url a {
    color:#1d4ed8; text-decoration:none;
    border-bottom:1px solid #93c5fd;
  }

  /* Photo grid (only if ?photos=1) */
  .photos {
    display:grid;
    grid-template-columns: repeat(3, 1fr);
    gap:8px;
  }
  .photo-box {
    border:1px solid #e6e9ef; border-radius:6px; background:#fff; padding:4px;
  }
  .photo-box img {
    display:block;
    max-width:100%;
    max-height:300px;
    width:auto;
    height:auto;
    margin:0 auto;
    object-fit:contain;
    border-radius:4px;
    background:#f6f8fb;
  }
</style>
</head>
<body>

<h1>Punch List Report</h1>
<div class="meta">
  Generated: <?php echo htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'); ?> •
  Total tasks: <?php echo (int)count($tasks); ?> •
  Layout: QR cards<?php echo $showPhotos ? ' + photos' : ''; ?>
</div>
<div class="summary"><strong>Filters:</strong> <?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?></div>

<?php if (empty($tasks)): ?>
  <p class="muted">No tasks found for the selected filters.</p>
<?php else: ?>
  <?php foreach ($tasks as $t): ?>
    <?php
      $tid          = (int)$t['id'];
      $priority     = $t['priority'] ?: 'none';
      $priorityLbl  = priority_label($t['priority']);
      $statusLbl    = status_label($t['status']);
      $statusClass  = str_replace('-', '_', (string)$t['status']);
      $roomText     = ($t['room_number'] ?? '') . (!empty($t['room_label']) ? ' - ' . $t['room_label'] : '');
      $taskPhotos   = !empty($photos[$tid]) ? $photos[$tid] : [];
      $created      = !empty($t['created_at']) ? substr((string)$t['created_at'], 0, 16) : '';
      $updated      = !empty($t['updated_at']) ? substr((string)$t['updated_at'], 0, 16) : '';
      $due          = !empty($t['due_date'])    ? (string)$t['due_date'] : '—';
      $publicUrl    = $publicLinks[$tid] ?? '';
      $qrDataUri    = $qrMap[$tid] ?? null;
    ?>
    <div class="task">
      <div class="task-head">
        <div class="task-title">#<?php echo $tid; ?> — <?php echo htmlspecialchars($t['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="badges">
          <span class="badge priority-<?php echo htmlspecialchars($priority, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($priorityLbl, ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="badge status-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLbl, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>

      <div class="split">
        <div class="col-info">
          <table class="meta">
            <tr><td class="key">Building</td><td class="val"><?php echo htmlspecialchars($t['building_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="key">Room</td><td class="val"><?php echo htmlspecialchars($roomText, ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="key">Assigned</td><td class="val"><?php echo htmlspecialchars($t['assigned_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="key">Due</td><td class="val"><?php echo htmlspecialchars($due, ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="key">Created</td><td class="val"><?php echo htmlspecialchars($created, ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <?php if ($updated): ?>
              <tr><td class="key">Updated</td><td class="val"><?php echo htmlspecialchars($updated, ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <?php endif; ?>
          </table>

          <?php if (!empty($t['description'])): ?>
            <div class="section-title">Description</div>
            <div class="desc"><?php echo htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>
        </div>

        <div class="col-qr">
          <div class="qr-box">
            <?php if ($qrDataUri): ?>
              <img src="<?php echo $qrDataUri; ?>" alt="QR to public photos">
            <?php else: ?>
              <div class="muted" style="font-size:10px;">QR unavailable</div>
            <?php endif; ?>
            <?php if ($publicUrl): ?>
              <div class="qr-url">
                <a href="<?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($showPhotos && !empty($taskPhotos)): ?>
        <div class="section-title">Photos</div>
        <div class="photos">
          <?php foreach ($taskPhotos as $p): ?>
            <?php if (!empty($p['url'])): ?>
              <div class="photo-box">
                <img src="<?php echo htmlspecialchars($p['url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Photo">
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

/* ============================
   4) Run wkhtmltopdf
   ============================ */
$pdfFile  = tempnam(sys_get_temp_dir(), 'wkpdf_') . '.pdf';
$htmlFile = tempnam(sys_get_temp_dir(), 'wkhtml_') . '.html';
file_put_contents($htmlFile, $html);

/* Find wkhtmltopdf (common paths or PATH) */
$wkhtml = '/usr/local/bin/wkhtmltopdf';
if (!is_executable($wkhtml)) { $wkhtml = '/usr/bin/wkhtmltopdf'; }
if (!is_executable($wkhtml)) { $wkhtml = 'wkhtmltopdf'; } // assume in PATH

/* Orientation: Portrait (change to 'Landscape' if you prefer) */
$orientation = 'Portrait';

/* Note: QR images are embedded as data URIs; no cookies needed */
$cmd = sprintf(
  '%s --quiet --encoding utf-8 --print-media-type ' .
  '--margin-top 18mm --margin-right 12mm --margin-bottom 18mm --margin-left 12mm ' .
  '--page-size A4 --orientation %s ' .
  '--footer-right "Page [page] of [toPage]" --footer-font-size 9 ' .
  '%s %s 2>&1',
  escapeshellarg($wkhtml),
  escapeshellarg($orientation),
  escapeshellarg($htmlFile),
  escapeshellarg($pdfFile)
);

$out = [];
$ret = 0;
exec($cmd, $out, $ret);

/* Clean temp HTML */
@unlink($htmlFile);

if ($ret !== 0 || !file_exists($pdfFile)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "wkhtmltopdf failed (code $ret)\nCommand:\n$cmd\n\nOutput:\n" . implode("\n", $out);
  exit;
}

/* Stream inline */
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="punch-list-cards-qr.pdf"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);

/* Cleanup */
@unlink($pdfFile);
