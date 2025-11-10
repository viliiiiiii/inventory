<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$me = current_user();


$id   = (int)($_GET['id'] ?? 0);
$note = notes_fetch($id);
if (!$note || !notes_can_view($note)) {
    redirect_with_message('index.php', 'Note not found or no access.', 'error');
    exit;
}

$photos          = notes_fetch_photos($id);
$shareDetails    = notes_get_share_details($id);
$commentsEnabled = notes_comments_table_exists();
$commentThreads  = $commentsEnabled ? notes_fetch_comment_threads($id) : [];
$commentCount    = $commentsEnabled ? notes_comment_count($id) : 0;
$errors          = [];

/* decorate comments with can_delete flag */
if ($commentsEnabled && $commentThreads) {
    $decorate = function (&$items) use (&$decorate, $note) {
        foreach ($items as &$item) {
            $item['can_delete'] = notes_comment_can_delete($item, $note);
            if (!empty($item['children'])) { $decorate($item['children']); }
        }
        unset($item);
    };
    $decorate($commentThreads);
}

/* handle new / delete comments */
if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if (isset($_POST['add_comment']) && $commentsEnabled) {
    $body     = trim((string)($_POST['body'] ?? ''));
    $parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    if ($body === '') {
        $errors[] = 'Comment cannot be empty.';
    } else {
        try {
            $authorId  = (int)($me['id'] ?? 0);
            $commentId = notes_comment_insert($id, $authorId, $body, $parentId ?: null);
            log_event('note.comment.create', 'note', $id, ['comment_id' => $commentId]);

            // recipients = owner + all shared users – author
            $authorId  = (int)($me['id'] ?? 0);
$ownerId   = (int)($note['user_id'] ?? 0);
$sharedIds = array_map('intval', notes_get_share_user_ids($id) ?: []);
$recipients = array_unique(array_filter(array_merge([$ownerId], $sharedIds)));
$recipients = array_values(array_diff($recipients, [$authorId])); // exclude author


            if ($recipients) {
                $t      = trim((string)($note['title'] ?? 'Untitled'));
                $title  = "New reply on “{$t}”";
                $excerpt= mb_substr($body, 0, 140);
                $link   = "/notes/view.php?id={$id}#comment-{$commentId}";
                $payload= ['note_id' => (int)$id, 'comment_id' => (int)$commentId];

                notify_users($recipients, 'note.comment', $title, $excerpt, $link, $payload);
            }

            redirect_with_message('view.php?id='.$id.'#comment-'.$commentId, 'Reply posted.', 'success');
        } catch (Throwable $e) {
            $errors[] = 'Failed to save comment.';
        }
    }
}

        if (isset($_POST['delete_comment']) && $commentsEnabled) {
            $commentId = (int)$_POST['delete_comment'];
            $comment   = notes_comment_fetch($commentId);
            if (!$comment || (int)($comment['note_id'] ?? 0) !== $id) {
                $errors[] = 'Comment not found.';
            } elseif (!notes_comment_can_delete($comment, $note)) {
                $errors[] = 'You cannot remove this comment.';
            } else {
                notes_comment_delete($commentId);
                log_event('note.comment.delete', 'note', $id, ['comment_id'=>$commentId]);
                redirect_with_message('view.php?id='.$id.'#comments', 'Comment removed.', 'success');
            }
        }
    }
}

/* refresh comments after post */
if ($commentsEnabled) {
    $commentThreads  = notes_fetch_comment_threads($id);
    $commentCount    = notes_comment_count($id);
    if ($commentThreads) {
        $decorate = function (&$items) use (&$decorate, $note) {
            foreach ($items as &$item) {
                $item['can_delete'] = notes_comment_can_delete($item, $note);
                if (!empty($item['children'])) { $decorate($item['children']); }
            }
            unset($item);
        };
        $decorate($commentThreads);
    }
}

$title = 'View Note';
include __DIR__ . '/../includes/header.php';
?>
<style>
/* ==== Panels & compact layout (same vibe as edit) ==== */
.panel {
  border: 1px solid #e7ebf3;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 6px 18px rgba(16,24,40,0.06);
  overflow: hidden;
}
.panel + .panel { margin-top: 16px; }

.panel__header {
  display:flex; align-items: center; justify-content: space-between;
  gap: 12px; padding: 14px 16px;
  background: linear-gradient(135deg, #f7faff 0%, #eef4ff 100%);
  border-bottom: 1px solid #e7ebf3;
}
.panel__title {
  display:flex; flex-direction: column; gap: 6px;
}
.panel__title h1 {
  margin:0; font-size: 20px; line-height:1.2; color:#0f172a;
}
.panel__meta { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.panel__actions { display:flex; gap:8px; flex-wrap:wrap; }

.panel__body { padding: 14px 16px; }

/* badges */
.badge { padding:3px 8px; border-radius:999px; font-size:11px; font-weight:700; background:#f3f6fb; color:#334155; }

/* prose */
.prose { line-height:1.55; color:#0f172a; }
.prose p { margin:0 0 .7rem; white-space:pre-wrap; }

/* 2-col main layout: text left, photos right (responsive) */
.note-view-shell { display:grid; gap:16px; }
@media (min-width: 980px) {
  .note-view-shell { grid-template-columns: 1.15fr .85fr; align-items:start; }
}

/* photos */
.photo-card__head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; }
.photo-grid {
  --min: 160px;
  display:grid; gap:10px;
  grid-template-columns: repeat(auto-fill, minmax(var(--min), 1fr));
}
.thumb { display:block; border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(16,24,40,.08); }
.thumb img {
  display:block; width:100%; height:auto; object-fit:cover;
  aspect-ratio: 4/3; /* keeps grid tidy */
}

/* comments */
.note-comments { display:grid; gap:12px; }
.note-comment {
  border:1px solid #eef1f6; border-radius:10px; background:#fff; padding:10px 12px;
}
.note-comment-header { display:flex; gap:8px; align-items:baseline; }
.note-comment-header .small { font-size:12px; color:#64748b; }
.note-comment-body { margin-top:6px; white-space:pre-wrap; }
.note-comment-actions { display:flex; gap:12px; align-items:center; margin-top:8px; }
.note-comment-children { border-left:3px solid #eef1f6; margin-left:10px; padding-left:10px; display:grid; gap:10px; }

.note-comment-form textarea { width:100%; }
.note-comment-new { margin-top:10px; }

/* photo modal (shared component, just larger box here) */
.photo-modal .photo-modal-box { max-width: 1080px; width: 92vw; height: 86vh; }
.photo-modal .photo-modal-body { height: calc(86vh - 56px); }
</style>

<!-- ============= NOTE TOP (title, date, shares, actions) ============= -->
<section class="panel">
  <div class="panel__header">
    <div class="panel__title">
      <h1><?= sanitize($note['title'] ?: 'Untitled'); ?></h1>
      <div class="panel__meta">
        <span class="badge"><?= sanitize($note['note_date']); ?></span>
        <?php if ($shareDetails): ?>
          <?php foreach ($shareDetails as $share): ?>
            <span class="badge"><?= sanitize($share['label']); ?></span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel__actions">
      <a class="btn" href="index.php">Back</a>
      <?php if (notes_can_edit($note)): ?>
        <a class="btn" href="edit.php?id=<?= (int)$note['id']; ?>">Edit</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="panel__body">
      <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
    </div>
  <?php endif; ?>
</section>

<!-- ============= MAIN CONTENT (text left, photos right) ============= -->
<section class="panel">
  <div class="panel__body">
    <div class="note-view-shell">
      <!-- Left: text -->
      <div>
        <?php if (!empty($note['body'])): ?>
          <div class="prose">
            <?= nl2br(sanitize($note['body'])); ?>
          </div>
        <?php else: ?>
          <p class="muted">No text.</p>
        <?php endif; ?>
      </div>

      <!-- Right: photos -->
      <div>
        <div class="photo-card__head">
          <h3 style="margin:0;">Photos</h3>
          <?php if (array_filter($photos)): ?>
            <button class="btn small" type="button" id="openAllNotePhotos">View larger</button>
          <?php endif; ?>
        </div>

        <div class="photo-grid" id="noteViewPhotoGrid">
          <?php for ($i=1; $i<=3; $i++): $p = $photos[$i] ?? null; ?>
            <?php if ($p): ?>
              <a href="<?= sanitize($p['url']); ?>" target="_blank" rel="noopener" class="thumb js-zoom">
                <img src="<?= sanitize($p['url']); ?>" alt="Note photo <?= $i; ?>" loading="lazy" decoding="async">
              </a>
            <?php else: ?>
              <div class="muted small">No photo #<?= $i; ?></div>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============= COMMENTS ============= -->
<section class="panel" id="comments">
  <div class="panel__header">
    <div class="panel__title">
      <h1 style="font-size:16px;margin:0;">Discussion</h1>
      <div class="panel__meta"><span class="badge"><?= (int)$commentCount; ?> replies</span></div>
    </div>
  </div>

  <div class="panel__body">
    <?php if (!$commentsEnabled): ?>
      <p class="muted">Commenting is disabled because the note_comments table was not detected.</p>
    <?php else: ?>
      <?php if (!$commentThreads): ?>
        <p class="muted">No replies yet.</p>
      <?php else: ?>
        <div class="note-comments">
          <?php
          $renderComment = function (array $comment) use (&$renderComment) {
              ?>
              <article class="note-comment" id="comment-<?= (int)$comment['id']; ?>">
                <header class="note-comment-header">
                  <strong><?= sanitize($comment['author_label']); ?></strong>
                  <span class="small"><?= sanitize(substr((string)($comment['created_at'] ?? ''), 0, 16)); ?></span>
                </header>

                <div class="note-comment-body"><?= nl2br(sanitize($comment['body'] ?? '')); ?></div>

                <footer class="note-comment-actions">
                  <details class="note-comment-reply">
                    <summary>Reply</summary>
                    <form method="post" class="note-comment-form">
                      <textarea name="body" rows="3" required></textarea>
                      <input type="hidden" name="parent_id" value="<?= (int)$comment['id']; ?>">
                      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
                      <button class="btn small" type="submit" name="add_comment" value="1">Post Reply</button>
                    </form>
                  </details>

                  <?php if (!empty($comment['can_delete'])): ?>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this reply?');">
                      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
                      <button class="btn small" type="submit" name="delete_comment" value="<?= (int)$comment['id']; ?>">Delete</button>
                    </form>
                  <?php endif; ?>
                </footer>

                <?php if (!empty($comment['children'])): ?>
                  <div class="note-comment-children">
                    <?php foreach ($comment['children'] as $child) { $renderComment($child); } ?>
                  </div>
                <?php endif; ?>
              </article>
              <?php
          };

          foreach ($commentThreads as $comment) { $renderComment($comment); }
          ?>
        </div>
      <?php endif; ?>

      <form method="post" class="note-comment-form note-comment-new">
        <label class="field-span-2">
          <span class="lbl">Add a reply</span>
          <textarea name="body" rows="4" required><?= sanitize($_POST['body'] ?? ''); ?></textarea>
        </label>
        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
        <button class="btn primary" type="submit" name="add_comment" value="1">Post Reply</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<!-- Zoom Modal -->
<div id="noteViewPhotoModal" class="photo-modal hidden" aria-hidden="true">
  <div class="photo-modal-backdrop" data-close-note-view></div>
  <div class="photo-modal-box" role="dialog" aria-modal="true" aria-labelledby="noteViewPhotoModalTitle">
    <div class="photo-modal-header">
      <h3 id="noteViewPhotoModalTitle">Photos</h3>
      <button class="close-btn" type="button" title="Close" data-close-note-view>&times;</button>
    </div>
    <div id="noteViewPhotoModalBody" class="photo-modal-body"></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const grid   = document.getElementById('noteViewPhotoGrid');
  const modal  = document.getElementById('noteViewPhotoModal');
  const bodyEl = document.getElementById('noteViewPhotoModalBody');
  const openAllBtn = document.getElementById('openAllNotePhotos');

  function openModal(){ modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
  function closeModal(){ modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); document.body.style.overflow=''; bodyEl.innerHTML=''; }

  modal.addEventListener('click', (e) => { if (e.target.matches('[data-close-note-view], .photo-modal-backdrop')) closeModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });

  function injectImages(urls){
    const frag = document.createDocumentFragment();
    urls.forEach(u => {
      if (!u) return;
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.decoding = 'async';
      img.src = u;
      img.alt = 'Note photo';
      frag.appendChild(img);
    });
    bodyEl.innerHTML = '';
    bodyEl.appendChild(frag);
  }

  // Click any thumbnail → modal (ctrl/cmd-click still opens new tab)
  if (grid) {
    grid.addEventListener('click', (e) => {
      const a = e.target.closest('.js-zoom');
      if (!a) return;
      if (e.metaKey || e.ctrlKey) return; // let user open new tab
      e.preventDefault();
      const imgs = Array.from(grid.querySelectorAll('.js-zoom img')).map(i => i.getAttribute('src')).filter(Boolean);
      const clicked = a.querySelector('img')?.getAttribute('src');
      const ordered = clicked ? [clicked].concat(imgs.filter(u => u !== clicked)) : imgs;
      if (!ordered.length) return;
      injectImages(ordered);
      openModal();
    });
  }

  // “View larger” button → all images
  if (openAllBtn) {
    openAllBtn.addEventListener('click', () => {
      const imgs = Array.from(grid.querySelectorAll('.js-zoom img')).map(i => i.getAttribute('src')).filter(Boolean);
      if (!imgs.length) return;
      injectImages(imgs);
      openModal();
    });
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php';
