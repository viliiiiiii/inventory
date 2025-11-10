<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_once __DIR__ . '/includes/export_tokens.php';

set_time_limit(180);

$buildingId = (int)($_GET['building_id'] ?? 0);
if ($buildingId <= 0) {
    http_response_code(400);
    exit('Building is required.');
}

$pdo = get_pdo();
$buildingStmt = $pdo->prepare('SELECT id, name FROM buildings WHERE id = ? LIMIT 1');
$buildingStmt->execute([$buildingId]);
$buildingRow = $buildingStmt->fetch(PDO::FETCH_ASSOC);
if (!$buildingRow) {
    http_response_code(404);
    exit('Building not found.');
}
$buildingName = (string)($buildingRow['name'] ?? ('Building #' . $buildingId));

$ttlDays = max(1, min(365, (int)($_GET['ttl'] ?? 30)));
$qrSize  = max(120, min(280, (int)($_GET['qr'] ?? 180)));

$rooms = fetch_rooms_by_building($buildingId);
$roomPages = [];
foreach ($rooms as $room) {
    $rid = (int)($room['id'] ?? 0);
    $roomPages[$rid] = [
        'meta' => [
            'room_id'     => $rid,
            'room_number' => (string)($room['room_number'] ?? ''),
            'room_label'  => (string)($room['label'] ?? ''),
        ],
        'tasks' => [],
        'status_counts' => [],
        'priority_counts' => [],
    ];
}

$selectedIds = [];
if (!empty($_REQUEST['selected'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', (string)$_REQUEST['selected'])));
}

$summary = '';
if ($selectedIds) {
    $tasks = fetch_tasks_by_ids($selectedIds);
    $tasks = array_values(array_filter($tasks, static function ($task) use ($buildingId) {
        return (int)($task['building_id'] ?? 0) === $buildingId;
    }));
    $summary = 'Selected tasks: ' . implode(', ', $selectedIds);
} else {
    $filters = get_filter_values();
    $filters['building_id'] = $buildingId;
    $filters['room_id'] = null;
    $tasks   = export_tasks($filters);
    $summary = filter_summary($filters);
}

$overallStatus   = [];
$overallPriority = [];
$roomsWithTasks  = 0;
$unassigned = [
    'meta' => [
        'room_id'     => null,
        'room_number' => '—',
        'room_label'  => 'Unassigned tasks',
    ],
    'tasks' => [],
    'status_counts' => [],
    'priority_counts' => [],
    'is_unassigned' => true,
];

$statusOrder = ['open', 'in_progress', 'done'];
$priorityOrder = ['high', 'midhigh', 'mid', 'lowmid', 'low', 'none'];

foreach ($tasks as $task) {
    $rid = (int)($task['room_id'] ?? 0);
    $statusKey = (string)($task['status'] ?? 'open');
    $priorityKey = (string)($task['priority'] ?? 'none');

    $overallStatus[$statusKey] = ($overallStatus[$statusKey] ?? 0) + 1;
    $overallPriority[$priorityKey] = ($overallPriority[$priorityKey] ?? 0) + 1;

    if ($rid && isset($roomPages[$rid])) {
        $roomPages[$rid]['tasks'][] = $task;
        $roomPages[$rid]['status_counts'][$statusKey] = ($roomPages[$rid]['status_counts'][$statusKey] ?? 0) + 1;
        $roomPages[$rid]['priority_counts'][$priorityKey] = ($roomPages[$rid]['priority_counts'][$priorityKey] ?? 0) + 1;
    } else {
        $unassigned['tasks'][] = $task;
        $unassigned['status_counts'][$statusKey] = ($unassigned['status_counts'][$statusKey] ?? 0) + 1;
        $unassigned['priority_counts'][$priorityKey] = ($unassigned['priority_counts'][$priorityKey] ?? 0) + 1;
    }
}

foreach ($roomPages as $rid => &$page) {
    if (!empty($page['tasks'])) {
        $roomsWithTasks++;
    }
    usort($page['tasks'], static function ($a, $b) {
        $statusCmp = strcmp((string)($a['status'] ?? ''), (string)($b['status'] ?? ''));
        if ($statusCmp !== 0) {
            return $statusCmp;
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });
}
unset($page);

if (!empty($unassigned['tasks'])) {
    usort($unassigned['tasks'], static function ($a, $b) {
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });
}

ensure_public_room_token_tables($pdo);
$roomIds = array_keys($roomPages);
$existingTokens = $roomIds ? fetch_valid_room_tokens($pdo, $roomIds) : [];
$baseUrl = base_url_for_pdf();
$publicPath = '/public_room_photos.php';
$roomLinks = [];
$roomQrs   = [];

foreach ($roomIds as $rid) {
    $tokRow = $existingTokens[$rid] ?? insert_room_token($pdo, $rid, $ttlDays);
    $token  = is_string($tokRow['token']) ? $tokRow['token'] : (string)$tokRow['token'];
    $url    = $baseUrl . $publicPath . '?t=' . rawurlencode($token);
    $roomLinks[$rid] = $url;
    $qr = qr_data_uri($url, $qrSize);
    if ($qr) {
        $roomQrs[$rid] = $qr;
    }
}

$sections = array_values($roomPages);
if (!empty($unassigned['tasks'])) {
    $sections[] = $unassigned;
}

$totalTasks = count($tasks);
$generatedAt = date('Y-m-d H:i');

ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Building export - <?php echo htmlspecialchars($buildingName, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
  @page { size: A4; margin: 16mm 14mm 18mm 14mm; }
  body {
    font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
    font-size: 11px;
    color: #0f172a;
    background: #f5f7ff;
    margin: 0;
  }
  h1, h2, h3 { margin: 0; }
  .muted { color: #64748b; }
  .badge {
    display:inline-flex; align-items:center; justify-content:center;
    padding:4px 10px; border-radius:999px;
    background:rgba(59,130,246,.12); color:#1e3a8a;
    font-weight:700; font-size:9px; letter-spacing:.05em; text-transform:uppercase;
  }
  .cover {
    padding: 36px 32px 42px;
    border-radius: 22px;
    background: linear-gradient(145deg, rgba(255,255,255,.94), rgba(214,233,255,.88));
    border: 1px solid rgba(148,163,184,.18);
    box-shadow: 0 28px 60px rgba(15,23,42,.18);
    page-break-after: always;
  }
  .cover__title {
    font-size: 26px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase;
    color: #0b1d4d;
  }
  .cover__subtitle {
    margin-top: 6px; font-size: 14px; color: #334155; letter-spacing: .22em;
    text-transform: uppercase;
  }
  .cover__meta { margin-top: 18px; display:flex; flex-wrap:wrap; gap:14px 24px; font-size: 11px; color:#0f172a; }
  .cover__meta span { font-weight:700; }
  .pill-row { margin-top: 22px; display:flex; flex-wrap:wrap; gap:10px; }
  .pill {
    display:flex; flex-direction:column; align-items:flex-start;
    padding:10px 14px; border-radius:14px;
    background:linear-gradient(135deg, rgba(59,130,246,.12), rgba(14,165,233,.08));
    border:1px solid rgba(148,163,184,.18);
  }
  .pill strong { font-size:13px; }
  .pill small { color:#475569; font-size:10px; text-transform:uppercase; letter-spacing:.08em; }

  .room-section {
    page-break-before: always;
    padding: 24px 26px;
    border-radius: 20px;
    background: linear-gradient(150deg, rgba(255,255,255,.96), rgba(225,234,255,.94));
    border: 1px solid rgba(148,163,184,.2);
    box-shadow: 0 24px 60px rgba(15,23,42,.16);
  }
  .room-header {
    display:flex; flex-wrap:wrap; align-items:flex-end; justify-content:space-between; gap:16px;
    border-bottom:1px solid rgba(148,163,184,.2); padding-bottom:16px; margin-bottom:18px;
  }
  .room-title { font-size:20px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#0b1d4d; }
  .room-subtitle { margin-top:4px; color:#475569; }
  .room-meta { display:flex; flex-wrap:wrap; gap:12px 18px; font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#475569; }
  .meta-value { font-weight:700; color:#0f172a; }
  .qr-wrapper {
    text-align:center; min-width:<?php echo (int)($qrSize + 40); ?>px;
  }
  .qr-halo {
    display:inline-block; padding:14px; border-radius:18px;
    background:linear-gradient(160deg, rgba(15,23,42,.08), rgba(148,163,184,.12));
    box-shadow:0 12px 24px rgba(15,23,42,.12);
  }
  .qr-halo img { display:block; width:<?php echo (int)$qrSize; ?>px; height:<?php echo (int)$qrSize; ?>px; }
  .qr-caption { margin-top:8px; font-size:9px; color:#475569; letter-spacing:.1em; text-transform:uppercase; }

  .stats-grid {
    margin-top:16px; display:grid; gap:10px; grid-template-columns:repeat(auto-fit, minmax(140px,1fr));
  }
  .stat-card {
    padding:10px 12px; border-radius:12px; border:1px solid rgba(148,163,184,.18);
    background:#f8fbff;
  }
  .stat-card strong { display:block; font-size:14px; }
  .stat-card span { display:block; margin-top:4px; font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:.12em; }

  .task-table { width:100%; border-collapse:collapse; margin-top:18px; }
  .task-table th {
    background:#e2e8ff; color:#1e3a8a; font-size:10px; letter-spacing:.1em; text-transform:uppercase;
    padding:8px; text-align:left; border-bottom:2px solid rgba(148,163,184,.35);
  }
  .task-table td {
    padding:7px 8px; border-bottom:1px solid rgba(148,163,184,.25); vertical-align:top; font-size:10px;
  }
  .task-table tr:nth-child(even) { background:rgba(241,245,255,.6); }
  .priority-high { color:#b91c1c; }
  .priority-mid, .priority-midhigh { color:#92400e; }
  .priority-low, .priority-lowmid { color:#065f46; }
  .priority-none { color:#475569; }
  .status-open { color:#0b4ea2; }
  .status-in_progress { color:#92400e; }
  .status-done { color:#0f766e; }
  .empty-note { margin-top:16px; padding:16px; border-radius:12px; border:1px dashed rgba(148,163,184,.4); background:rgba(248,250,255,.8); color:#475569; }
</style>
</head>
<body>
  <section class="cover">
    <div class="badge">Building Export</div>
    <h1 class="cover__title"><?php echo htmlspecialchars($buildingName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="cover__subtitle">Room-by-room punch list</div>
    <div class="cover__meta">
      <div><span><?php echo (int)count($rooms); ?></span> rooms</div>
      <div><span><?php echo (int)$roomsWithTasks; ?></span> rooms with tasks</div>
      <div><span><?php echo (int)$totalTasks; ?></span> total tasks</div>
      <div>Generated <span><?php echo htmlspecialchars($generatedAt, ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div>QR access valid <?php echo (int)$ttlDays; ?> days</div>
    </div>
    <?php if ($summary): ?>
      <div class="empty-note" style="margin-top:18px;">Filters: <?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($overallStatus || $overallPriority): ?>
      <div class="pill-row">
        <?php foreach ($overallStatus as $status => $count): ?>
          <div class="pill">
            <small>Status</small>
            <strong><?php echo htmlspecialchars(status_label((string)$status), ENT_QUOTES, 'UTF-8'); ?></strong>
            <small><?php echo (int)$count; ?> task<?php echo $count === 1 ? '' : 's'; ?></small>
          </div>
        <?php endforeach; ?>
        <?php foreach ($overallPriority as $priority => $count): ?>
          <div class="pill">
            <small>Priority</small>
            <strong><?php echo htmlspecialchars(priority_label((string)$priority), ENT_QUOTES, 'UTF-8'); ?></strong>
            <small><?php echo (int)$count; ?> task<?php echo $count === 1 ? '' : 's'; ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php foreach ($sections as $section):
    $meta = $section['meta'];
    $tasks = $section['tasks'];
    $statusCounts = $section['status_counts'] ?? [];
    $priorityCounts = $section['priority_counts'] ?? [];
    $rid = $meta['room_id'];
    $roomNumber = trim((string)($meta['room_number'] ?? ''));
    $roomLabel  = trim((string)($meta['room_label'] ?? ''));
    $roomHeading = $roomNumber !== '' ? '#' . $roomNumber : ($section['is_unassigned'] ?? false ? 'Unassigned' : 'Room');
    if ($roomLabel !== '') {
        $roomHeading .= ' · ' . $roomLabel;
    }
    $hasQr = ($rid && isset($roomQrs[$rid]));
    $link = $rid && isset($roomLinks[$rid]) ? $roomLinks[$rid] : null;
  ?>
    <section class="room-section">
      <header class="room-header">
        <div>
          <div class="room-title"><?php echo htmlspecialchars($roomHeading, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="room-meta">
            <div>Building <span class="meta-value"><?php echo htmlspecialchars($buildingName, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <div>Tasks <span class="meta-value"><?php echo (int)count($tasks); ?></span></div>
          </div>
        </div>
        <div class="qr-wrapper">
          <?php if ($hasQr): ?>
            <div class="qr-halo">
              <img src="<?php echo htmlspecialchars($roomQrs[$rid], ENT_QUOTES, 'UTF-8'); ?>" alt="QR code for room">
            </div>
            <div class="qr-caption">Scan for live progress</div>
            <?php if ($link): ?><div style="margin-top:4px;font-size:9px;color:#64748b;word-break:break-all;"><?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
          <?php else: ?>
            <div class="qr-caption">QR not available for this section.</div>
          <?php endif; ?>
        </div>
      </header>

      <?php if (!empty($statusCounts) || !empty($priorityCounts)): ?>
        <div class="stats-grid">
          <?php foreach ($statusOrder as $statusKey):
            $count = $statusCounts[$statusKey] ?? 0;
            if ($count <= 0) continue;
          ?>
            <div class="stat-card">
              <small>Status</small>
              <strong><?php echo htmlspecialchars(status_label($statusKey), ENT_QUOTES, 'UTF-8'); ?></strong>
              <span><?php echo (int)$count; ?> task<?php echo $count === 1 ? '' : 's'; ?></span>
            </div>
          <?php endforeach; ?>
          <?php foreach ($priorityOrder as $prioKey):
            $count = $priorityCounts[$prioKey] ?? 0;
            if ($count <= 0) continue;
          ?>
            <div class="stat-card">
              <small>Priority</small>
              <strong><?php echo htmlspecialchars(priority_label($prioKey), ENT_QUOTES, 'UTF-8'); ?></strong>
              <span><?php echo (int)$count; ?> task<?php echo $count === 1 ? '' : 's'; ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$tasks): ?>
        <div class="empty-note">No tasks assigned to this room yet. Scan the QR to add updates in the field.</div>
      <?php else: ?>
        <table class="task-table">
          <thead>
            <tr>
              <th style="width:7%;">ID</th>
              <th style="width:30%;">Task</th>
              <th style="width:13%;">Priority</th>
              <th style="width:14%;">Status</th>
              <th style="width:18%;">Assigned</th>
              <th style="width:10%;">Due</th>
              <th style="width:8%;">Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tasks as $task):
              $priority = (string)($task['priority'] ?? 'none');
              $status   = (string)($task['status'] ?? 'open');
              $dueDate  = (string)($task['due_date'] ?? '');
              $updated  = (string)($task['updated_at'] ?? '');
              $updated  = $updated !== '' ? substr($updated, 0, 10) : '';
              $title    = (string)($task['title'] ?? '');
              $assigned = (string)($task['assigned_to'] ?? '');
            ?>
              <?php
                $priorityClass = preg_replace('/[^a-z0-9]+/', '', strtolower($priority));
                $statusClass   = preg_replace('/[^a-z0-9_]+/', '_', strtolower($status));
              ?>
              <tr>
                <td>#<?php echo (int)$task['id']; ?></td>
                <td><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="priority-<?php echo htmlspecialchars($priorityClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(priority_label($priority), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="status-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(status_label($status), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo $assigned !== '' ? htmlspecialchars($assigned, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                <td><?php echo $dueDate !== '' ? htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                <td><?php echo $updated !== '' ? htmlspecialchars($updated, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</body>
</html>
<?php
$html = ob_get_clean();

$pdfFile  = tempnam(sys_get_temp_dir(), 'building_pdf_') . '.pdf';
$htmlFile = tempnam(sys_get_temp_dir(), 'building_html_') . '.html';
file_put_contents($htmlFile, $html);

$wkhtml = '/usr/local/bin/wkhtmltopdf';
if (!is_executable($wkhtml)) { $wkhtml = '/usr/bin/wkhtmltopdf'; }
if (!is_executable($wkhtml)) { $wkhtml = 'wkhtmltopdf'; }

$cookieArg = '--cookie "PHPSESSID" ' . escapeshellarg(session_id());
$cmd = sprintf(
  '%s --quiet --encoding utf-8 --print-media-type ' .
  '--margin-top 16mm --margin-right 14mm --margin-bottom 18mm --margin-left 14mm ' .
  '--page-size A4 --orientation Portrait ' .
  '--footer-right "Page [page] of [toPage]" --footer-font-size 9 ' .
  '%s %s %s 2>&1',
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
header('Content-Disposition: inline; filename="building-' . $buildingId . '-export.pdf"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);
@unlink($pdfFile);