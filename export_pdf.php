<?php
require_once __DIR__ . '/helpers.php';
require_login();

set_time_limit(180);

/* -------- 1) Selected vs Filters -------- */
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

/* Photos per task (expects $photos[task_id] = [ [ 'url' => ... ], ... ]) */
$taskIds = array_column($tasks, 'id');
$photos  = $taskIds ? fetch_photos_for_tasks($taskIds) : [];

/* -------- 2) Build HTML for wkhtmltopdf -------- */
ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Punch List (Cards with Photos)</title>
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
    page-break-inside: avoid; /* keep each task together if possible */
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

  /* Photo grid */
  .photos {
    display:grid;
    grid-template-columns: repeat(3, 1fr);
    gap:8px;
  }
  .photo-box {
    border:1px solid #e6e9ef; border-radius:6px; background:#fff; padding:4px;
  }
  .photo-box img {
    display:block; width:100%; height:140px; object-fit:cover; border-radius:4px; background:#f6f8fb;
  }
</style>
</head>
<body>

<h1>Punch List Report</h1>
<div class="meta">
  Generated: <?php echo htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'); ?> •
  Total tasks: <?php echo (int)count($tasks); ?> •
  Layout: Cards with photos
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
      $roomText     = ($t['room_number'] ?? '') . (!empty($t['room_label']) ? ' - ' . $t['room_label'] : '');
      $taskPhotos   = !empty($photos[$t['id']]) ? $photos[$t['id']] : [];
      $created      = !empty($t['created_at']) ? substr((string)$t['created_at'], 0, 16) : '';
      $updated      = !empty($t['updated_at']) ? substr((string)$t['updated_at'], 0, 16) : '';
      $due          = !empty($t['due_date'])    ? (string)$t['due_date'] : '—';
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

      <?php if (!empty($taskPhotos)): ?>
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

/* -------- 3) Run wkhtmltopdf -------- */
$pdfFile  = tempnam(sys_get_temp_dir(), 'wkpdf_') . '.pdf';
$htmlFile = tempnam(sys_get_temp_dir(), 'wkhtml_') . '.html';
file_put_contents($htmlFile, $html);

/* Find wkhtmltopdf (common paths or PATH) */
$wkhtml = '/usr/local/bin/wkhtmltopdf';
if (!is_executable($wkhtml)) { $wkhtml = '/usr/bin/wkhtmltopdf'; }
if (!is_executable($wkhtml)) { $wkhtml = 'wkhtmltopdf'; } // assume in PATH

/* Orientation: Portrait (change to 'Landscape' if you prefer) */
$orientation = 'Portrait';

/* If images are behind auth, pass the PHP session cookie */
$cookieArg = '--cookie "PHPSESSID" ' . escapeshellarg(session_id());

$cmd = sprintf(
  '%s --quiet --encoding utf-8 --print-media-type ' .
  '--margin-top 18mm --margin-right 12mm --margin-bottom 18mm --margin-left 12mm ' .
  '--page-size A4 --orientation %s ' .
  '--footer-right "Page [page] of [toPage]" --footer-font-size 9 ' .
  '%s %s %s 2>&1',
  escapeshellarg($wkhtml),
  escapeshellarg($orientation),
  $cookieArg,
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
header('Content-Disposition: inline; filename="punch-list-cards.pdf"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);

/* Cleanup */
@unlink($pdfFile);
