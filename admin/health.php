<?php
// /admin/health.php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_login();

if (current_user_role_key() !== 'root') {
  http_response_code(403);
  exit('Forbidden: root only');
}

$title = 'System Health';
$deep  = isset($_GET['deep']) && $_GET['deep'] !== '0';
$json  = isset($_GET['format']) && $_GET['format'] === 'json';

/* ---------- helpers ---------- */
function ok($cond): string { return $cond ? 'ok' : 'err'; }
function warn_if($cond): string { return $cond ? 'warn' : 'ok'; }
function bool_str($b): string { return $b ? 'Yes' : 'No'; }
function safe_int(?string $v, int $fallback = 0): int { return is_numeric($v ?? '') ? (int)$v : $fallback; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function try_call(callable $fn) {
  try { return $fn(); } catch (Throwable $e) { return ['__error__' => $e->getMessage()]; }
}

function table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}

function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}

function read_log_tail(string $path, int $lines = 200): array {
  if (!is_file($path) || !is_readable($path)) return [];
  $f = new SplFileObject($path, 'r');
  $f->seek(PHP_INT_MAX);
  $last = $f->key();
  $from = max(0, $last - $lines);
  $out = [];
  for ($i = $from; $i <= $last; $i++) {
    $f->seek($i);
    $out[] = rtrim((string)$f->current(), "\r\n");
  }
  return $out;
}

function which(string $bin): array {
  $out = [];
  $ret = 0;
  @exec('which ' . escapeshellarg($bin) . ' 2>&1', $out, $ret);
  return [$ret === 0, implode("\n", $out)];
}

/* ---------- collect data ---------- */
$report = [
  'generated_at' => date('c'),
  'app_title'    => defined('APP_TITLE') ? APP_TITLE : 'n/a',
  'user'         => current_user()['email'] ?? 'root',
  'deep'         => $deep,
  'php'          => [],
  'env'          => [],
  'db'           => [],
  'filesystem'   => [],
  'binaries'     => [],
  'libraries'    => [],
  'errors'       => [],
];

$report['php'] = [
  'version'          => PHP_VERSION,
  'sapi'             => PHP_SAPI,
  'extensions'       => get_loaded_extensions(),
  'ini' => [
    'memory_limit'      => ini_get('memory_limit'),
    'max_execution_time'=> ini_get('max_execution_time'),
    'post_max_size'     => ini_get('post_max_size'),
    'upload_max_filesize'=> ini_get('upload_max_filesize'),
    'display_errors'    => ini_get('display_errors'),
    'error_log'         => ini_get('error_log'),
    'session.save_path' => ini_get('session.save_path'),
    'upload_tmp_dir'    => ini_get('upload_tmp_dir'),
    'opcache.enable'    => ini_get('opcache.enable'),
  ],
];

$report['env'] = [
  'https'                 => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'host'                  => $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'cli'),
  'server_addr'           => $_SERVER['SERVER_ADDR'] ?? '',
  'remote_addr'           => $_SERVER['REMOTE_ADDR'] ?? '',
  'app_base_url'          => defined('APP_BASE_URL') ? APP_BASE_URL : '',
  'document_root'         => $_SERVER['DOCUMENT_ROOT'] ?? '',
];

$pdoApp  = null;
$pdoCore = null;
$appDbOk = false;
$coreDbOk= false;

/* DB: app */
$report['db']['app'] = try_call(function() use (&$pdoApp, &$appDbOk, $deep) {
  $pdoApp = get_pdo();
  $t0 = microtime(true);
  $one = $pdoApp->query('SELECT 1')->fetchColumn();
  $ms = round((microtime(true)-$t0)*1000,1);
  $tables = ['tasks','buildings','rooms','notes','note_photos','note_comments','notes_shares',
             'tags','note_tags','inventory_items','inventory_movements','public_task_tokens','public_room_tokens'];
  $tbl = [];
  foreach ($tables as $t) { $tbl[$t] = table_exists($pdoApp, $t); }

  // Special schema checks
  $sharesHasUser = table_exists($pdoApp,'notes_shares') && column_exists($pdoApp,'notes_shares','user_id');
  $sharesHasOld  = table_exists($pdoApp,'notes_shares') && column_exists($pdoApp,'notes_shares','shared_with');

  $counts = [];
  if ($deep) {
    foreach (['tasks','notes','inventory_items'] as $ct) {
      if (table_exists($pdoApp,$ct)) {
        $counts[$ct] = (int)$pdoApp->query("SELECT COUNT(*) FROM `$ct`")->fetchColumn();
      }
    }
  }

  return [
    'connected'  => (int)$one === 1,
    'latency_ms' => $ms,
    'tables'     => $tbl,
    'notes_shares_user_id' => $sharesHasUser,
    'notes_shares_shared_with' => $sharesHasOld,
    'counts'     => $counts,
  ];
});
$appDbOk = !isset($report['db']['app']['__error__']) && !empty($report['db']['app']['connected']);

/* DB: core */
$report['db']['core'] = try_call(function() use (&$pdoCore, $deep) {
  $pdoCore = get_pdo('core');
  $t0 = microtime(true);
  $one = $pdoCore->query('SELECT 1')->fetchColumn();
  $ms = round((microtime(true)-$t0)*1000,1);
  $tables = ['users','sectors','activity_log'];
  $tbl = [];
  foreach ($tables as $t) { $tbl[$t] = table_exists($pdoCore, $t); }

  $counts = [];
  if ($deep && $tbl['users'] ?? false) {
    $counts['users'] = (int)$pdoCore->query('SELECT COUNT(*) FROM users')->fetchColumn();
  }

  return [
    'connected'  => (int)$one === 1,
    'latency_ms' => $ms,
    'tables'     => $tbl,
    'counts'     => $counts,
  ];
});
$coreDbOk = !isset($report['db']['core']['__error__']) && !empty($report['db']['core']['connected']);

/* Filesystem */
$sysTmp = sys_get_temp_dir();
$upTmp  = ini_get('upload_tmp_dir') ?: $sysTmp;
$sesDir = ini_get('session.save_path') ?: $sysTmp;

$report['filesystem'] = [
  'sys_temp'         => $sysTmp,
  'sys_temp_writable'=> is_writable($sysTmp),
  'upload_tmp_dir'   => $upTmp,
  'upload_tmp_writable'=> is_writable($upTmp),
  'session_save_path'=> $sesDir,
  'session_save_writable'=> is_writable($sesDir),
  'disk_root_total'  => @disk_total_space('/') ?: 0,
  'disk_root_free'   => @disk_free_space('/') ?: 0,
];

/* Binaries */
[$wkOk, $wkPath] = which('wkhtmltopdf');
$wkVersionOut = [];
$wkRet = 0;
if ($wkOk) { @exec('wkhtmltopdf --version 2>&1', $wkVersionOut, $wkRet); }
$report['binaries'] = [
  'wkhtmltopdf_found'   => $wkOk,
  'wkhtmltopdf_which'   => $wkPath,
  'wkhtmltopdf_version' => implode(' ', $wkVersionOut),
];

/* Libraries */
$report['libraries'] = [
  'dompdf'     => class_exists('Dompdf\Dompdf'),
  'phpqrcode'  => function_exists('QRcode'),
  'gd'         => extension_loaded('gd'),
  'imagick'    => extension_loaded('imagick'),
  'mbstring'   => extension_loaded('mbstring'),
  'pdo_mysql'  => extension_loaded('pdo_mysql'),
  'openssl'    => extension_loaded('openssl'),
  'zip'        => extension_loaded('zip'),
];

/* Error log tail */
$errPath = (string)ini_get('error_log');
$tail = $errPath ? read_log_tail($errPath, $deep ? 500 : 200) : [];
$report['errors'] = [
  'error_log' => $errPath,
  'tail'      => $tail,
];

/* Activity record (best-effort) */
try { log_event('admin.health.view', 'system', null); } catch (Throwable $e) {}

/* ---------- JSON mode ---------- */
if ($json) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

/* ---------- HTML render ---------- */
include __DIR__ . '/../includes/header.php';
?>
<style>
  /* compact, readable, light mode, slightly “techy” */
  .health-wrap{max-width:1200px;margin:0 auto}
  .health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
  .hcard{border:1px solid #e6e9ef;border-radius:14px;background:#fff;padding:14px}
  .hcard h3{margin:0 0 8px;font-size:16px}
  .kv{display:grid;grid-template-columns:180px 1fr;gap:8px 10px;font-size:14px}
  .kv .k{color:#6b7280}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700}
  .badge.ok{background:#ecfdf5;color:#065f46}
  .badge.warn{background:#fffbeb;color:#92400e}
  .badge.err{background:#fff1f2;color:#991b1b}
  .mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px}
  details.log{max-height:320px;overflow:auto;border:1px dashed #e6e9ef;border-radius:10px;padding:8px;background:#fbfdff}
  .pill{display:inline-block;padding:2px 6px;border:1px solid #e6e9ef;border-radius:999px;margin:2px;background:#f7fafc}
  .list{margin:0;padding-left:16px}
  .topbar-actions{display:flex;gap:8px;flex-wrap:wrap}
  .muted{color:#6b7280}
</style>

<section class="card health-wrap">
  <div class="card-header">
    <h1>System Health</h1>
    <div class="actions topbar-actions">
      <a class="btn" href="?deep=<?php echo $deep ? '0':'1'; ?>">Toggle Deep: <?php echo $deep?'ON':'OFF'; ?></a>
      <a class="btn" href="?format=json<?php echo $deep?'&deep=1':''; ?>" target="_blank">Download JSON</a>
      <a class="btn" href="activity.php" target="_blank">Activity Log</a>
    </div>
  </div>

  <p class="muted">Generated at <strong><?php echo h($report['generated_at']); ?></strong> • Deep checks: <strong><?php echo $deep?'ON':'OFF'; ?></strong></p>

  <div class="health-grid">
    <div class="hcard">
      <h3>Overall</h3>
      <div class="kv">
        <div class="k">App Title</div><div><?php echo h($report['app_title']); ?></div>
        <div class="k">Viewer</div><div><?php echo h($report['user']); ?></div>
        <div class="k">Host</div><div><?php echo h($report['env']['host']); ?></div>
        <div class="k">HTTPS</div><div><span class="badge <?php echo ok($report['env']['https']); ?>"><?php echo bool_str($report['env']['https']); ?></span></div>
      </div>
    </div>

    <div class="hcard">
      <h3>PHP Runtime</h3>
      <div class="kv">
        <div class="k">Version</div><div><?php echo h($report['php']['version']); ?></div>
        <div class="k">SAPI</div><div><?php echo h($report['php']['sapi']); ?></div>
        <div class="k">memory_limit</div><div class="mono"><?php echo h($report['php']['ini']['memory_limit']); ?></div>
        <div class="k">max_execution_time</div><div class="mono"><?php echo h($report['php']['ini']['max_execution_time']); ?></div>
        <div class="k">upload_max_filesize</div><div class="mono"><?php echo h($report['php']['ini']['upload_max_filesize']); ?></div>
        <div class="k">post_max_size</div><div class="mono"><?php echo h($report['php']['ini']['post_max_size']); ?></div>
        <div class="k">OPcache</div><div><span class="badge <?php echo ok((int)$report['php']['ini']['opcache.enable'] === 1); ?>"><?php echo ((int)$report['php']['ini']['opcache.enable'] === 1) ? 'Enabled' : 'Disabled'; ?></span></div>
      </div>
      <details style="margin-top:8px">
        <summary>Loaded Extensions (<?php echo count($report['php']['extensions']); ?>)</summary>
        <div style="margin-top:6px">
          <?php foreach ($report['php']['extensions'] as $ext): ?>
            <span class="pill mono"><?php echo h($ext); ?></span>
          <?php endforeach; ?>
        </div>
      </details>
    </div>

    <div class="hcard">
      <h3>DB: App</h3>
      <?php $app = $report['db']['app']; ?>
      <?php if (isset($app['__error__'])): ?>
        <p><span class="badge err">ERROR</span> <?php echo h($app['__error__']); ?></p>
      <?php else: ?>
        <div class="kv">
          <div class="k">Connected</div><div><span class="badge <?php echo ok($app['connected']); ?>"><?php echo $app['connected']?'Yes':'No'; ?></span></div>
          <div class="k">Latency</div><div><?php echo h($app['latency_ms'].' ms'); ?></div>
        </div>
        <details style="margin-top:8px" open>
          <summary>Tables</summary>
          <ul class="list">
            <?php foreach ($app['tables'] as $tName => $exists): ?>
              <li>
                <span class="badge <?php echo ok($exists); ?>"><?php echo $exists?'OK':'MISSING'; ?></span>
                <span class="mono"><?php echo h($tName); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
        <div class="kv" style="margin-top:8px">
          <div class="k">notes_shares.user_id</div><div><span class="badge <?php echo ok($app['notes_shares_user_id'] ?? false); ?>"><?php echo ($app['notes_shares_user_id'] ?? false)?'Yes':'No'; ?></span></div>
          <div class="k">notes_shares.shared_with</div><div><span class="badge <?php echo warn_if($app['notes_shares_shared_with'] ?? false); ?>"><?php echo ($app['notes_shares_shared_with'] ?? false)?'Legacy present':'No'; ?></span></div>
        </div>
        <?php if ($deep && !empty($app['counts'])): ?>
          <div class="kv" style="margin-top:8px">
            <?php foreach ($app['counts'] as $k => $v): ?>
              <div class="k">count(<?php echo h($k); ?>)</div><div class="mono"><?php echo (int)$v; ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="hcard">
      <h3>DB: Core</h3>
      <?php $core = $report['db']['core']; ?>
      <?php if (isset($core['__error__'])): ?>
        <p><span class="badge err">ERROR</span> <?php echo h($core['__error__']); ?></p>
      <?php else: ?>
        <div class="kv">
          <div class="k">Connected</div><div><span class="badge <?php echo ok($core['connected']); ?>"><?php echo $core['connected']?'Yes':'No'; ?></span></div>
          <div class="k">Latency</div><div><?php echo h($core['latency_ms'].' ms'); ?></div>
        </div>
        <details style="margin-top:8px" open>
          <summary>Tables</summary>
          <ul class="list">
            <?php foreach ($core['tables'] as $tName => $exists): ?>
              <li>
                <span class="badge <?php echo ok($exists); ?>"><?php echo $exists?'OK':'MISSING'; ?></span>
                <span class="mono"><?php echo h($tName); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
        <?php if ($deep && !empty($core['counts'])): ?>
          <div class="kv" style="margin-top:8px">
            <?php foreach ($core['counts'] as $k => $v): ?>
              <div class="k">count(<?php echo h($k); ?>)</div><div class="mono"><?php echo (int)$v; ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="hcard">
      <h3>Files & Disk</h3>
      <div class="kv">
        <div class="k">sys temp</div><div class="mono"><?php echo h($report['filesystem']['sys_temp']); ?> <span class="badge <?php echo ok($report['filesystem']['sys_temp_writable']); ?>"><?php echo bool_str($report['filesystem']['sys_temp_writable']); ?></span></div>
        <div class="k">upload tmp</div><div class="mono"><?php echo h($report['filesystem']['upload_tmp_dir']); ?> <span class="badge <?php echo ok($report['filesystem']['upload_tmp_writable']); ?>"><?php echo bool_str($report['filesystem']['upload_tmp_writable']); ?></span></div>
        <div class="k">session path</div><div class="mono"><?php echo h($report['filesystem']['session_save_path']); ?> <span class="badge <?php echo ok($report['filesystem']['session_save_writable']); ?>"><?php echo bool_str($report['filesystem']['session_save_writable']); ?></span></div>
        <div class="k">disk total</div><div class="mono"><?php echo number_format((int)($report['filesystem']['disk_root_total'] / (1024*1024*1024)),1); ?> GB</div>
        <div class="k">disk free</div><div class="mono"><?php echo number_format((int)($report['filesystem']['disk_root_free'] / (1024*1024*1024)),1); ?> GB</div>
      </div>
    </div>

    <div class="hcard">
      <h3>Binaries</h3>
      <div class="kv">
        <div class="k">wkhtmltopdf</div>
        <div>
          <span class="badge <?php echo ok($report['binaries']['wkhtmltopdf_found']); ?>">
            <?php echo $report['binaries']['wkhtmltopdf_found'] ? 'Found' : 'Missing'; ?>
          </span>
          <?php if ($report['binaries']['wkhtmltopdf_which']): ?>
            <div class="mono"><?php echo nl2br(h($report['binaries']['wkhtmltopdf_which'])); ?></div>
          <?php endif; ?>
          <?php if ($report['binaries']['wkhtmltopdf_version']): ?>
            <div class="mono muted"><?php echo h($report['binaries']['wkhtmltopdf_version']); ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="hcard">
      <h3>Libraries</h3>
      <div class="kv">
        <?php foreach ($report['libraries'] as $lib => $present): ?>
          <div class="k"><?php echo h($lib); ?></div>
          <div><span class="badge <?php echo ok($present); ?>"><?php echo $present ? 'Yes' : 'No'; ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="hcard" style="grid-column: 1 / -1;">
      <h3>Error Log Tail</h3>
      <?php if ($report['errors']['error_log']): ?>
        <p class="mono"><strong>Path:</strong> <?php echo h($report['errors']['error_log']); ?></p>
      <?php else: ?>
        <p class="muted">No error_log configured.</p>
      <?php endif; ?>
      <?php if (!empty($report['errors']['tail'])): ?>
        <details class="log" open>
          <summary>Last <?php echo count($report['errors']['tail']); ?> lines</summary>
          <pre class="mono"><?php echo h(implode("\n", $report['errors']['tail'])); ?></pre>
        </details>
      <?php else: ?>
        <p class="muted">No entries.</p>
      <?php endif; ?>
    </div>

  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php';
