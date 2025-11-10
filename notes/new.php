<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$errors = [];
$today  = date('Y-m-d');

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $data = [
            'user_id'   => (int)(current_user()['id'] ?? 0),
            'note_date' => (string)($_POST['note_date'] ?? $today),
            'title'     => trim((string)($_POST['title'] ?? '')),
            'body'      => trim((string)($_POST['body'] ?? '')),
        ];
        if ($data['title'] === '') $errors[] = 'Title is required.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['note_date'])) $errors[] = 'Valid date is required.';

        if (!$errors) {
            $id = notes_insert($data);
            // inline photos best-effort
            for ($i=1; $i<=3; $i++) {
                if (!empty($_FILES["photo$i"]['name'])) {
                    try { notes_save_uploaded_photo($id, $i, "photo$i"); } catch (Throwable $e) {}
                }
            }
            redirect_with_message('view.php?id='.$id, 'Note created.', 'success');
        }
    }
}

$title = 'New Note';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <div class="card-header">
    <h1>Create Note</h1>
    <div class="actions">
      <a class="btn" href="index.php">Back to Notes</a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <!-- Form: mobile-first; on desktop your grid makes this 2 columns -->
  <form method="post" enctype="multipart/form-data" class="grid two" novalidate>
    <label>Date
      <input type="date" name="note_date" value="<?= sanitize($_POST['note_date'] ?? $today); ?>" required>
    </label>

    <label>Title
      <input type="text" name="title" value="<?= sanitize($_POST['title'] ?? ''); ?>" required>
    </label>

    <label class="field-span-2">Notes
      <textarea name="body" rows="7" placeholder="Write your note..."><?= sanitize($_POST['body'] ?? ''); ?></textarea>
    </label>

    <!-- Drag & drop + previews -->
    <div class="field-span-2">
      <div id="dropZone"
           class="dropzone"
           data-max-mb="<?= (int)NOTES_MAX_MB; ?>"
           aria-label="Drop images here">
        <div class="dz-icon" aria-hidden="true">📎</div>
        <div class="dz-text">
          <strong>Drag & drop photos</strong> here, or click a slot below to choose.
          <div class="muted small">JPG/PNG/WebP/HEIC up to <?= (int)NOTES_MAX_MB; ?> MB each.</div>
        </div>
      </div>

      <div class="uploader-grid" id="uploaderGrid">
        <?php for ($i=1; $i<=3; $i++): ?>
          <div class="uploader-tile" data-slot="<?= $i; ?>">
            <div class="uploader-thumb" id="preview<?= $i; ?>">
              <span class="muted small">Photo <?= $i; ?></span>
            </div>
            <div class="uploader-actions">
              <label class="btn small">
                Choose
                <input
                  id="photo<?= $i; ?>"
                  type="file"
                  name="photo<?= $i; ?>"
                  accept="image/*,image/heic,image/heif"
                  class="visually-hidden file-compact">
              </label>
              <button type="button" class="btn small secondary" data-clear="<?= $i; ?>">Clear</button>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    </div>

    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">

    <div class="form-actions field-span-2">
      <button class="btn primary" type="submit">Create</button>
      <a class="btn secondary" href="index.php">Cancel</a>
    </div>
  </form>
</section>

<!-- Minimal styles that piggyback on your light theme (safe to keep here or move to app.css) -->
<style>
/* Drag & drop area */
.dropzone{
  display:flex; align-items:center; gap:.8rem;
  padding:1rem; margin-bottom:.8rem;
  border:2px dashed var(--line,#e7ecf3); border-radius:14px; background:#fbfcff;
}
.dropzone.is-drag{ background:#f3f7ff; border-color:#b9c7ff; }
.dropzone .dz-icon{ font-size:1.25rem; }
.dropzone .dz-text{ line-height:1.35; }

/* Uploader grid */
.uploader-grid{
  display:grid; grid-template-columns:1fr; gap:.8rem;
}
@media (min-width:720px){ .uploader-grid{ grid-template-columns:repeat(3, 1fr); } }

.uploader-tile{
  border:1px solid var(--line,#e7ecf3); border-radius:14px; background:#fff;
  padding:.6rem; display:flex; flex-direction:column; gap:.5rem;
}
.uploader-thumb{
  display:grid; place-items:center;
  aspect-ratio: 4/3;
  border-radius:10px; background:#f6f8fd;
  overflow:hidden; border:1px dashed #e6ebf5;
}
.uploader-thumb img{
  width:100%; height:100%; object-fit:cover; display:block;
}
.uploader-actions{ display:flex; gap:.5rem; justify-content:space-between; }
.visually-hidden{ position:absolute !important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const maxMB = parseInt(document.getElementById('dropZone')?.dataset.maxMb || '70', 10);
  const maxBytes = maxMB * 1024 * 1024;

  const inputs = [1,2,3].map(i => document.getElementById('photo' + i));
  const previews = [1,2,3].map(i => document.getElementById('preview' + i));
  const dropZone = document.getElementById('dropZone');

  function clearSlot(i){
    const input = inputs[i-1], preview = previews[i-1];
    if (!input || !preview) return;
    try {
      // Clear the FileList via a fresh DataTransfer
      const dt = new DataTransfer();
      input.files = dt.files;
    } catch(_) { input.value = ''; }
    preview.innerHTML = '<span class="muted small">Photo ' + i + '</span>';
  }

  function showPreview(i, file){
    const preview = previews[i-1];
    if (!preview) return;
    const reader = new FileReader();
    reader.onload = e => {
      preview.innerHTML = '';
      const img = document.createElement('img');
      img.src = e.target.result;
      img.alt = 'Preview ' + i;
      preview.appendChild(img);
    };
    reader.readAsDataURL(file);
  }

  function setFileToInput(input, file){
    if (!file) return false;
    if (file.size > maxBytes) {
      alert('File "'+ file.name +'" is too large. Max ' + maxMB + 'MB.');
      return false;
    }
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    return true;
  }

  function firstEmptyInputIdx(){
    for (let i=0;i<inputs.length;i++){
      if (!inputs[i].files || inputs[i].files.length === 0) return i;
    }
    return -1;
  }

  // Choose: file input change → preview
  inputs.forEach((input, idx) => {
    if (!input) return;
    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      if (!file) { clearSlot(idx+1); return; }
      if (file.size > maxBytes) {
        alert('File "'+ file.name +'" is too large. Max ' + maxMB + 'MB.');
        clearSlot(idx+1);
        return;
      }
      showPreview(idx+1, file);
    });
  });

  // Clear buttons
  document.querySelectorAll('[data-clear]').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = parseInt(btn.getAttribute('data-clear'), 10);
      clearSlot(i);
    });
  });

  // DropZone UX
  if (dropZone){
    const on = () => dropZone.classList.add('is-drag');
    const off = () => dropZone.classList.remove('is-drag');

    ['dragenter','dragover'].forEach(ev =>
      dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); on(); })
    );
    ['dragleave','dragend','drop'].forEach(ev =>
      dropZone.addEventListener(ev, e => { if (ev !== 'drop'){ off(); } })
    );

    dropZone.addEventListener('drop', (e) => {
      e.preventDefault(); e.stopPropagation(); off();
      const files = Array.from(e.dataTransfer?.files || []).filter(f => f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name));
      if (!files.length) return;

      let cursor = firstEmptyInputIdx();
      for (const file of files){
        if (cursor === -1) break;
        const input = inputs[cursor];
        if (setFileToInput(input, file)) {
          showPreview(cursor+1, file);
          cursor = firstEmptyInputIdx();
        }
      }
    });

    // Click dropzone → open first empty slot, else #1
    dropZone.addEventListener('click', () => {
      const idx = Math.max(0, firstEmptyInputIdx());
      inputs[idx]?.click();
    });
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php';
