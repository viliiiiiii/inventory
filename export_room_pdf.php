<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_once __DIR__ . '/includes/export_tokens.php';

set_time_limit(120);

$roomId = (int)($_GET['room_id'] ?? 0);
$ttlDays = max(1, min(365, (int)($_GET['ttl'] ?? 30)));
$qrSize  = max(100, min(200, (int)($_GET['qr'] ?? 120))); // Smaller QR code

if ($roomId <= 0) {
    http_response_code(400);
    exit('Room is required.');
}

$pdo = get_pdo();
$roomStmt = $pdo->prepare(
    'SELECT r.id, r.room_number, r.label, r.building_id, b.name AS building_name
     FROM rooms r
     JOIN buildings b ON b.id = r.building_id
     WHERE r.id = ?
     LIMIT 1'
);
$roomStmt->execute([$roomId]);
$roomRow = $roomStmt->fetch(PDO::FETCH_ASSOC);

if (!$roomRow) {
    http_response_code(404);
    exit('Room not found.');
}

$roomNumber   = trim((string)($roomRow['room_number'] ?? ''));
$roomLabel    = trim((string)($roomRow['label'] ?? ''));
$buildingName = trim((string)($roomRow['building_name'] ?? ''));
if ($buildingName === '') {
    $buildingName = 'Building #' . (int)($roomRow['building_id'] ?? 0);
}

$tasks = room_tasks($roomId);
$grouped = group_tasks_by_status($tasks);
$taskCount = count($tasks);

$formatDateTime = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('M j, Y H:i');
    } catch (Exception $e) {
        return (string)$value;
    }
};
$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$baseUrl = base_url_for_pdf();

ensure_public_room_token_tables($pdo);
$tokenRow = fetch_valid_room_tokens($pdo, [$roomId]);
$tokenRow = $tokenRow[$roomId] ?? insert_room_token($pdo, $roomId, $ttlDays);
$token    = is_string($tokenRow['token']) ? $tokenRow['token'] : (string)$tokenRow['token'];
$roomLink = $baseUrl . '/public_room_photos.php?t=' . rawurlencode($token);
$roomQr   = qr_data_uri($roomLink, $qrSize);

ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Room export - <?php echo $h($roomNumber); ?></title>
<style>
  @page { 
    size: A4 landscape; 
    margin: 10mm 12mm 12mm 12mm; 
  }
  body {
    font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
    font-size: 9px;
    color: #0f172a;
    background: #ffffff;
    margin: 0;
    line-height: 1.2;
  }
  .wrapper {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .compact-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 12px 16px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 8px;
  }
  .header-info {
    flex: 1;
  }
  .building-name {
    font-size: 14px;
    font-weight: 700;
    color: #334155;
    margin: 0 0 2px 0;
  }
  .room-number {
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 6px 0;
  }
  .task-count {
    font-size: 11px;
    color: #64748b;
    font-weight: 600;
    margin: 0;
  }
  .qr-section {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }
  .qr-code {
    width: <?php echo (int)$qrSize; ?>px;
    height: <?php echo (int)$qrSize; ?>px;
  }
  .qr-caption {
    font-size: 8px;
    color: #64748b;
    font-weight: 600;
    text-align: center;
  }
  .qr-link {
    font-size: 7px;
    color: #94a3b8;
    text-align: center;
    max-width: <?php echo (int)($qrSize + 20); ?>px;
    word-break: break-all;
    text-decoration: none;
  }
  .qr-link:hover {
    color: #3b82f6;
  }
  .section {
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    padding: 16px 20px;
  }
  .tasks-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 16px;
  }
  .tasks-table th {
    background: #f1f5f9;
    padding: 6px 8px;
    text-align: left;
    font-weight: 700;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #e2e8f0;
  }
  .tasks-table td {
    padding: 6px 8px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
  }
  .tasks-table tr:hover {
    background: #f8fafc;
  }
  .status-badge, .priority-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .status-open { background: #dbeafe; color: #1e40af; }
  .status-in_progress { background: #fef3c7; color: #92400e; }
  .status-done { background: #d1fae5; color: #065f46; }
  .priority-high { background: #fee2e2; color: #dc2626; }
  .priority-midhigh { background: #fed7aa; color: #ea580c; }
  .priority-mid { background: #fef08a; color: #ca8a04; }
  .priority-lowmid { background: #bbf7d0; color: #16a34a; }
  .priority-low { background: #99f6e4; color: #0d9488; }
  .priority-none { background: #f1f5f9; color: #64748b; }
  .task-description {
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .empty-state {
    margin: 16px 0;
    padding: 16px;
    border-radius: 6px;
    border: 1px dashed #cbd5e1;
    background: #f8fafc;
    color: #64748b;
    text-align: center;
    font-size: 10px;
  }
  .group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid #e2e8f0;
  }
  .group-title {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .group-count {
    font-size: 9px;
    color: #64748b;
    font-weight: 600;
  }
</style>
</head>
<body>
  <div class="wrapper">
    <!-- Compact Header -->
    <div class="compact-header">
      <div class="header-info">
        <div class="building-name"><?php echo $h($buildingName); ?></div>
        <div class="room-number">#<?php echo $h($roomNumber); ?></div>
        <div class="task-count"><?php echo $h($taskCount); ?> tasks</div>
      </div>
      <div class="qr-section">
        <?php if ($roomQr): ?>
          <img class="qr-code" src="<?php echo $h($roomQr); ?>" alt="QR code for room">
          <div class="qr-caption">Scan for live updates</div>
          <a href="<?php echo $h($roomLink); ?>" class="qr-link" target="_blank"><?php echo $h($roomLink); ?></a>
        <?php else: ?>
          <div class="qr-caption">QR code unavailable</div>
        <?php endif; ?>
      </div>
    </div>

    <section class="section">
      <?php if ($taskCount === 0): ?>
        <div class="empty-state">No tasks are linked to this room yet. Use the QR code to capture the first punch list items on site.</div>
      <?php else: ?>
        <?php foreach (['open', 'in_progress', 'done'] as $statusKey):
          $tasksInGroup = $grouped[$statusKey] ?? [];
          if (!$tasksInGroup) continue;
        ?>
          <div class="group">
            <div class="group-header">
              <div class="group-title"><?php echo $h(status_label($statusKey)); ?> Tasks</div>
              <div class="group-count"><?php echo $h(count($tasksInGroup)); ?> task<?php echo count($tasksInGroup) === 1 ? '' : 's'; ?></div>
            </div>
            <table class="tasks-table">
              <thead>
                <tr>
                  <th width="50">ID</th>
                  <th width="260">Title</th>
                  <th width="70">Status</th>
                  <th width="70">Priority</th>
                  <th width="100">Assigned To</th>
                  <th width="260">Description</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tasksInGroup as $task):
                  $priorityValue = (string)($task['priority'] ?? '');
                  $statusValue = (string)($task['status'] ?? 'open');
                  $assigned = trim((string)($task['assigned_to'] ?? ''));
                  $desc = trim((string)($task['description'] ?? ''));
                  
                  // Simple date formatting
                  $formatDate = function(?string $value) {
                    if (!$value) return '—';
                    try {
                      return (new DateTimeImmutable($value))->format('M j, Y');
                    } catch (Exception $e) {
                      return $value;
                    }
                  };
                ?>
                  <tr>
                    <td><strong>#<?php echo $h($task['id']); ?></strong></td>
                    <td><?php echo $h($task['title'] ?? ''); ?></td>
                    <td>
                      <span class="status-badge status-<?php echo $h($statusValue); ?>">
                        <?php echo $h(status_label($statusValue)); ?>
                      </span>
                    </td>
                    <td>
                      <span class="priority-badge priority-<?php echo $h(strtolower(str_replace('/', '', $priorityValue))); ?>">
                        <?php echo $h(priority_label($priorityValue)); ?>
                      </span>
                    </td>
                    <td><?php echo $assigned !== '' ? $h($assigned) : '—'; ?></td>
                    <td class="task-description" title="<?php echo $h($desc); ?>">
                      <?php echo $desc !== '' ? $h($desc) : '—'; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
<?php
$html = ob_get_clean();

$pdfFile  = tempnam(sys_get_temp_dir(), 'room_pdf_') . '.pdf';
$htmlFile = tempnam(sys_get_temp_dir(), 'room_html_') . '.html';
file_put_contents($htmlFile, $html);

$wkhtml = '/usr/local/bin/wkhtmltopdf';
if (!is_executable($wkhtml)) { $wkhtml = '/usr/bin/wkhtmltopdf'; }
if (!is_executable($wkhtml)) { $wkhtml = 'wkhtmltopdf'; }

$cookieArg = '--cookie "PHPSESSID" ' . escapeshellarg(session_id());
$cmd = sprintf(
    '%s --quiet --encoding utf-8 --print-media-type '
    . '--margin-top 10mm --margin-right 12mm --margin-bottom 12mm --margin-left 12mm '
    . '--page-size A4 --orientation Landscape '
    . '--footer-right "Page [page] of [toPage]" --footer-font-size 8 '
    . '%s %s %s 2>&1',
    escapeshellarg($wkhtml),
    $cookieArg,
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

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="room-' . $roomId . '-export.pdf"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);
@unlink($pdfFile);