<?php
require_once __DIR__ . '/helpers.php';
require_login();

set_time_limit(180);

/* =========================
   CONFIG
   ========================= */
function base_url_for_pdf(): string {
    if (function_exists('app_base_url')) {
        $u = rtrim((string)app_base_url(), '/');
        if ($u) return $u;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/* =========================
   TOKEN STORAGE (DB)
   ========================= */
function db_apps(): PDO { return get_pdo(); }

/* Create tables if not present (safe to run each time) */
function ensure_public_token_tables(): void {
    $pdo = db_apps();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_task_tokens (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          task_id BIGINT UNSIGNED NOT NULL,
          token VARCHAR(86) NOT NULL UNIQUE,            -- base64url of 48 random bytes ≈ 64 chars; doubled room
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
function b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

/** Generate a cryptographically-strong token (slug) */
function generate_public_token(): string {
    // 48 random bytes → base64url (~64 chars); more than enough entropy
    return b64url(random_bytes(48));
}

/** Get an active token for a task (optionally extend expiry), or create one. */
function get_or_create_task_token(int $taskId, int $ttlDays, ?int $createdById): array {
    $pdo = db_apps();
    $now = new DateTimeImmutable('now');
    $wantExp = $now->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');

    // Try to reuse the newest non-revoked token that hasn't expired yet
    $st = $pdo->prepare("SELECT * FROM public_task_tokens
                         WHERE task_id = :tid AND revoked = 0 AND expires_at > NOW()
                         ORDER BY expires_at DESC LIMIT 1");
    $st->execute([':tid' => $taskId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // If its expiry is sooner than what we want, extend it
        if ((string)$row['expires_at'] < $wantExp) {
            $upd = $pdo->prepare("UPDATE public_task_tokens SET expires_at = :exp WHERE id = :id");
            $upd->execute([':exp'=>$wantExp, ':id'=>(int)$row['id']]);
            $row['expires_at'] = $wantExp;
        }
        return $row;
    }

    // Create a fresh token
    $token = generate_public_token();
    $ins = $pdo->prepare("INSERT INTO public_task_tokens (task_id, token, expires_at, created_by)
                          VALUES (:tid, :tok, :exp, :uid)");
    $ins->execute([
        ':tid' => $taskId,
        ':tok' => $token,
        ':exp' => $wantExp,
        ':uid' => $createdById ?: null
    ]);

    $id = (int)$pdo->lastInsertId();
    return [
        'id'         => $id,
        'task_id'    => $taskId,
        'token'      => $token,
        'expires_at' => $wantExp,
        'created_by' => $createdById,
        'created_at' => date('Y-m-d H:i:s'),
        'last_used_at' => null,
        'use_count'  => 0,
        'revoked'    => 0,
    ];
}

/* =========================
   1) Selected vs Filters
   ========================= */
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
    $tasks   = export_tasks($filters);   // your existing export query
    $summary = filter_summary($filters); // your existing summary
}

/* Pre-req tables */
ensure_public_token_tables();

/* QR options */
$baseUrl = base_url_for_pdf();
$ttlDays = max(1, (int)($_GET['ttl'] ?? 30));             // link validity in days
$qrSize  = max(120, min(300, (int)($_GET['qr'] ?? 180))); // QR size px

/* =========================
   2) Build HTML for wkhtmltopdf
   ========================= */
ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Punch List (Public QR Photo Links)</title>
<style>
  @page { size: A4; margin: 16mm 12mm 16mm 12mm; }

  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; color:#0f172a; font-size:11px; line-height:1.42; }
  h1 { font-size:18px; margin:0 0 8px; color:#0b1220; }
  .muted { color:#6b7280; }
  .meta { font-size:10px; color:#5b6472; margin-bottom:8px; }
  .summary { border:1px solid #e6e9ef; background:#f6f8fb; border-radius:8px; padding:8px; font-size:10px; margin:8px 0 12px; }

  .task {
    border:1px solid #e6e9ef; border-radius:10px; background:#fff;
    padding:12px; margin-bottom:12px;
    page-break-inside: avoid;
  }
  .task-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
  .task-title { font-weight:800; font-size:13px; color:#0b1220; }
  .badges { display:flex; gap:6px; flex-wrap:wrap; }
  .badge {
    display:inline-block; padding:2px 8px; border-radius:999px;
    font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.02em;
    border: 1px solid rgba(0,0,0,.06);
  }
  .priority-none { background:#f3f4f6; color:#4b5563; }
  .priority-low { background:#ecfdf5; color:#065f46; }
  .priority-mid { background:#fffbeb; color:#92400e; }
  .priority-high { background:#fff1f2; color:#991b1b; }
  .priority-lowmid { background:#eef6ff; color:#075985; }
  .priority-midhigh { background:#fde2e1; color:#b91c1c; }
  .status-open { background:#e0f2fe; color:#075985; }
  .status-in_progress { background:#fff6e6; color:#7a4a00; }
  .status-done { background:#e9fff2; color:#065f46; }

  table.meta { width:100%; border-collapse:collapse; margin:6px 0 8px; }
  table.meta td { border:1px solid #e6e9ef; font-size:10px; padding:6px 8px; vertical-align:top; }
  table.meta .key { width:26%; background:#fbfdff; color:#6b7280; font-weight:700; }
  table.meta .val { width:74%; word-break:break-word; }

  .section-title { font-weight:800; font-size:12px; margin:8px 0 6px; color:#0b122a; }

  .qr-row { display:flex; align-items:center; gap:12px; }
  .qr-box {
    border:1px solid #e6e9ef; background:#fff; border-radius:8px; padding:6px;
    width: <?php echo (int)$qrSize + 12; ?>px;
  }
  .qr-box img { display:block; width:<?php echo (int)$qrSize; ?>px; height:<?php echo (int)$qrSize; ?>px; }
  .qr-info { font-size:10px; color:#374151; }
  .qr-info code { font-size:10px; color:#111827; }
</style>
</head>
<body>

<h1>Punch List Report</h1>
<div class="meta">
  Generated: <?php echo htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'); ?> •
  Total tasks: <?php echo (int)count($tasks); ?> •
  Layout: Public QR links to photos (valid <?php echo (int)$ttlDays; ?> days)
</div>
<div class="summary"><strong>Filters:</strong> <?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?></div>

<?php if (empty($tasks)): ?>
  <p class="muted">No tasks found for the selected filters.</p>
<?php else: ?>
  <?php foreach ($tasks as $t): ?>
    <?php
      $priority     = $t['priority'] ?: 'none';
      $priorityLbl  = priority_label($t['priority']);
      $statusLbl    = status_label($t['status']);
      $statusClass  = str_replace('-', '_', (string)$t['status']);
      $roomText     = trim(($t['room_number'] ?? '') . (!empty($t['room_label']) ? ' - ' . $t['room_label'] : ''));
      $created      = !empty($t['created_at']) ? substr((string)$t['created_at'], 0, 16) : '';
      $updated      = !empty($t['updated_at']) ? substr((string)$t['updated_at'], 0, 16) : '';
      $due          = !empty($t['due_date'])    ? (string)$t['due_date'] : '—';

      $tokenRow     = get_or_create_task_token((int)$t['id'], $ttlDays, (int)(current_user()['id'] ?? 0));
      $publicUrl    = $baseUrl . '/public_task_photos.php?t=' . rawurlencode((string)$tokenRow['token']);

      // QR via Google Chart API
      $qrImg        = 'https://chart.googleapis.com/chart?cht=qr&chs=' . (int)$qrSize . 'x' . (int)$qrSize .
                      '&chl=' . rawurlencode($publicUrl) . '&choe=UTF-8';
    ?>
    <div class="task">
      <div class="task-head">
        <div class="task-title">#<?php echo (int)$t['id']; ?> — <?php echo htmlspecialchars($t['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="badges">
          <span class="badge priority-<?php echo htmlspecialchars($priority, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($priorityLbl, ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="badge status-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLbl, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>

      <table class="meta">
        <tr><td class="key">Building</td><td class="val"><?php echo htmlspecialchars((string)($t['building_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td class="key">Room</td><td class="val"><?php echo htmlspecialchars($roomText, ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td class="key">Assigned</td><td class="val"><?php echo htmlspecialchars((string)($t['assigned_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td class="key">Due</td><td class="val"><?php echo htmlspecialchars($due, ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td class="key">Created</td><td class="val"><?php echo htmlspecialchars($created, ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <?php if ($updated): ?>
          <tr><td class="key">Updated</td><td class="val"><?php echo htmlspecialchars($updated, ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <?php endif; ?>
      </table>

      <div class="section-title">Scan to view photos (no login)</div>
      <div class="qr-row">
        <div class="qr-box">
          <img src="<?php echo htmlspecialchars($qrImg, ENT_QUOTES, 'UTF-8'); ?>" alt="QR to task photos">
        </div>
        <div class="qr-info">
          Token expires: <strong><?php echo htmlspecialchars((string)$tokenRow['expires_at'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
          Task ID: <code>#<?php echo (int)$t['id']; ?></code><br>
          URL: <span class="muted"><?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

/* =========================
   3) Run wkhtmltopdf
   ========================= */
$pdfFile  = tempnam(sys_get_temp_dir(), 'wkpdf_') . '.pdf';
$htmlFile = tempnam(sys_get_temp_dir(), 'wkhtml_') . '.html';
file_put_contents($htmlFile, $html);

/* Find wkhtmltopdf (common paths or PATH) */
$wkhtml = '/usr/local/bin/wkhtmltopdf';
if (!is_executable($wkhtml)) { $wkhtml = '/usr/bin/wkhtmltopdf'; }
if (!is_executable($wkhtml)) { $wkhtml = 'wkhtmltopdf'; } // assume in PATH

$orientation = 'Portrait';
$cmd = sprintf(
  '%s --quiet --encoding utf-8 --print-media-type ' .
  '--margin-top 16mm --margin-right 12mm --margin-bottom 16mm --margin-left 12mm ' .
  '--page-size A4 --orientation %s --dpi 150 --images ' .
  '--footer-right "Page [page] of [toPage]" --footer-font-size 9 ' .
  '%s %s %s 2>&1',
  escapeshellarg($wkhtml),
  escapeshellarg($orientation),
  '', // no cookies needed (public viewer)
  escapeshellarg($htmlFile),
  escapeshellarg($pdfFile)
);

$out = [];
$ret = 0;
exec($cmd, $out, $ret);
@unlink($htmlFile);

if ($ret !== 0 || !file_exists($pdfFile)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "wkhtmltopdf failed (code $ret)\nCommand:\n$cmd\n\nOutput:\n" . implode("\n", $out);
  exit;
}

/* Stream inline */
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="punch-list-qr-public.pdf"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);
@unlink($pdfFile);
