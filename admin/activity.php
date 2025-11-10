<?php
require_once __DIR__ . '/../helpers.php';
require_perm('view_audit');

$corePdo = get_pdo('core');

$filters = [
    'user_id' => isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null,
    'action'  => trim((string)($_GET['action'] ?? '')),
    'from'    => trim((string)($_GET['from'] ?? '')),
    'to'      => trim((string)($_GET['to'] ?? '')),
];

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where  = [];
$params = [];
if ($filters['user_id']) { $where[] = 'al.user_id = :user_id'; $params[':user_id'] = $filters['user_id']; }
if ($filters['action'] !== '') { $where[] = 'al.action LIKE :action'; $params[':action'] = $filters['action'] . '%'; }
if ($filters['from'] !== '') { $where[] = 'al.ts >= :from'; $params[':from'] = $filters['from']; }
if ($filters['to']   !== '') { $where[] = 'al.ts <= :to';   $params[':to']   = $filters['to'] . ' 23:59:59'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $corePdo->prepare("SELECT COUNT(*) FROM activity_log al $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT al.*, u.email
        FROM activity_log al
        LEFT JOIN users u ON u.id = al.user_id
        $whereSql
        ORDER BY al.id DESC
        LIMIT :limit OFFSET :offset";
$stmt = $corePdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$userOptions = $corePdo->query('SELECT id, email FROM users ORDER BY email')->fetchAll();

function render_ip($binary): string {
    if ($binary === null || $binary === '') return '';
    $ip = @inet_ntop($binary);
    return $ip ?: '';
}

$pages = (int)ceil($total / $perPage);

$title = 'Activity Log';
include __DIR__ . '/../includes/header.php';
?>

<section class="card">
  <div class="card-header">
    <h1>Activity Log</h1>
    <div class="actions">
      <span class="badge">Total: <?php echo number_format($total); ?></span>
    </div>
  </div>

  <form method="get" class="filters">
    <label>User
      <select name="user_id">
        <option value="">All</option>
        <?php foreach ($userOptions as $user): ?>
          <option value="<?php echo (int)$user['id']; ?>" <?php echo ($filters['user_id'] == $user['id']) ? 'selected' : ''; ?>>
            <?php echo sanitize($user['email']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Action
      <input type="text" name="action" value="<?php echo sanitize($filters['action']); ?>" placeholder="e.g. task.create">
    </label>

    <label>From
      <input type="date" name="from" value="<?php echo sanitize($filters['from']); ?>">
    </label>

    <label>To
      <input type="date" name="to" value="<?php echo sanitize($filters['to']); ?>">
    </label>

    <div class="filter-actions">
      <button class="btn primary" type="submit">Filter</button>
      <a class="btn secondary" href="activity.php">Reset</a>
    </div>
  </form>
</section>

<section class="card">
  <h2>Results</h2>

  <table class="table table-excel table--cards compact-rows">
    <thead>
      <tr>
        <th class="col-id">Time</th>
        <th>User</th>
        <th class="col-status">Action</th>
        <th>Entity</th>
        <th>Meta</th>
        <th>IP</th>
        <th>User Agent</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td data-label="Time"><?php echo sanitize($row['ts']); ?></td>

        <td data-label="User">
          <?php echo $row['email'] ? sanitize($row['email']) : '<em class="muted">System</em>'; ?>
        </td>

        <td data-label="Action">
          <span class="badge"><?php echo sanitize($row['action']); ?></span>
        </td>

        <td data-label="Entity">
          <?php
            $entity = trim((string)($row['entity_type'] ?? ''));
            $entityId = (string)($row['entity_id'] ?? '');
            echo sanitize($entity . ($entityId !== '' ? ('#' . $entityId) : ''));
          ?>
        </td>

        <td data-label="Meta">
          <?php if (!empty($row['meta'])): ?>
            <?php
              // Base64 to safely embed raw JSON; decode on click in JS
              $b64 = base64_encode((string)$row['meta']);
              $size = strlen((string)$row['meta']);
            ?>
            <button
              type="button"
              class="btn small js-json-view"
              data-meta-b64="<?php echo htmlspecialchars($b64, ENT_QUOTES, 'UTF-8'); ?>"
              aria-label="View JSON payload">
              View JSON (<?php echo number_format($size); ?> B)
            </button>
          <?php else: ?>
            <span class="muted small">—</span>
          <?php endif; ?>
        </td>

        <td data-label="IP">
          <code><?php echo sanitize(render_ip($row['ip'])); ?></code>
        </td>

        <td data-label="User Agent">
          <span class="muted small"><?php echo sanitize($row['ua'] ?? ''); ?></span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Activity pages">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php $q = http_build_query(array_merge($filters, ['page' => $p])); ?>
        <a class="btn small <?php echo $p === $page ? 'primary' : 'secondary'; ?>" href="?<?php echo $q; ?>">
          <?php echo $p; ?>
        </a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</section>

<!-- JSON Modal (reuses global modal styles used for photo modal) -->
<style>
  /* Minimal tweaks for JSON content area */
  .json-modal-body {
    display: block;             /* not a grid, just a scrollable area */
    padding: 16px;
    overflow: auto;
    background: #fff;
  }
  .json-pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "DejaVu Sans Mono", monospace;
    font-size: 12px;
    line-height: 1.45;
    color: #0f172a;
    background: #f7f9fc;
    border: 1px solid #e6ecf5;
    border-radius: 10px;
    padding: 12px;
  }
</style>

<div id="jsonModal" class="photo-modal hidden" aria-hidden="true">
  <div class="photo-modal-backdrop" data-close-json-modal></div>
  <div class="photo-modal-box" role="dialog" aria-modal="true" aria-labelledby="jsonModalTitle">
    <div class="photo-modal-header">
      <h3 id="jsonModalTitle">Event JSON</h3>
      <button class="close-btn" type="button" title="Close" data-close-json-modal>&times;</button>
    </div>
    <div class="json-modal-body">
      <pre class="json-pre"><code id="jsonCode"></code></pre>
    </div>
  </div>
</div>

<script>
(function() {
  const modal     = document.getElementById('jsonModal');
  const codeEl    = document.getElementById('jsonCode');

  function openModal() {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (codeEl) codeEl.textContent = '';
  }

  // Close on backdrop / button
  modal?.addEventListener('click', (e) => {
    if (e.target.matches('[data-close-json-modal], .photo-modal-backdrop')) {
      closeModal();
    }
  });

  // Close on Esc
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
      closeModal();
    }
  });

  // Helper: base64 -> UTF-8 string
  function b64ToUtf8(b64) {
    try {
      const bin = atob(b64);
      const bytes = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      return new TextDecoder('utf-8').decode(bytes);
    } catch (_) {
      try { return atob(b64); } catch { return ''; }
    }
  }

  // Delegate click for "View JSON" buttons
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.js-json-view');
    if (!btn) return;

    const b64 = btn.getAttribute('data-meta-b64') || '';
    const raw = b64ToUtf8(b64);
    if (!raw) {
      alert('Could not load JSON payload.');
      return;
    }

    let pretty = raw;
    try {
      const obj = JSON.parse(raw);
      pretty = JSON.stringify(obj, null, 2);
    } catch (_) {
      // not valid JSON; show as-is
    }

    if (codeEl) codeEl.textContent = pretty;
    openModal();
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
