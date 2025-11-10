<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$meId = (int)(current_user()['id'] ?? 0);
$pdo  = get_pdo();

/* ---------- Local fallbacks if helpers aren't defined ---------- */
if (!function_exists('notes__table_exists')) {
  function notes__table_exists(PDO $pdo, string $tbl): bool {
    try {
      $st = $pdo->prepare("SHOW TABLES LIKE ?");
      $st->execute([$tbl]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('notes__col_exists')) {
  function notes__col_exists(PDO $pdo, string $tbl, string $col): bool {
    try {
      $st = $pdo->prepare("SHOW COLUMNS FROM `{$tbl}` LIKE ?");
      $st->execute([$col]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('notes__search_index')) {
  function notes__search_index(string $title, string $body = ''): string {
    $plain = trim($title . ' ' . preg_replace('/\s+/u', ' ', strip_tags($body)));
    if ($plain === '') {
      return '';
    }
    if (function_exists('mb_strtolower')) {
      return mb_strtolower($plain, 'UTF-8');
    }
    return strtolower($plain);
  }
}
if (!function_exists('notes__relative_time')) {
  function notes__relative_time(?string $timestamp): string {
    if (!$timestamp) {
      return '';
    }
    try {
      $dt = new DateTimeImmutable($timestamp);
    } catch (Throwable $e) {
      return (string)$timestamp;
    }
    $now  = new DateTimeImmutable('now');
    $diff = $now->getTimestamp() - $dt->getTimestamp();
    if ($diff < 0) {
      $diff = 0;
    }
    if ($diff < 60) {
      return $diff . 's ago';
    }
    $mins = (int)floor($diff / 60);
    if ($mins < 60) {
      return $mins . 'm ago';
    }
    $hours = (int)floor($mins / 60);
    if ($hours < 24) {
      return $hours . 'h ago';
    }
    $days = (int)floor($hours / 24);
    if ($days < 7) {
      return $days . 'd ago';
    }
    if ($days < 30) {
      return (int)floor($days / 7) . 'w ago';
    }
    return $dt->format('M j, Y');
  }
}
if (!function_exists('notes__excerpt')) {
  function notes__excerpt(string $body, int $limit = 180): string {
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
    if ($plain === '') {
      return '';
    }
    if (function_exists('mb_strimwidth')) {
      return (string)mb_strimwidth($plain, 0, $limit, '…', 'UTF-8');
    }
    return strlen($plain) > $limit ? substr($plain, 0, $limit - 1) . '…' : $plain;
  }
}

/* ---------- Detect optional schema features ---------- */
$hasSharesTbl  = notes__table_exists($pdo, 'notes_shares');
$sharesHasUser = $hasSharesTbl && notes__col_exists($pdo, 'notes_shares', 'user_id');
$sharesHasOld  = $hasSharesTbl && notes__col_exists($pdo, 'notes_shares', 'shared_with');
$sharesCol     = $sharesHasUser ? 'user_id' : ($sharesHasOld ? 'shared_with' : null);

$hasNoteDate   = notes__col_exists($pdo, 'notes', 'note_date');
$hasCreatedAt  = notes__col_exists($pdo, 'notes', 'created_at');
$hasPhotosTbl  = notes__table_exists($pdo, 'note_photos');
$hasCommentsTbl= notes__table_exists($pdo, 'note_comments');

/* ---------- Filters ---------- */
$search = trim((string)($_GET['q'] ?? ''));
$from   = trim((string)($_GET['from'] ?? ''));
$to     = trim((string)($_GET['to'] ?? ''));

/* ---------- View preference: GET → cookie → default('table') ---------- */
$allowedViews = ['table', 'sticky'];
if (isset($_GET['view']) && in_array($_GET['view'], $allowedViews, true)) {
  $view = $_GET['view'];
} elseif (isset($_COOKIE['notes_view']) && in_array($_COOKIE['notes_view'], $allowedViews, true)) {
  $view = $_COOKIE['notes_view'];
} else {
  $view = 'table';
}
/* Persist cookie (1 year) so server renders preferred view on first paint */
@setcookie('notes_view', $view, time() + 31536000, '/', '', false, true);

$where  = [];
$params = [];

/* Text filter */
if ($search !== '') {
  $where[]        = '(n.title LIKE :q OR COALESCE(n.body,"") LIKE :q)';
  $params[':q']   = '%' . $search . '%';
}
/* Date filters */
if ($hasNoteDate && $from !== '') { $where[] = 'n.note_date >= :from'; $params[':from'] = $from; }
if ($hasNoteDate && $to   !== '') { $where[] = 'n.note_date <= :to';   $params[':to']   = $to;   }

/* ---------- Visibility (non-admin semantics) ---------- */
/* Only owner or explicitly shared-with-me. If shares table/column missing, show own notes only. */
if ($sharesCol) {
  $where[] = "(n.user_id = :me_owner_where
              OR EXISTS (SELECT 1 FROM notes_shares s
                         WHERE s.note_id = n.id
                           AND s.{$sharesCol} = :me_share_where))";

  // WHERE placeholders
  $params[':me_owner_where'] = $meId;
  $params[':me_share_where'] = $meId;

  // SELECT placeholder (some drivers require distinct names)
  $isSharedExpr = "EXISTS(SELECT 1 FROM notes_shares s
                          WHERE s.note_id = n.id
                            AND s.{$sharesCol} = :me_share_select) AS is_shared";
  $params[':me_share_select'] = $meId;

} else {
  $where[] = "n.user_id = :me_owner_where";
  $params[':me_owner_where'] = $meId;
  $isSharedExpr = "0 AS is_shared";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---------- Other selectable columns ---------- */
$photoCountExpr = $hasPhotosTbl
  ? "(SELECT COUNT(*) FROM note_photos p WHERE p.note_id = n.id) AS photo_count"
  : "0 AS photo_count";
$commentCountExpr = $hasCommentsTbl
  ? "(SELECT COUNT(*) FROM note_comments c WHERE c.note_id = n.id) AS comment_count"
  : "0 AS comment_count";

/* ---------- Ordering ---------- */
$orderParts = [];
if ($hasNoteDate)  { $orderParts[] = "n.note_date DESC"; }
if ($hasCreatedAt) { $orderParts[] = "n.created_at DESC"; }
$orderParts[] = "n.id DESC";
$orderSql = " ORDER BY " . implode(', ', $orderParts) . " LIMIT 200";

/* ---------- Final SQL ---------- */
$sql = "SELECT
          n.*,
          (n.user_id = :me_owner_select) AS is_owner,
          {$isSharedExpr},
          {$photoCountExpr},
          {$commentCountExpr}
        FROM notes n
        {$whereSql}
        {$orderSql}";
$params[':me_owner_select'] = $meId;

$rows = [];
try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
} catch (Throwable $e) {
  error_log("Notes index query failed: " . $e->getMessage());
  $rows = [];
}

/* ---------- Dashboard insights ---------- */
$totalNotes         = count($rows);
$ownedCount         = 0;
$sharedCount        = 0;
$photoRichCount     = 0;
$commentRichCount   = 0;
$photoTotal         = 0;
$latestTimestamp    = null;

foreach ($rows as $row) {
  $isOwner  = !empty($row['is_owner']);
  $isShared = !empty($row['is_shared']) && !$isOwner;
  $pc       = (int)($row['photo_count'] ?? 0);
  $cc       = (int)($row['comment_count'] ?? 0);

  if ($isOwner) { $ownedCount++; }
  if ($isShared) { $sharedCount++; }
  if ($pc > 0) { $photoRichCount++; }
  if ($cc > 0) { $commentRichCount++; }

  $photoTotal += $pc;

  $candidateTimestamp = $row['created_at'] ?? $row['updated_at'] ?? $row['note_date'] ?? null;
  if ($candidateTimestamp === null) {
    continue;
  }
  if ($latestTimestamp === null || strcmp((string)$candidateTimestamp, (string)$latestTimestamp) > 0) {
    $latestTimestamp = (string)$candidateTimestamp;
  }
}

$avgPhotos           = $totalNotes > 0 ? $photoTotal / $totalNotes : 0.0;
$avgPhotosRounded    = $avgPhotos > 0 ? round($avgPhotos, 1) : 0.0;
$percentOwned        = $totalNotes > 0 ? round(($ownedCount / $totalNotes) * 100) : 0;
$percentShared       = $totalNotes > 0 ? round(($sharedCount / $totalNotes) * 100) : 0;
$recentNotes         = $rows;
$lastUpdatedRelative = $latestTimestamp ? notes__relative_time($latestTimestamp) : '';
$lastUpdatedAbsolute = '';
if ($latestTimestamp) {
  try {
    $lastUpdatedAbsolute = (new DateTimeImmutable($latestTimestamp))->format('Y-m-d H:i');
  } catch (Throwable $e) {
    $lastUpdatedAbsolute = (string)$latestTimestamp;
  }
}

if ($recentNotes) {
  usort($recentNotes, static function (array $a, array $b): int {
    $aTime = $a['created_at'] ?? $a['updated_at'] ?? $a['note_date'] ?? '';
    $bTime = $b['created_at'] ?? $b['updated_at'] ?? $b['note_date'] ?? '';
    if ($aTime === $bTime) {
      return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    }
    return strcmp((string)$bTime, (string)$aTime);
  });
  $recentNotes = array_slice($recentNotes, 0, 4);
}

$heroSubtitle = $totalNotes
  ? sprintf(
      'You have %s notes in this space — %s%% owned by you and %s%% shared with teammates.',
      number_format($totalNotes),
      number_format($percentOwned),
      number_format($percentShared)
    )
  : 'Create your first note to capture site observations, assignments, and punch list updates.';
$lastUpdateHint = $lastUpdatedRelative !== ''
  ? 'Last update ' . $lastUpdatedRelative
  : 'Ready when you are';
$ownedHint = $totalNotes
  ? sprintf('%s%% of workspace notes', number_format($percentOwned))
  : 'Start capturing your work';
$sharedHint = $sharedCount
  ? sprintf('%s collaborative notes', number_format($sharedCount))
  : 'Share notes to collaborate';
$mediaHint = $photoRichCount
  ? sprintf('Avg %s photos attached', number_format($avgPhotosRounded, 1))
  : 'Drop in site photos';
$discussionHint = $commentRichCount
  ? sprintf('%s notes with replies', number_format($commentRichCount))
  : 'Invite teammates to comment';

/* ---------- Helper: build toggle URL preserving filters ---------- */
function toggle_view_url(string $targetView): string {
  $q = $_GET;
  $q['view'] = $targetView;
  return 'index.php?' . http_build_query($q);
}

$title = 'Notes';
include __DIR__ . '/../includes/header.php';
?>

<section class="card card--surface notes-controls">
  <form method="get" class="notes-filter" action="index.php" autocomplete="off">
    <input type="hidden" name="view" value="<?= sanitize($view); ?>">
    <div class="notes-filter__grid">
      <label class="notes-field">
        <span class="notes-field__label">Search</span>
        <input type="search" name="q" value="<?= sanitize($search); ?>" placeholder="Title or text" data-live-search>
      </label>
      <label class="notes-field">
        <span class="notes-field__label">From</span>
        <input type="date" name="from" value="<?= sanitize($from); ?>" <?= $hasNoteDate ? '' : 'disabled'; ?>>
      </label>
      <label class="notes-field">
        <span class="notes-field__label">To</span>
        <input type="date" name="to" value="<?= sanitize($to); ?>" <?= $hasNoteDate ? '' : 'disabled'; ?>>
      </label>
    </div>
    <div class="notes-filter__actions">
      <button class="btn" type="submit">Apply filters</button>
      <a class="btn secondary" href="index.php?view=<?= $view === 'sticky' ? 'sticky' : 'table'; ?>">Clear</a>
      <button class="btn ghost" type="button" data-reset-filters>Reset quick filters</button>
    </div>
  </form>
  <div class="notes-toolbar">
    <div class="notes-quick-filters" role="group" aria-label="Quick filters">
      <button type="button" class="chip is-active" data-filter-button="all" aria-pressed="true">All notes</button>
      <button type="button" class="chip" data-filter-button="mine" aria-pressed="false">Mine</button>
      <button type="button" class="chip" data-filter-button="shared" aria-pressed="false">Shared</button>
      <button type="button" class="chip" data-filter-button="photos" aria-pressed="false">With photos</button>
      <button type="button" class="chip" data-filter-button="replies" aria-pressed="false">With replies</button>
    </div>
    <div class="notes-view-toggle" role="group" aria-label="View mode">
      <a class="notes-view-toggle__link<?= $view === 'sticky' ? '' : ' is-active'; ?> js-toggle-view" href="<?= toggle_view_url('table'); ?>">List view</a>
      <a class="notes-view-toggle__link<?= $view === 'sticky' ? ' is-active' : ''; ?> js-toggle-view" href="<?= toggle_view_url('sticky'); ?>">Board view</a>
    </div>
  </div>
</section>

<section class="card card--surface notes-content">
  <?php if (!$rows): ?>
    <div class="notes-empty notes-empty--initial">
      <div class="notes-empty__icon" aria-hidden="true">📝</div>
      <h2>No notes yet</h2>
      <p class="muted">Draft your first note to document findings, attach site photos, and assign follow-up work.</p>
      <a class="btn primary" href="new.php">Create a note</a>
    </div>
  <?php else: ?>
    <div class="notes-empty" data-empty aria-hidden="true">
      <div class="notes-empty__icon" aria-hidden="true">🔍</div>
      <h3>No notes match your filters</h3>
      <p class="muted">Try clearing the search or switching to a different quick filter.</p>
      <button class="btn secondary" type="button" data-reset-filters>Reset filters</button>
    </div>

    <?php if ($view === 'sticky'): ?>
      <div class="sticky-grid notes-collection" data-note-collection>
        <?php foreach ($rows as $n):
          $id        = (int)($n['id'] ?? 0);
          $date      = $n['note_date'] ?? ($n['created_at'] ?? '');
          $titleV    = (string)($n['title'] ?? 'Untitled');
          $body      = (string)($n['body'] ?? '');
          $pc        = (int)($n['photo_count'] ?? 0);
          $cc        = (int)($n['comment_count'] ?? 0);
          $isOwner   = !empty($n['is_owner']);
          $isShared  = !empty($n['is_shared']) && !$isOwner;
          $colorClass= 'c' . (($id % 6) + 1);
          $tiltDeg   = (($id % 5) - 2) * 1.2;
          $searchIdx = notes__search_index($titleV, $body);
          $excerpt   = notes__excerpt($body, 220);
        ?>
        <article class="postit <?= $colorClass; ?>" style="--tilt: <?= htmlspecialchars((string)$tiltDeg, ENT_QUOTES, 'UTF-8'); ?>deg;"
                 data-note
                 data-owned="<?= $isOwner ? '1' : '0'; ?>"
                 data-shared="<?= $isShared ? '1' : '0'; ?>"
                 data-photos="<?= $pc; ?>"
                 data-comments="<?= $cc; ?>"
                 data-search="<?= sanitize($searchIdx); ?>">
          <div class="tape" aria-hidden="true"></div>

          <header class="postit-head">
            <span class="postit-date"><?= sanitize((string)$date); ?></span>
            <div class="postit-tags">
              <?php if ($isOwner): ?><span class="badge">Mine</span><?php endif; ?>
              <?php if ($isShared): ?><span class="badge">Shared</span><?php endif; ?>
            </div>
          </header>

          <h3 class="postit-title">
            <a href="view.php?id=<?= $id; ?>"><?= sanitize($titleV); ?></a>
          </h3>

          <?php if ($body !== ''): ?>
            <p class="postit-body"><?= nl2br(sanitize($excerpt)); ?></p>
          <?php else: ?>
            <p class="postit-body muted">No text.</p>
          <?php endif; ?>

          <footer class="postit-meta">
            <span class="meta-pill" title="Photos">📷 <?= $pc; ?></span>
            <span class="meta-pill" title="Replies">💬 <?= $cc; ?></span>
            <?php if (notes_can_edit($n)): ?>
              <a class="btn tiny" href="edit.php?id=<?= $id; ?>">Edit</a>
            <?php endif; ?>
          </footer>
        </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="notes-table-wrapper">
        <table class="table notes-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Title</th>
              <th>Photos</th>
              <th>Replies</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody data-note-collection>
          <?php foreach ($rows as $n):
            $noteId     = (int)($n['id'] ?? 0);
            $noteTitle  = (string)($n['title'] ?? 'Untitled');
            $noteBody   = (string)($n['body'] ?? '');
            $pc         = (int)($n['photo_count'] ?? 0);
            $cc         = (int)($n['comment_count'] ?? 0);
            $isOwner    = !empty($n['is_owner']);
            $isShared   = !empty($n['is_shared']) && !$isOwner;
            $noteDate   = $n['note_date'] ?? ($n['created_at'] ?? '');
            $noteRelative = notes__relative_time($n['created_at'] ?? $n['updated_at'] ?? $n['note_date'] ?? null);
            $excerpt    = notes__excerpt($noteBody, 140);
            $searchIdx  = notes__search_index($noteTitle, $noteBody);
          ?>
            <tr class="notes-row<?= $isShared ? ' is-shared' : ''; ?>"
                data-note
                data-owned="<?= $isOwner ? '1' : '0'; ?>"
                data-shared="<?= $isShared ? '1' : '0'; ?>"
                data-photos="<?= $pc; ?>"
                data-comments="<?= $cc; ?>"
                data-search="<?= sanitize($searchIdx); ?>">
              <td data-label="Date">
                <?= sanitize((string)$noteDate); ?>
                <?php if ($noteRelative): ?><div class="notes-row__meta muted"><?= sanitize($noteRelative); ?></div><?php endif; ?>
              </td>
              <td data-label="Title">
                <div class="notes-row__title">
                  <a href="view.php?id=<?= $noteId; ?>"><?= sanitize($noteTitle); ?></a>
                  <div class="notes-row__badges">
                    <?php if ($isOwner): ?><span class="badge">Mine</span><?php endif; ?>
                    <?php if ($isShared): ?><span class="badge">Shared</span><?php endif; ?>
                  </div>
                </div>
                <?php if ($excerpt !== ''): ?>
                  <div class="notes-snippet"><?= sanitize($excerpt); ?></div>
                <?php endif; ?>
              </td>
              <td data-label="Photos">
                <span class="notes-count-chip<?= $pc ? ' has-value' : ''; ?>">📷 <?= $pc; ?></span>
              </td>
              <td data-label="Replies">
                <span class="notes-count-chip<?= $cc ? ' has-value' : ''; ?>">💬 <?= $cc; ?></span>
              </td>
              <td class="text-right">
                <div class="notes-row__actions">
                  <a class="btn small" href="view.php?id=<?= $noteId; ?>">View</a>
                  <?php if (notes_can_edit($n)): ?>
                    <a class="btn small" href="edit.php?id=<?= $noteId; ?>">Edit</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<style>
.notes-hero{
  position:relative;
  padding:2.5rem;
  overflow:hidden;
  background:linear-gradient(135deg,#eef2ff,#e0f2fe);
}
.notes-hero::before{
  content:"";
  position:absolute;
  inset:0;
  background:radial-gradient(circle at top right, rgba(14,116,144,.25), transparent 55%),
             radial-gradient(circle at bottom left, rgba(79,70,229,.2), transparent 50%);
  z-index:0;
}
.notes-hero>*{ position:relative; z-index:1; }
.notes-hero__headline{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:2rem;
  flex-wrap:wrap;
}
.notes-hero__eyebrow{
  font-size:.75rem;
  text-transform:uppercase;
  letter-spacing:.15em;
  color:#4338ca;
  margin:0 0 .5rem;
}
.notes-hero__title{ margin:0 0 1rem; font-size:2rem; color:#0f172a; }
.notes-hero__subtitle{ margin:0 0 1.5rem; max-width:38rem; color:#1e293b; }
.notes-hero__actions{ display:flex; gap:.75rem; flex-wrap:wrap; }
.notes-hero__meta{ display:flex; align-items:flex-end; }
.notes-hero__stamp{
  background:rgba(255,255,255,.7);
  padding:1rem 1.5rem;
  border-radius:1rem;
  box-shadow:0 20px 45px rgba(15,23,42,.08);
  display:flex;
  flex-direction:column;
  gap:.25rem;
  color:#0f172a;
}
.notes-hero__stamp-label{ font-size:.8rem; text-transform:uppercase; letter-spacing:.12em; color:#475569; }
.notes-hero__stamp-value{ font-size:1.5rem; }
.notes-hero__metrics{
  margin-top:2rem;
  display:grid;
  gap:1rem;
  grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
}
.notes-metric{
  background:rgba(255,255,255,.78);
  border-radius:1.25rem;
  padding:1.25rem;
  box-shadow:0 14px 32px rgba(15,23,42,.06);
  display:flex;
  flex-direction:column;
  gap:.5rem;
}
.notes-metric__label{ font-size:.85rem; text-transform:uppercase; letter-spacing:.08em; color:#475569; }
.notes-metric__value{ font-size:2rem; font-weight:600; color:#111827; }
.notes-metric__hint{ font-size:.85rem; color:#64748b; }

.card--surface{ background:var(--card-surface,#ffffffeb); backdrop-filter:blur(12px); }

.notes-recent{ margin-top:1.5rem; }
.notes-recent__header{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:1.5rem;
  flex-wrap:wrap;
}
.notes-recent__header h2{ margin:0; }
.notes-recent__timestamp{ font-size:.85rem; color:#6366f1; align-self:flex-end; }
.notes-recent__list{ list-style:none; margin:1.5rem 0 0; padding:0; display:grid; gap:1rem; }
.notes-recent__item{ padding:1rem 1.25rem; border-radius:1rem; background:rgba(241,245,249,.7); transition:transform .2s ease, box-shadow .2s ease; }
.notes-recent__item:hover{ transform:translateY(-3px); box-shadow:0 12px 26px rgba(148,163,184,.25); }
.notes-recent__link{ display:flex; flex-direction:column; gap:.35rem; color:inherit; text-decoration:none; }
.notes-recent__title{ font-weight:600; }
.notes-recent__meta{ font-size:.85rem; color:#475569; display:flex; gap:1rem; }
.notes-recent__excerpt{ margin:.25rem 0 0; color:#1e293b; font-size:.9rem; }

.notes-controls{ margin-top:1.5rem; display:flex; flex-direction:column; gap:1.25rem; }
.notes-filter{ display:flex; flex-direction:column; gap:1.25rem; }
.notes-filter__grid{ display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
.notes-field{ display:flex; flex-direction:column; gap:.35rem; }
.notes-field__label{ font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.notes-field input{ border:1px solid #cbd5f5; border-radius:.75rem; padding:.65rem .85rem; font-size:1rem; }
.notes-filter__actions{ display:flex; gap:.75rem; flex-wrap:wrap; }
.notes-toolbar{ display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:center; }
.notes-quick-filters{ display:flex; gap:.5rem; flex-wrap:wrap; }
.chip{ border:1px solid rgba(99,102,241,.3); background:rgba(99,102,241,.08); color:#3730a3; border-radius:999px; padding:.45rem 1rem; font-size:.85rem; cursor:pointer; transition:all .2s ease; }
.chip.is-active, .chip[aria-pressed="true"]{ background:#6366f1; color:#fff; border-color:#6366f1; box-shadow:0 8px 18px rgba(99,102,241,.35); }
.chip:hover{ transform:translateY(-1px); }
.notes-view-toggle{ display:flex; gap:.5rem; border:1px solid rgba(148,163,184,.4); border-radius:999px; padding:.25rem; background:rgba(255,255,255,.7); }
.notes-view-toggle__link{ padding:.45rem 1.1rem; border-radius:999px; text-decoration:none; color:#475569; font-weight:500; }
.notes-view-toggle__link.is-active{ background:#0ea5e9; color:#fff; box-shadow:0 12px 24px rgba(14,165,233,.25); }

.notes-content{ margin-top:1.5rem; position:relative; }
.notes-empty{ display:none; flex-direction:column; align-items:center; justify-content:center; gap:.75rem; padding:2.5rem 1rem; text-align:center; border:2px dashed rgba(148,163,184,.4); border-radius:1.5rem; background:rgba(248,250,252,.8); }
.notes-empty.is-visible{ display:flex; }
.notes-empty--initial{ display:flex; }
.notes-empty__icon{ font-size:2rem; }

.sticky-grid{
  display:grid;
  gap:1.25rem;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
}
@media (min-width:780px){
  .sticky-grid{ grid-template-columns:repeat(3,1fr); }
}
@media (min-width:1100px){
  .sticky-grid{ grid-template-columns:repeat(4,1fr); }
}
.notes-collection{ display:grid; }
.is-hidden{ display:none !important; }

.notes-table-wrapper{ overflow-x:auto; }
.notes-table thead th{ text-transform:uppercase; font-size:.8rem; letter-spacing:.08em; color:#64748b; }
.notes-row{ transition:background .2s ease, transform .2s ease; }
.notes-row:hover{ background:rgba(14,165,233,.08); }
.notes-row.is-shared{ border-left:4px solid rgba(99,102,241,.6); }
.notes-row__title{ display:flex; justify-content:space-between; align-items:flex-start; gap:.5rem; }
.notes-row__title a{ font-weight:600; text-decoration:none; color:#0f172a; }
.notes-row__title a:hover{ text-decoration:underline; }
.notes-row__badges{ display:flex; gap:.4rem; flex-wrap:wrap; }
.notes-snippet{ margin-top:.35rem; font-size:.9rem; color:#475569; line-height:1.4; }
.notes-row__meta{ font-size:.8rem; margin-top:.25rem; }
.notes-count-chip{ display:inline-flex; align-items:center; gap:.25rem; background:rgba(226,232,240,.7); border-radius:999px; padding:.3rem .75rem; font-size:.85rem; }
.notes-count-chip.has-value{ background:#e0f2fe; color:#0f172a; }
.notes-row__actions{ display:flex; gap:.4rem; justify-content:flex-end; }

.postit{
  position:relative;
  background:#fffbe6;
  border:1px solid #f0e6a6;
  border-radius:18px;
  padding:1.25rem;
  box-shadow:0 6px 18px rgba(0,0,0,.06), 0 2px 0 rgba(0,0,0,.04) inset;
  transform:rotate(var(--tilt,0deg));
  transition:transform .2s ease, box-shadow .2s ease;
  min-height:230px;
  display:flex;
  flex-direction:column;
  gap:.75rem;
  cursor:pointer;
}
.postit:hover{ transform:rotate(0deg) translateY(-4px); box-shadow:0 18px 34px rgba(15,23,42,.12); }
.postit .tape{
  position:absolute;
  top:-12px;
  left:50%;
  transform:translateX(-50%) rotate(-2deg);
  width:82px;
  height:24px;
  background:rgba(255,255,255,.65);
  border:1px solid rgba(0,0,0,.05);
  border-radius:6px;
  box-shadow:0 6px 12px rgba(15,23,42,.08);
}
.postit-head{ display:flex; justify-content:space-between; gap:.5rem; align-items:center; }
.postit-date{ font-size:.85rem; color:#6b7280; }
.postit-tags{ display:flex; gap:.35rem; flex-wrap:wrap; }
.postit-title{ margin:0; font-size:1.05rem; line-height:1.3; }
.postit-title a{ text-decoration:none; color:#0f172a; }
.postit-title a:hover{ text-decoration:underline; }
.postit-body{ margin:0; color:#111827; }
.postit-meta{ margin-top:auto; display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; }
.meta-pill{
  display:inline-flex;
  align-items:center;
  gap:.25rem;
  border:1px solid #e2e8f0;
  border-radius:999px;
  padding:.25rem .65rem;
  background:#fff;
  font-size:.85rem;
}
.postit.c1{ background:#fff9db; border-color:#ffe27a; }
.postit.c2{ background:#e7fff3; border-color:#b8f3d2; }
.postit.c3{ background:#eaf4ff; border-color:#bfd9ff; }
.postit.c4{ background:#fff0f6; border-color:#ffc4da; }
.postit.c5{ background:#f3fff0; border-color:#c7f7bc; }
.postit.c6{ background:#f5f0ff; border-color:#dacbff; }
.btn.tiny{ padding:.2rem .5rem; font-size:.75rem; border-radius:8px; }

@media (max-width: 720px){
  .notes-hero{ padding:2rem; }
  .notes-hero__metrics{ grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); }
  .notes-toolbar{ flex-direction:column; align-items:flex-start; }
  .notes-view-toggle{ align-self:stretch; justify-content:space-between; }
}
</style>

<script>
(() => {
  const CURRENT = '<?= $view === 'sticky' ? 'sticky' : 'table'; ?>';

  try { localStorage.setItem('notes_view', CURRENT); } catch (e) {}

  document.addEventListener('click', (e) => {
    const a = e.target.closest('.js-toggle-view');
    if (!a) return;
    try {
      const url = new URL(a.href, location.href);
      const next = url.searchParams.get('view') || 'table';
      localStorage.setItem('notes_view', next);
    } catch (err) {}
  });

  (function applyInitialPreference() {
    const params = new URLSearchParams(location.search);
    if (params.has('view')) return;
    try {
      const pref = localStorage.getItem('notes_view');
      if (pref && (pref === 'table' || pref === 'sticky') && pref !== CURRENT) {
        const u = new URL(location.href);
        u.searchParams.set('view', pref);
        location.replace(u.toString());
      }
    } catch (err) {}
  })();

  window.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.querySelector('[data-live-search]');
    const noteNodes = Array.from(document.querySelectorAll('[data-note]'));
    const quickButtons = Array.from(document.querySelectorAll('[data-filter-button]'));
    const emptyState = document.querySelector('[data-empty]');
    const resetButtons = Array.from(document.querySelectorAll('[data-reset-filters]'));
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let activeFilter = 'all';

    function updateButtonStates() {
      quickButtons.forEach((btn) => {
        const val = btn.getAttribute('data-filter-button') || 'all';
        const isActive = activeFilter !== 'all' && val === activeFilter;
        btn.classList.toggle('is-active', isActive);
        btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });
      const allButton = quickButtons.find((btn) => (btn.getAttribute('data-filter-button') || 'all') === 'all');
      if (allButton) {
        const shouldHighlight = activeFilter === 'all';
        allButton.classList.toggle('is-active', shouldHighlight);
        allButton.setAttribute('aria-pressed', shouldHighlight ? 'true' : 'false');
      }
    }

    function matchesQuickFilter(node) {
      switch (activeFilter) {
        case 'mine':
          return node.dataset.owned === '1';
        case 'shared':
          return node.dataset.shared === '1';
        case 'photos':
          return Number(node.dataset.photos || 0) > 0;
        case 'replies':
          return Number(node.dataset.comments || 0) > 0;
        default:
          return true;
      }
    }

    function applyFilters() {
      const term = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
      let visible = 0;

      noteNodes.forEach((node) => {
        const haystack = (node.dataset.search || '').toLowerCase();
        const matchesSearch = term === '' || haystack.includes(term);
        const matchesFilter = matchesQuickFilter(node);
        const shouldShow = matchesSearch && matchesFilter;
        node.classList.toggle('is-hidden', !shouldShow);
        if (shouldShow) {
          visible++;
        }
      });

      if (emptyState) {
        emptyState.classList.toggle('is-visible', visible === 0);
        emptyState.setAttribute('aria-hidden', visible === 0 ? 'false' : 'true');
      }
    }

    if (searchInput) {
      searchInput.addEventListener('input', applyFilters);
      searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          searchInput.value = '';
          applyFilters();
        }
      });
    }

    quickButtons.forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        const value = btn.getAttribute('data-filter-button') || 'all';
        activeFilter = activeFilter === value ? 'all' : value;
        updateButtonStates();
        applyFilters();
      });
    });

    resetButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        activeFilter = 'all';
        if (searchInput) {
          searchInput.value = '';
        }
        updateButtonStates();
        applyFilters();
      });
    });

    updateButtonStates();
    applyFilters();

    if (!prefersReducedMotion) {
      const counters = Array.from(document.querySelectorAll('[data-count-target]'));
      counters.forEach((el) => {
        const target = Number(el.getAttribute('data-count-target') || '0');
        const decimals = Number(el.getAttribute('data-count-decimals') || '0');
        if (!Number.isFinite(target)) {
          return;
        }
        const start = performance.now();
        const duration = 700;
        const startValue = 0;
        const formatter = new Intl.NumberFormat(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

        function step(now) {
          const progress = Math.min(1, (now - start) / duration);
          const value = startValue + (target - startValue) * progress;
          el.textContent = formatter.format(value);
          if (progress < 1) {
            requestAnimationFrame(step);
          } else {
            el.textContent = formatter.format(target);
          }
        }

        el.textContent = formatter.format(0);
        requestAnimationFrame(step);
      });
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php';