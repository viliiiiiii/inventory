<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$id   = (int)($_GET['id'] ?? 0);
$note = notes_fetch($id);
if (!$note || !notes_can_view($note)) {
    redirect_with_message('index.php', 'Note not found or no access.', 'error');
    exit;
}

$canEdit       = notes_can_edit($note);
$canShare      = notes_can_share($note);
$photos        = notes_fetch_photos($id);
$shareOptions  = notes_all_users();
$currentShares = notes_get_share_user_ids($id);
if (!is_array($currentShares)) $currentShares = [];
$sharedCount   = count($currentShares);
$ownerId       = (int)$note['user_id'];

$errors = [];

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {

        // delete note
        if (isset($_POST['delete_note']) && $canEdit) {
            notes_delete($id);
            log_event('note.delete', 'note', $id);
            redirect_with_message('index.php', 'Note deleted.', 'success');
        }

        // save text fields
        if (isset($_POST['save_note']) && $canEdit) {
            $data = [
                'note_date' => (string)($_POST['note_date'] ?? ''),
                'title'     => trim((string)($_POST['title'] ?? '')),
                'body'      => trim((string)($_POST['body'] ?? '')),
            ];
            if ($data['title'] === '') $errors[] = 'Title is required.';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['note_date'])) $errors[] = 'Valid date is required.';
            if (!$errors) {
                notes_update($id, $data);
                log_event('note.update', 'note', $id);
                redirect_with_message('view.php?id='.$id, 'Note updated.', 'success');
            }
            $note = array_merge($note, $data);
        }

        // Only compute BEFORE if we're actually saving shares
if (isset($_POST['save_shares'])) {
    $before = array_map('intval', notes_get_share_user_ids($id) ?: []);

    if (!$canShare) { http_response_code(403); exit('Forbidden'); }

    $selected = array_map('intval', (array)($_POST['shared_ids'] ?? []));
    $selected = array_values(array_filter($selected, fn($u) => $u !== $ownerId));

    try {
        // Persist selection
        notes_update_shares($id, $selected);

        // AFTER & diff
        $after = array_map('intval', notes_get_share_user_ids($id) ?: []);
        $added = array_values(array_diff($after, $before));

        // Notify only new users; never let this crash the request
        if ($added) {
            try {
                $me   = current_user();
                $who  = $me['email'] ?? 'Someone';
                $t    = trim((string)($note['title'] ?? 'Untitled'));
                $date = (string)($note['note_date'] ?? '');

                $title   = "A note was shared with you";
                $body    = "“{$t}” {$date} — shared by {$who}";
                $link    = "/notes/view.php?id=" . (int)$id;
                $payload = ['note_id' => (int)$id, 'by' => $who];

                if (!function_exists('notify_users')) {
                    error_log('notify_users() missing');
                } else {
                    notify_users($added, 'note.shared', $title, $body, $link, $payload);
                }
                log_event('note.share', 'note', (int)$id, ['added' => $added]);
            } catch (Throwable $nx) {
                error_log('notify_users failed: '.$nx->getMessage());
            }
        }

        redirect_with_message('edit.php?id='.$id, 'Shares updated.', 'success');
    } catch (Throwable $e) {
        error_log('notes_update_shares failed: '.$e->getMessage());
        $errors[] = 'Failed to update shares.';
    }
}

// Send notifications only to newly added users
if ($added) {
    $me   = current_user();
    $who  = $me['email'] ?? 'Someone';
    $t    = trim((string)($note['title'] ?? 'Untitled'));
    $date = (string)($note['note_date'] ?? '');
    $title   = "A note was shared with you";
    $body    = "“{$t}” {$date} — shared by {$who}";
    $link    = "/notes/view.php?id=" . (int)$id; // opens the note
    $payload = ['note_id' => (int)$id, 'by' => $who];

    notify_users($added, 'note.shared', $title, $body, $link, $payload);
    log_event('note.share', 'note', (int)$id, ['added' => $added]);
}

        // photo upload/replace
        if (isset($_POST['upload_position']) && $canEdit) {
            $pos = (int)$_POST['upload_position'];
            if (in_array($pos, [1,2,3], true)) {
                try {
                    notes_save_uploaded_photo($id, $pos, 'photo');
                    redirect_with_message('edit.php?id='.$id, "Photo $pos uploaded.", 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Photo upload failed: '.$e->getMessage();
                }
                $photos = notes_fetch_photos($id);
            } else {
                $errors[] = 'Bad photo position.';
            }
        }

        // photo delete
        if (isset($_POST['delete_photo_id']) && $canEdit) {
            try {
                notes_remove_photo_by_id((int)$_POST['delete_photo_id']);
                redirect_with_message('edit.php?id='.$id, 'Photo removed.', 'success');
            } catch (Throwable $e) {
                $errors[] = 'Failed to remove photo.';
            }
            $photos = notes_fetch_photos($id);
        }
    }
}

$title = 'Edit Note';
include __DIR__ . '/../includes/header.php';
?>
<style>
/* ===== Modern Panel Look ===== */
.panel {
  border: 1px solid #e7ebf3;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 6px 18px rgba(16,24,40,0.06);
  overflow: hidden;
}
.panel + .panel { margin-top: 16px; }

.panel__header {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; padding: 14px 16px;
  background: linear-gradient(135deg, #f7faff 0%, #eef4ff 100%);
  border-bottom: 1px solid #e7ebf3;
}
.panel__title {
  font-weight: 700; font-size: 16px; color: #0f172a;
  display: flex; align-items:center; gap: 10px;
}
.panel__meta { display: flex; gap: 6px; flex-wrap: wrap; align-items:center; }
.panel__actions { display: flex; gap: 8px; flex-wrap: wrap; }

.panel__body { padding: 14px 16px; }

/* ===== Compact grid layout ===== */
.note-shell {
  display: grid; gap: 16px;
}
@media (min-width: 960px) {
  .note-shell {
    grid-template-columns: 1.05fr 0.95fr; /* Left fields | Right tabs */
    align-items: start;
  }
}

/* Inputs spacing tighter */
.panel__body .grid.two label,
.panel__body .field { margin-bottom: 10px; }

/* Badges */
.badge { padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background:#f3f6fb; color:#334155; }

/* ===== Tabs (right side) ===== */
.tabs { display: flex; align-items: center; gap: 6px; padding: 6px; background:#f6f8fd; border-radius: 10px; }
.tab-btn {
  appearance: none; border: 1px solid transparent; background: transparent; color:#475569;
  padding: 6px 10px; border-radius: 10px; font-size: 13px; cursor: pointer;
}
.tab-btn[aria-selected="true"] { background:#fff; border-color:#dfe6f2; color:#0f172a; box-shadow: 0 1px 3px rgba(16,24,40,0.06); }

.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ===== Sharing chooser ===== */
.share-box {
  border: 1px dashed #dfe6f2; border-radius: 12px; padding: 10px; background: #fbfdff;
}
.share-head { display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin-bottom:8px; }
.share-search { flex:1 1 200px; }
.share-search input { width:100%; }
.share-actions { display:flex; gap:6px; flex-wrap:wrap; }

.share-list {
  max-height: 260px; overflow: auto; padding-right: 6px;
  display: grid; gap: 6px;
}
@media (min-width: 720px) {
  .share-list { grid-template-columns: repeat(2, minmax(0,1fr)); }
}
.share-item {
  display:flex; gap:8px; align-items:center;
  padding:10px; border:1px solid #eef1f6; border-radius:10px; background:#fff;
}

/* ===== Photo modal (reuse your classes, just larger box) ===== */
.photo-modal .photo-modal-box { max-width: 1080px; width: 92vw; height: 86vh; }
.photo-modal .photo-modal-body { height: calc(86vh - 56px); }

/* ===== Drag & Drop photo pods ===== */
.dz-grid{--gap:10px;display:grid;gap:var(--gap);grid-template-columns:repeat(3,1fr)}
.dz-slot{
  position:relative;border:1px dashed #cdd6e6;border-radius:12px;background:#f8faff;
  min-height:160px;display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:12px;transition:border-color .15s, background .15s; cursor:pointer;
}
.dz-slot:hover{border-color:#90a2c9;background:#f3f7ff}
.dz-slot.is-dragover{border-color:#2563eb;background:#eef4ff}

/* when a photo exists, pod is “zoom only” */
.dz-slot.has-photo{cursor:zoom-in}

/* Centered image */
.dz-thumb{
  display:block;margin:0 auto;
  max-width:100%; max-height:220px; width:auto; height:auto;
  object-fit:contain; border-radius:10px;
  box-shadow:0 2px 8px rgba(16,24,40,.08);
}

/* Empty placeholder text */
.dz-empty-text{color:#69809c;font-size:12px;text-align:center;line-height:1.35}

/* Remove button under the image */
.dz-actions{margin-top:10px;display:flex;gap:8px;justify-content:center}

/* Hidden file input */
.dz-input{display:none}

/* Busy overlay */
.dz-busy::after{
  content:'Uploading…';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  background:rgba(255,255,255,.7);backdrop-filter:blur(2px);font-weight:700;color:#0f172a;border-radius:12px;
}
</style>

<section class="panel">
  <div class="panel__header">
    <div class="panel__title">
      Edit Note
      <span class="panel__meta">
        <span class="badge"><?php echo sanitize($note['note_date']); ?></span>
        <?php if ($sharedCount > 0): ?>
          <span class="badge">Shared: <?php echo (int)$sharedCount; ?></span>
        <?php else: ?>
          <span class="badge">Not shared</span>
        <?php endif; ?>
      </span>
    </div>
    <div class="panel__actions">
      <a class="btn" href="index.php">Back</a>
      <a class="btn" href="view.php?id=<?php echo (int)$note['id']; ?>">View</a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="panel__body">
      <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
    </div>
  <?php endif; ?>

  <div class="panel__body">
    <div class="note-shell compact">
      <!-- LEFT: note fields -->
      <section class="panel">
        <div class="panel__header">
          <div class="panel__title">Details</div>
        </div>
        <div class="panel__body">
          <form method="post" class="grid two" novalidate>
            <label>Date
              <input type="date" name="note_date" value="<?php echo sanitize($note['note_date']); ?>" required <?php echo $canEdit?'':'disabled'; ?>>
            </label>

            <label>Title
              <input type="text" name="title" value="<?php echo sanitize($note['title']); ?>" required <?php echo $canEdit?'':'disabled'; ?>>
            </label>

            <label class="field-span-2">Notes
              <textarea name="body" rows="7" <?php echo $canEdit?'':'disabled'; ?>><?php echo sanitize($note['body'] ?? ''); ?></textarea>
            </label>

            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">

            <?php if ($canEdit): ?>
              <div class="form-actions field-span-2">
                <button class="btn primary" type="submit" name="save_note" value="1">Save</button>
                <a class="btn secondary" href="view.php?id=<?php echo (int)$note['id']; ?>">Cancel</a>
              </div>
            <?php endif; ?>
          </form>

          <?php if ($canEdit): ?>
            <form method="post" onsubmit="return confirm('Delete this note?');" class="inline" style="margin-top:.6rem">
              <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="delete_note" value="1">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </section>

      <!-- RIGHT: tabs (Photos / Sharing) -->
      <section class="panel">
        <div class="panel__header" style="align-items: flex-end;">
          <div class="panel__title">Assets & Access</div>
          <div class="tabs" role="tablist" aria-label="Asset tabs">
            <button class="tab-btn" role="tab" aria-selected="true" aria-controls="tab-photos" id="tabbtn-photos">Photos</button>
            <?php if ($canShare): ?>
              <button class="tab-btn" role="tab" aria-selected="false" aria-controls="tab-sharing" id="tabbtn-sharing">Sharing</button>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel__body">
          <!-- Photos panel -->
          <div id="tab-photos" class="tab-panel active" role="tabpanel" aria-labelledby="tabbtn-photos">
            <?php if (array_filter($photos)): ?>
              <div style="display:flex; justify-content:flex-end; margin-bottom:8px;">
                <button class="btn small" type="button" id="openAllPhotos">View larger</button>
              </div>
            <?php endif; ?>

            <div class="dz-grid" id="notePhotoGrid">
              <?php for ($i=1; $i<=3; $i++): $p = $photos[$i] ?? null; ?>
                <div
                  class="dz-slot<?php echo $p ? ' has-photo' : ''; ?>"
                  data-slot="<?php echo $i; ?>"
                  data-csrf-name="<?php echo CSRF_TOKEN_NAME; ?>"
                  data-csrf-value="<?php echo csrf_token(); ?>"
                  title="<?php echo $p ? 'Click to view fullscreen' : 'Click or drop to upload'; ?>"
                >
                  <?php if ($p): ?>
                    <img
                      src="<?php echo sanitize($p['url']); ?>"
                      alt="Note photo <?php echo $i; ?>"
                      class="dz-thumb js-zoom"
                      loading="lazy"
                      decoding="async"
                    >
                    <?php if ($canEdit): ?>
                      <div class="dz-actions">
                        <form method="post" onsubmit="return confirm('Remove this photo?');">
                          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                          <input type="hidden" name="delete_photo_id" value="<?php echo (int)$p['id']; ?>">
                          <button class="btn small" type="submit">Remove</button>
                        </form>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div class="dz-empty-text">Drop image here<br>or tap to upload</div>
                  <?php endif; ?>

                  <?php if ($canEdit): ?>
                    <!-- Hidden picker (tap/click) -->
                    <form method="post" enctype="multipart/form-data" class="dz-form">
                      <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                      <input type="hidden" name="upload_position" value="<?php echo $i; ?>">
                      <input class="dz-input" type="file" name="photo" accept="image/*,image/heic,image/heif">
                    </form>
                  <?php endif; ?>
                </div>
              <?php endfor; ?>
            </div>
          </div>

          <!-- Sharing panel -->
          <?php if ($canShare): ?>
            <div id="tab-sharing" class="tab-panel" role="tabpanel" aria-labelledby="tabbtn-sharing">
              <div class="share-box" id="shareBox">
                <div class="share-head">
                  <div class="share-search">
                    <input type="search" id="shareSearch" class="input" placeholder="Search users…" autocomplete="off">
                  </div>
                  <div class="share-actions">
                    <button class="btn small" type="button" id="shareSelectAll">Select all</button>
                    <button class="btn small secondary" type="button" id="shareClear">Clear</button>
                    <label class="small" style="display:flex;align-items:center;gap:6px;">
                      <input type="checkbox" id="shareOnlySelected">
                      <span>Only selected</span>
                    </label>
                  </div>
                </div>

                <form method="post">
                  <div class="share-list" id="shareList" role="group" aria-label="Share with users">
                    <?php foreach ($shareOptions as $u): ?>
                      <?php
                        $uid = (int)$u['id'];
                        if ($uid === $ownerId) continue;
                        $checked = in_array($uid, $currentShares, true) ? 'checked' : '';
                      ?>
                      <label class="share-item" data-label="<?php echo htmlspecialchars(strtolower((string)$u['email']), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="checkbox" name="shared_ids[]" value="<?php echo $uid; ?>" <?php echo $checked; ?>>
                        <span><?php echo sanitize($u['email']); ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>

                  <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                  <div class="form-actions" style="margin-top:10px;">
                    <button class="btn" type="submit" name="save_shares" value="1">Save Shares</button>
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </section>

    </div>
  </div>
</section>

<!-- Zoom Modal (bigger) -->
<div id="notePhotoModal" class="photo-modal hidden" aria-hidden="true">
  <div class="photo-modal-backdrop" data-close-note-photo></div>
  <div class="photo-modal-box" role="dialog" aria-modal="true" aria-labelledby="notePhotoModalTitle">
    <div class="photo-modal-header">
      <h3 id="notePhotoModalTitle">Photos</h3>
      <button class="close-btn" type="button" title="Close" data-close-note-photo>&times;</button>
    </div>
    <div id="notePhotoModalBody" class="photo-modal-body"></div>
  </div>
</div>

<script>
/* Tabs */
document.addEventListener('DOMContentLoaded', () => {
  const photosBtn  = document.getElementById('tabbtn-photos');
  const shareBtn   = document.getElementById('tabbtn-sharing');
  const photosPane = document.getElementById('tab-photos');
  const sharePane  = document.getElementById('tab-sharing');

  function selectTab(which) {
    if (which === 'photos') {
      photosBtn?.setAttribute('aria-selected','true');
      photosPane?.classList.add('active');
      if (shareBtn) shareBtn.setAttribute('aria-selected','false');
      if (sharePane) sharePane.classList.remove('active');
    } else {
      shareBtn?.setAttribute('aria-selected','true');
      sharePane?.classList.add('active');
      photosBtn?.setAttribute('aria-selected','false');
      photosPane?.classList.remove('active');
    }
  }
  photosBtn?.addEventListener('click', () => selectTab('photos'));
  shareBtn?.addEventListener('click', () => selectTab('share'));
});

/* Photo modal logic */
document.addEventListener('DOMContentLoaded', () => {
  const grid   = document.getElementById('notePhotoGrid');
  const modal  = document.getElementById('notePhotoModal');
  const bodyEl = document.getElementById('notePhotoModalBody');
  const openAllBtn = document.getElementById('openAllPhotos');

  function openModal() {
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
    bodyEl.innerHTML = '';
  }

  modal.addEventListener('click', (e) => {
    if (e.target.matches('[data-close-note-photo], .photo-modal-backdrop')) closeModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
  });

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

  if (grid) {
    grid.addEventListener('click', (e) => {
      const target = e.target.closest('.js-zoom');
      if (!target) return;
      const imgs = Array.from(grid.querySelectorAll('img[src]')).map(i => i.getAttribute('src')).filter(Boolean);
      const clicked = target.getAttribute('src');
      const ordered = [clicked].concat(imgs.filter(u => u !== clicked));
      injectImages(ordered);
      openModal();
    });
  }
  if (openAllBtn) {
    openAllBtn.addEventListener('click', () => {
      const imgs = Array.from(grid.querySelectorAll('img[src]')).map(i => i.getAttribute('src')).filter(Boolean);
      if (!imgs.length) return;
      injectImages(imgs);
      openModal();
    });
  }
});

/* Sharing: search, select-all, clear, only-selected */
document.addEventListener('DOMContentLoaded', () => {
  const list  = document.getElementById('shareList');
  const search= document.getElementById('shareSearch');
  const onlySelected = document.getElementById('shareOnlySelected');
  const selectAllBtn = document.getElementById('shareSelectAll');
  const clearBtn = document.getElementById('shareClear');

  if (!list) return;

  function applyFilters() {
    const q = (search?.value || '').trim().toLowerCase();
    const only = !!(onlySelected && onlySelected.checked);
    const items = list.querySelectorAll('.share-item');
    items.forEach(it => {
      const label = it.getAttribute('data-label') || '';
      const cb    = it.querySelector('input[type="checkbox"]');
      let show = true;
      if (q && !label.includes(q)) show = false;
      if (only && cb && !cb.checked) show = false;
      it.style.display = show ? '' : 'none';
    });
  }

  search?.addEventListener('input', applyFilters);
  onlySelected?.addEventListener('change', applyFilters);

  selectAllBtn?.addEventListener('click', () => {
    list.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = true; });
    applyFilters();
  });
  clearBtn?.addEventListener('click', () => {
    list.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });
    applyFilters();
  });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const grid = document.getElementById('notePhotoGrid');
  if (!grid) return;

  // Click to open file picker (only when slot is empty)
  grid.addEventListener('click', (e) => {
    const slot = e.target.closest('.dz-slot');
    if (!slot) return;
    if (slot.classList.contains('has-photo')) return; // image present -> fullscreen only
    if (e.target.closest('form')) return;             // avoid clicking remove form

    const input = slot.querySelector('.dz-input');
    if (input) input.click();
  });

  // File picker -> normal form submit (page reload)
  grid.addEventListener('change', (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement)) return;
    if (!input.classList.contains('dz-input')) return;
    const form = input.closest('.dz-form');
    if (!form || !input.files || !input.files[0]) return;
    form.submit(); // posts back to same page and reloads by server redirect
  });

  // Drag & drop upload via fetch -> reload after (only when slot is empty)
  function addDnD(slotEl){
    if (slotEl.classList.contains('has-photo')) return; // disable DnD when a photo exists

    ['dragenter','dragover'].forEach(ev => {
      slotEl.addEventListener(ev, (e) => {
        e.preventDefault(); e.stopPropagation();
        slotEl.classList.add('is-dragover');
      });
    });
    ;['dragleave','drop'].forEach(ev => {
      slotEl.addEventListener(ev, (e) => {
        e.preventDefault(); e.stopPropagation();
        if (ev === 'dragleave' && !slotEl.contains(e.relatedTarget)) {
          slotEl.classList.remove('is-dragover');
        }
      });
    });
    slotEl.addEventListener('drop', async (e) => {
      slotEl.classList.remove('is-dragover');
      const files = e.dataTransfer?.files;
      if (!files || !files[0]) return;

      const file = files[0];
      const pos  = slotEl.getAttribute('data-slot');
      const csrfName  = slotEl.getAttribute('data-csrf-name');
      const csrfValue = slotEl.getAttribute('data-csrf-value');

      const fd = new FormData();
      if (csrfName && csrfValue) fd.append(csrfName, csrfValue);
      fd.append('upload_position', pos || '');
      fd.append('photo', file, file.name);

      // Visual busy state
      slotEl.classList.add('dz-busy');
      try {
        await fetch(location.href, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        location.reload();
      } catch (err) {
        console.error(err);
        alert('Upload failed. Please try again.');
        slotEl.classList.remove('dz-busy');
      }
    });
  }

  grid.querySelectorAll('.dz-slot').forEach(addDnD);
});
</script>

<?php include __DIR__ . '/../includes/footer.php';
