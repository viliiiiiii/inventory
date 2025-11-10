<?php
declare(strict_types=1);

/**
 * Notes diagnostics (no Node; plain PHP).
 * - Verifies DB/schema for notes, note_photos, notes_shares
 * - Detects which share column exists: user_id or shared_with
 * - Reproduces index.php visibility logic
 * - Offers a Share Emulator to insert/delete share rows
 * - Surfaces real SQL errors via raw probes
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/lib.php';
require_login();

/* ---------- tiny helpers ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hv($arr, $k, $def='') { return isset($arr[$k]) ? (string)$arr[$k] : $def; }

/* ---------- env ---------- */
$pdo = get_pdo();
$core = null;
try { $core = get_pdo('core'); } catch (Throwable $e) { /* optional */ }

/* ---------- robust schema detection ---------- */
function diag_current_db(PDO $pdo): string {
    try { return (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); }
    catch (Throwable $e) { return ''; }
}
function diag_is_table(PDO $pdo, string $db, string $table): bool {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:t');
        $st->execute([':db'=>$db, ':t'=>$table]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function diag_is_column(PDO $pdo, string $db, string $table, string $col): bool {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:t AND column_name=:c');
        $st->execute([':db'=>$db, ':t'=>$table, ':c'=>$col]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function diag_list_note_tables(PDO $pdo, string $db): array {
    try {
        $st = $pdo->prepare(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema=:db
               AND (table_name LIKE 'note\\_%' ESCAPE '\\\\' OR table_name LIKE 'notes%')
             ORDER BY table_name"
        );
        $st->execute([':db'=>$db]);
        return array_map(fn($r)=>$r['table_name'], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}
function diag_probe(PDO $pdo, string $sql): array {
    try {
        $st = $pdo->query($sql);
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        return ['ok'=>true, 'row'=>$row];
    } catch (Throwable $e) { return ['ok'=>false, 'error'=>$e->getMessage()]; }
}

/* ---------- run detection ---------- */
$db = diag_current_db($pdo);
$tables = diag_list_note_tables($pdo, $db);

$hasNotes        = diag_is_table($pdo, $db, 'notes');
$hasPhotos       = diag_is_table($pdo, $db, 'note_photos');
$hasShares       = diag_is_table($pdo, $db, 'notes_shares');
$hasNoteDate     = $hasNotes  ? diag_is_column($pdo, $db, 'notes', 'note_date')   : false;
$hasCreatedAt    = $hasNotes  ? diag_is_column($pdo, $db, 'notes', 'created_at')  : false;
$sharesUserId    = $hasShares ? diag_is_column($pdo, $db, 'notes_shares', 'user_id') : false;
$sharesSharedWith= $hasShares ? diag_is_column($pdo, $db, 'notes_shares', 'shared_with') : false;
$shareCol        = $sharesUserId ? 'user_id' : ($sharesSharedWith ? 'shared_with' : null);

/* live probes */
$probe_notes        = $hasNotes  ? diag_probe($pdo, 'SELECT id,user_id,title FROM notes ORDER BY id DESC LIMIT 1') : ['ok'=>false,'error'=>'table not found'];
$probe_note_photos  = $hasPhotos ? diag_probe($pdo, 'SELECT id,note_id,position FROM note_photos ORDER BY id DESC LIMIT 1') : ['ok'=>false,'error'=>'table not found'];
$probe_notes_shares = $hasShares ? diag_probe($pdo, 'SELECT id,note_id,'.($shareCol ?: 'user_id').' FROM notes_shares ORDER BY id DESC LIMIT 1') : ['ok'=>false,'error'=>'table not found'];

/* ---------- emulate share insert/remove ---------- */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? '')) {
    $action = hv($_POST, 'do', '');
    try {
        if ($action === 'share_insert') {
            if (!$hasShares || !$shareCol) throw new RuntimeException('shares table/column not detected');
            $noteId = (int)($_POST['note_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($noteId <= 0 || $userId <= 0) throw new RuntimeException('note_id and user_id are required');
            $sql = "INSERT INTO notes_shares (note_id, {$shareCol}) VALUES (:n, :u)
                    ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP";
            $st = $pdo->prepare($sql);
            $st->execute([':n'=>$noteId, ':u'=>$userId]);
            $flash = "Share row upserted: note #{$noteId} → {$shareCol}={$userId}.";
        } elseif ($action === 'share_delete') {
            if (!$hasShares || !$shareCol) throw new RuntimeException('shares table/column not detected');
            $noteId = (int)($_POST['note_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($noteId <= 0 || $userId <= 0) throw new RuntimeException('note_id and user_id are required');
            $sql = "DELETE FROM notes_shares WHERE note_id=:n AND {$shareCol}=:u";
            $st = $pdo->prepare($sql);
            $st->execute([':n'=>$noteId, ':u'=>$userId]);
            $flash = "Share row deleted: note #{$noteId} → {$shareCol}={$userId}.";
        }
    } catch (Throwable $e) {
        $flash = 'ERROR: '.$e->getMessage();
    }
}

/* ---------- inputs for visibility test ---------- */
$simUserId = isset($_GET['user']) ? (int)$_GET['user'] : (int)(current_user()['id'] ?? 0);
$simNoteId = isset($_GET['note']) ? (int)$_GET['note'] : 0;
$txt = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

/* ---------- reproduce index.php visibility (non-admin semantics) ---------- */
$visErr  = null;
$visRows = [];

if ($hasNotes) {
    $params = [];
    $where  = [];

    // Text/date filters
    if ($txt !== '') {
        $where[]        = '(n.title LIKE :q OR COALESCE(n.body, "") LIKE :q)';
        $params[':q']   = '%'.$txt.'%';
    }
    if ($hasNoteDate && $from !== '') { $where[] = 'n.note_date >= :from'; $params[':from'] = $from; }
    if ($hasNoteDate && $to   !== '') { $where[] = 'n.note_date <= :to';   $params[':to']   = $to;   }

    // Optional: focus on a single note id
    if ($simNoteId > 0) {
        $where[]           = 'n.id = :nid';
        $params[':nid']    = $simNoteId;
    }

    // Visibility (non-admin semantics)
    if ($shareCol) {
        // We will use :me1 and :me2 in SQL, so bind both
        $where[] = "(n.user_id = :me1
                    OR EXISTS(SELECT 1
                              FROM notes_shares s
                              WHERE s.note_id = n.id
                                AND s.{$shareCol} = :me2))";
        $params[':me1'] = $simUserId;
        $params[':me2'] = $simUserId;

        $isSharedExpr = "EXISTS(SELECT 1 FROM notes_shares s WHERE s.note_id = n.id AND s.{$shareCol} = :me2) AS is_shared";
    } else {
        // No sharing table/column -> only own notes, only :me1 is used/bound
        $where[] = "n.user_id = :me1";
        $params[':me1'] = $simUserId;

        $isSharedExpr = "0 AS is_shared";
    }

    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    $orderParts = [];
    if ($hasNoteDate)  $orderParts[] = "n.note_date DESC";
    if ($hasCreatedAt) $orderParts[] = "n.created_at DESC";
    $orderParts[] = "n.id DESC";
    $orderSql = " ORDER BY ".implode(', ', $orderParts)." LIMIT 100";

    $sql = "SELECT
                n.*,
                (n.user_id = :me1) AS is_owner,
                {$isSharedExpr}
            FROM notes n
            {$whereSql}
            {$orderSql}";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $visRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $visErr = $e->getMessage();
    }
}

/* ---------- helper: user list for selects ---------- */
function diag_user_options(?PDO $core, PDO $app, int $limit=200): array {
    // prefer core users
    if ($core) {
        try {
            $rows = $core->query("SELECT id, email FROM users ORDER BY email LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) return $rows;
        } catch (Throwable $e) {}
    }
    // fallback: app users (if present)
    try {
        $rows = $app->query("SELECT id, email FROM users ORDER BY email LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) { return []; }
}

/* ---------- fetch note details (for Share Emulator sidebar) ---------- */
$noteDetails = null;
if ($hasNotes && $simNoteId > 0) {
    try {
        $st = $pdo->prepare('SELECT * FROM notes WHERE id = :id LIMIT 1');
        $st->execute([':id'=>$simNoteId]);
        $noteDetails = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $noteDetails = null; }
}
$sharesForNote = [];
if ($hasShares && $simNoteId > 0 && $shareCol) {
    try {
        $st = $pdo->prepare("SELECT id, note_id, {$shareCol} AS user_id, created_at FROM notes_shares WHERE note_id=:n ORDER BY created_at DESC");
        $st->execute([':n'=>$simNoteId]);
        $sharesForNote = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $sharesForNote = []; }
}

/* ---------- page ---------- */
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Notes Diagnostics</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Helvetica,Arial,sans-serif;line-height:1.4;margin:16px;}
    h1,h2,h3{margin:.2rem 0 .5rem}
    .grid{display:grid;gap:1rem;grid-template-columns:2fr 1fr}
    table{width:100%;border-collapse:collapse;margin:.5rem 0}
    th,td{border:1px solid #ddd;padding:.4rem .5rem;font-size:.95rem}
    th{background:#f5f5f5;text-align:left}
    .flash{padding:.5rem .6rem;border-radius:6px;margin:.5rem 0}
    .flash-error{background:#ffe7e7;border:1px solid #f7b3b3}
    .flash-ok{background:#e7ffe7;border:1px solid #b3f7b3}
    .muted{color:#666}
    .badge{background:#eef;border:1px solid #ccd;padding:.1rem .35rem;border-radius:6px;font-size:.75rem;margin-left:.25rem}
    label{display:block;margin:.35rem 0}
    input[type=text],input[type=number],input[type=date],select,textarea{width:100%;padding:.4rem;border:1px solid #bbb;border-radius:6px}
    button{padding:.45rem .7rem;border:1px solid #888;border-radius:6px;background:#fafafa;cursor:pointer}
    code{background:#f6f6f6;padding:.1rem .25rem;border-radius:4px}
    .card{border:1px solid #ddd;border-radius:10px;padding:12px;margin:10px 0}
  </style>
</head>
<body>
  <h1>Notes Diagnostics</h1>

  <section class="card">
    <h2>Environment</h2>
    <table>
      <tbody>
        <tr><th>PHP</th><td><?= h(PHP_VERSION) ?></td></tr>
        <tr><th>Loaded php.ini</th><td><?= h(php_ini_loaded_file() ?: '(unknown)') ?></td></tr>
        <tr><th>Current DB</th><td><?= h($db ?: '(unknown)') ?></td></tr>
        <tr><th>Note tables present</th><td><?= h(implode(', ', $tables) ?: '(none)') ?></td></tr>
      </tbody>
    </table>
  </section>

  <section class="card">
    <h2>Schema checks (information_schema)</h2>
    <table>
      <tbody>
        <tr><th>notes</th><td><?= $hasNotes ? 'YES' : 'NO' ?></td></tr>
        <tr><th>note_photos</th><td><?= $hasPhotos ? 'YES' : 'NO' ?></td></tr>
        <tr><th>notes_shares</th><td><?= $hasShares ? 'YES' : 'NO' ?></td></tr>
        <tr><th>notes.note_date</th><td><?= $hasNoteDate ? 'YES' : 'NO' ?></td></tr>
        <tr><th>notes.created_at</th><td><?= $hasCreatedAt ? 'YES' : 'NO' ?></td></tr>
        <tr><th>notes_shares.user_id</th><td><?= $sharesUserId ? 'YES' : 'NO' ?></td></tr>
        <tr><th>notes_shares.shared_with</th><td><?= $sharesSharedWith ? 'YES' : 'NO' ?></td></tr>
        <tr><th>Sharing column detected</th><td><b><?= h($shareCol ?: '(none)') ?></b></td></tr>
      </tbody>
    </table>

    <h3>Raw DB Probes</h3>
    <ul>
      <li><code>SELECT id,user_id,title FROM notes …</code>:
        <?php if ($probe_notes['ok']): ?>
          OK <?= $probe_notes['row'] ? '(row exists)' : '(empty)' ?>
        <?php else: ?>
          <span class="flash flash-error"><?= h($probe_notes['error']) ?></span>
        <?php endif; ?>
      </li>
      <li><code>SELECT id,note_id,position FROM note_photos …</code>:
        <?php if ($probe_note_photos['ok']): ?>
          OK <?= $probe_note_photos['row'] ? '(row exists)' : '(empty)' ?>
        <?php else: ?>
          <span class="flash flash-error"><?= h($probe_note_photos['error']) ?></span>
        <?php endif; ?>
      </li>
      <li><code>SELECT id,note_id,<?= h($shareCol ?: 'user_id') ?> FROM notes_shares …</code>:
        <?php if ($probe_notes_shares['ok']): ?>
          OK <?= $probe_notes_shares['row'] ? '(row exists)' : '(empty)' ?>
        <?php else: ?>
          <span class="flash flash-error"><?= h($probe_notes_shares['error']) ?></span>
        <?php endif; ?>
      </li>
    </ul>
  </section>

  <?php if ($flash): ?>
    <div class="flash <?= str_starts_with($flash, 'ERROR:') ? 'flash-error' : 'flash-ok' ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="grid">
    <section class="card">
      <h2>Simulate visibility (same logic as index.php)</h2>
      <?php $userOpts = diag_user_options($core, $pdo); ?>
      <form method="get">
        <label>User
          <select name="user">
            <?php foreach ($userOpts as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $simUserId ? 'selected' : '' ?>>
                <?= h($u['email'] ?? ('#'.$u['id'])) ?> (ID <?= (int)$u['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Note (optional)
          <input type="number" name="note" value="<?= $simNoteId ?: '' ?>" placeholder="e.g. 3">
        </label>
        <label>Text filter
          <input type="text" name="q" value="<?= h($txt) ?>" placeholder="title/body contains…">
        </label>
        <div style="display:flex;gap:.5rem">
          <label style="flex:1">From <input type="date" name="from" value="<?= h($from) ?>" <?= $hasNoteDate ? '' : 'disabled' ?>></label>
          <label style="flex:1">To <input type="date" name="to" value="<?= h($to) ?>" <?= $hasNoteDate ? '' : 'disabled' ?>></label>
        </div>
        <button type="submit">Run</button>
      </form>

      <?php if (!$hasNotes): ?>
        <p class="flash flash-error">Table <code>notes</code> is missing.</p>
      <?php elseif ($visErr): ?>
        <p class="flash flash-error"><?= h($visErr) ?></p>
      <?php else: ?>
        <?php if (!$visRows): ?>
          <p class="muted">No visible notes for this user with current filters.</p>
        <?php else: ?>
          <table>
            <thead><tr><th>ID</th><th>Owner</th><th>Title</th><th>is_owner</th><th>is_shared</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($visRows as $r): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= (int)$r['user_id'] ?></td>
                  <td><?= h($r['title']) ?></td>
                  <td><?= !empty($r['is_owner']) ? '1' : '0' ?></td>
                  <td><?= !empty($r['is_shared']) ? '1' : '0' ?></td>
                  <td><?= h($r['note_date'] ?? substr((string)($r['created_at'] ?? ''),0,10)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Share Emulator</h2>

      <form method="post" class="card" style="margin:0 0 .5rem">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= csrf_token() ?>">
        <input type="hidden" name="do" value="share_insert">
        <label>note_id
          <input type="number" name="note_id" value="<?= $simNoteId ?: '' ?>" placeholder="e.g. 3" required>
        </label>
        <label>user_id (the person who should see it)
          <select name="user_id" required>
            <option value="">Select…</option>
            <?php foreach ($userOpts as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= h($u['email'] ?? ('#'.$u['id'])) ?> (ID <?= (int)$u['id'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit">Insert/Upsert share</button>
        <?php if (!$hasShares): ?><div class="muted">notes_shares table not found.</div><?php endif; ?>
        <?php if ($hasShares && !$shareCol): ?><div class="muted">No share column detected; create <code>user_id</code> on <code>notes_shares</code>.</div><?php endif; ?>
      </form>

      <form method="post" class="card" style="margin:0">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= csrf_token() ?>">
        <input type="hidden" name="do" value="share_delete">
        <label>note_id
          <input type="number" name="note_id" value="<?= $simNoteId ?: '' ?>" placeholder="e.g. 3" required>
        </label>
        <label>user_id
          <select name="user_id" required>
            <option value="">Select…</option>
            <?php foreach ($userOpts as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= h($u['email'] ?? ('#'.$u['id'])) ?> (ID <?= (int)$u['id'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit">Delete share</button>
      </form>

      <?php if ($noteDetails): ?>
        <h3 style="margin-top:1rem">Note details</h3>
        <table>
          <tbody>
            <tr><th>id</th><td><?= (int)$noteDetails['id'] ?></td></tr>
            <tr><th>user_id (owner)</th><td><?= (int)$noteDetails['user_id'] ?></td></tr>
            <tr><th>title</th><td><?= h($noteDetails['title']) ?></td></tr>
            <tr><th>note_date</th><td><?= h($noteDetails['note_date'] ?? '') ?></td></tr>
            <tr><th>created_at</th><td><?= h($noteDetails['created_at'] ?? '') ?></td></tr>
          </tbody>
        </table>
        <h3>Shares for note #<?= (int)$noteDetails['id'] ?></h3>
        <?php if (!$sharesForNote): ?>
          <p class="muted">This note is not shared with anyone (or shares table empty).</p>
        <?php else: ?>
          <table>
            <thead><tr><th>id</th><th>note_id</th><th><?= h($shareCol ?: 'user_id') ?></th><th>created_at</th></tr></thead>
            <tbody>
              <?php foreach ($sharesForNote as $s): ?>
                <tr>
                  <td><?= (int)$s['id'] ?></td>
                  <td><?= (int)$s['note_id'] ?></td>
                  <td><?= (int)$s['user_id'] ?></td>
                  <td><?= h($s['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>

  <section class="card">
    <h2>What to check</h2>
    <ul>
      <li>If “Sharing column detected” is blank, add a <code>user_id INT UNSIGNED NOT NULL</code> column to <code>notes_shares</code> and a unique key <code>(note_id, user_id)</code>.</li>
      <li>The Share Emulator uses the detected column. If it errors, the raw error will be shown above.</li>
      <li>Make sure the number in “user_id” is the **numeric user ID** from your users table (not an email).</li>
      <li>Use the “Simulate visibility” section with that user to confirm the note appears with <code>is_shared = 1</code>.</li>
    </ul>
  </section>
</body>
</html>
