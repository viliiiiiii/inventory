<?php
require_once __DIR__ . '/helpers.php';
require_login();
if (!can('edit')) {
    http_response_code(403);
    exit('Forbidden');
}

$taskId = (int)($_GET['id'] ?? 0);
$task = fetch_task($taskId);
if (!$task) {
    redirect_with_message('tasks.php', 'Task not found.', 'error');
}

$originalTask = $task;

$errors    = [];
$photos    = fetch_task_photos($taskId); // [1]..[3]
$photoCnt  = 0;
foreach ([1,2,3] as $i) { if (!empty($photos[$i])) $photoCnt++; }

$buildings = fetch_buildings();
$rooms     = fetch_rooms_by_building($task['building_id']);

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors['csrf'] = 'Invalid CSRF token.';
    } elseif (isset($_POST['delete_task'])) {
        delete_task($taskId);
        log_event('task.delete', 'task', $taskId);
        redirect_with_message('tasks.php', 'Task deleted.', 'success');
    } else {
        $data = [
            'building_id' => (int)($_POST['building_id'] ?? 0),
            'room_id'     => (int)($_POST['room_id'] ?? 0),
            'title'       => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'priority'    => $_POST['priority'] ?? '',
            'assigned_to' => trim($_POST['assigned_to'] ?? ''),
            'status'      => $_POST['status'] ?? 'open',
            'due_date'    => $_POST['due_date'] ?? null,
        ];
        if (!validate_task_payload($data, $errors)) {
            // messages in $errors
        } elseif (!ensure_building_room_valid($data['building_id'], $data['room_id'])) {
            $errors['room_id'] = 'Selected room does not belong to building.';
        } else {
            update_task($taskId, $data);
            log_event('task.update', 'task', $taskId);
            $changedForNotify = [];
            if (array_key_exists('assigned_to', $data)) {
                $beforeAssigned = trim((string)($task['assigned_to'] ?? ''));
                $afterAssigned  = trim((string)($data['assigned_to'] ?? ''));
                if ($beforeAssigned !== $afterAssigned) {
                    $changedForNotify[] = 'assigned_to';
                }
            }
            if (array_key_exists('status', $data)) {
                $beforeStatus = (string)($task['status'] ?? '');
                $afterStatus  = (string)($data['status'] ?? '');
                if ($beforeStatus !== $afterStatus) {
                    $changedForNotify[] = 'status';
                }
            }
            if (array_key_exists('priority', $data)) {
                $beforePriority = (string)($task['priority'] ?? '');
                $afterPriority  = (string)($data['priority'] ?? '');
                if ($beforePriority !== $afterPriority) {
                    $changedForNotify[] = 'priority';
                }
            }
            if (array_key_exists('due_date', $data)) {
                $beforeDue = $task['due_date'] ? (string)$task['due_date'] : '';
                $afterDue  = trim((string)($data['due_date'] ?? ''));
                if ($beforeDue !== $afterDue) {
                    $changedForNotify[] = 'due_date';
                }
            }
            if (array_key_exists('title', $data)) {
                $beforeTitle = trim((string)($task['title'] ?? ''));
                $afterTitle  = trim((string)($data['title'] ?? ''));
                if ($beforeTitle !== $afterTitle) {
                    $changedForNotify[] = 'title';
                }
            }

            if ($changedForNotify) {
                try {
                    $updatedTask = fetch_task($taskId) ?: array_merge($task, $data);
                    task_notify_changes($taskId, $originalTask, $updatedTask, array_values(array_unique($changedForNotify)));
                } catch (Throwable $notifyErr) {
                    error_log('task_edit notify failed: ' . $notifyErr->getMessage());
                }
            }
            redirect_with_message('task_view.php?id=' . $taskId, 'Task updated successfully.');
        }
        if ($data['building_id']) {
            $rooms = fetch_rooms_by_building($data['building_id']);
        }
        $task = array_merge($task, $data);
    }
}

$title = 'Edit Task';
include __DIR__ . '/includes/header.php';
?>
<style>
/* ===== 2-column compact layout ===== */
.edit-layout {
  display: grid;
  grid-template-columns: 1.1fr 1fr;
  gap: 14px;
}
@media (max-width: 980px) {
  .edit-layout { grid-template-columns: 1fr; }
}

.card.card-compact { padding: 12px 14px; }
.card-compact .card-header { margin-bottom: 8px; }

/* Left form compactness */
.form-compact .grid-compact {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 8px;
}
.field { margin-bottom: 6px; display: flex; flex-direction: column; }
.field .lbl { font-size: 12px; opacity: .9; margin-bottom: 4px; }
.field input[type="text"],
.field select,
.field input[type="date"],
.field textarea { padding: 6px 8px; font-size: 14px; }
.field [data-help] { font-size: 12px; color: var(--muted,#6b7280); }

.col-span-12 { grid-column: span 12; }
.col-span-8  { grid-column: span 8; }
.col-span-6  { grid-column: span 6; }
.col-span-4  { grid-column: span 4; }
@media (max-width: 980px) {
  .col-span-8, .col-span-6, .col-span-4 { grid-column: span 12; }
}

.form-actions-compact { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; }
.btn-compact { padding: 6px 10px; font-size: 13px; }

/* Right side photos panel */
.photos-panel .panel-head {
  display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px;
}
.photos-panel .muted { color: #6b7280; }

/* Drag & drop slots */
.slot-grid {
  display: grid; gap: 10px;
  grid-template-columns: repeat(3, 1fr);
}
@media (max-width: 1280px) {
  .slot-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 700px) {
  .slot-grid { grid-template-columns: 1fr; }
}

.dz {
  position: relative;
  border: 1.5px dashed #c7d2fe;
  border-radius: 10px;
  min-height: 180px;
  background: #f8fafc;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
  transition: border-color .15s, background .15s, box-shadow .15s;
  cursor: pointer;
}
.dz:hover { background: #f3f6ff; }
.dz.dz-over {
  border-color: #4f46e5;
  background: #eef2ff;
  box-shadow: 0 0 0 3px rgba(79,70,229,.15) inset;
}
.dz img {
  position: absolute; inset: 0;
  width: 100%; height: 100%; object-fit: cover;
}
.dz .dz-hint {
  position: relative; z-index: 2;
  text-align: center; color: #394150; font-size: 12px;
  background: rgba(255,255,255,.75);
  padding: 8px 10px; border-radius: 6px;
}
.dz .dz-hint strong { display: block; font-size: 13px; margin-bottom: 2px; }

.slot-actions {
  position: absolute; right: 8px; bottom: 8px; z-index: 3;
  display: flex; gap: 6px;
}
.slot-actions .btn { padding: 4px 8px; font-size: 12px; }
.hidden-input { display: none; }
</style>

<section class="card card-compact">
  <div class="card-header" style="display:flex; gap:10px; align-items:center; justify-content:space-between;">
    <h1 style="margin:0;">Edit Task #<?php echo (int)$taskId; ?></h1>
    <div style="display:flex; gap:8px;">
      <a class="btn btn-compact" href="task_view.php?id=<?php echo (int)$taskId; ?>">View</a>
      <a class="btn danger btn-compact" href="#" onclick="if(confirm('Delete this task?')){ document.getElementById('deleteTaskForm').submit(); } return false;">Delete</a>
    </div>
  </div>

  <?php if (!empty($errors['csrf'])): ?>
    <div class="flash flash-error"><?php echo sanitize($errors['csrf']); ?></div>
  <?php endif; ?>

  <div class="edit-layout">
    <!-- LEFT: COMPACT FORM -->
    <form method="post" class="form-compact" novalidate>
      <div class="grid-compact">
        <label class="field col-span-6">
          <span class="lbl">Building</span>
          <select name="building_id" required data-room-source data-room-target="room-select">
            <option value="">Select building</option>
            <?php foreach ($buildings as $building): ?>
              <option value="<?php echo $building['id']; ?>" <?php echo ($task['building_id'] == $building['id']) ? 'selected' : ''; ?>>
                <?php echo sanitize($building['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['building_id'])): ?><span class="error small"><?php echo sanitize($errors['building_id']); ?></span><?php endif; ?>
        </label>

        <label class="field col-span-6">
          <span class="lbl">Room</span>
          <select name="room_id" id="room-select" required data-room-placeholder="Select room">
            <option value="">Select room</option>
            <?php foreach ($rooms as $room): ?>
              <option value="<?php echo $room['id']; ?>" <?php echo ($task['room_id'] == $room['id']) ? 'selected' : ''; ?>>
                <?php echo sanitize($room['room_number'] . ($room['label'] ? ' - ' . $room['label'] : '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['room_id'])): ?><span class="error small"><?php echo sanitize($errors['room_id']); ?></span><?php endif; ?>
        </label>

        <label class="field col-span-8">
          <span class="lbl">Title</span>
          <input type="text" name="title" required value="<?php echo sanitize($task['title']); ?>" placeholder="Brief, action-oriented title" autofocus>
          <?php if (!empty($errors['title'])): ?><span class="error small"><?php echo sanitize($errors['title']); ?></span><?php endif; ?>
        </label>

        <label class="field col-span-4">
          <span class="lbl">Assigned To</span>
          <input type="text" name="assigned_to" value="<?php echo sanitize($task['assigned_to'] ?? ''); ?>" placeholder="Name or team">
        </label>

        <label class="field col-span-4">
          <span class="lbl">Priority</span>
          <select name="priority">
            <?php foreach (get_priorities() as $priority): ?>
              <option value="<?php echo $priority; ?>" <?php echo ($task['priority'] === $priority) ? 'selected' : ''; ?>>
                <?php echo sanitize(priority_label($priority)); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['priority'])): ?><span class="error small"><?php echo sanitize($errors['priority']); ?></span><?php endif; ?>
        </label>

        <label class="field col-span-4">
          <span class="lbl">Status</span>
          <select name="status">
            <?php foreach (get_statuses() as $status): ?>
              <option value="<?php echo $status; ?>" <?php echo ($task['status'] === $status) ? 'selected' : ''; ?>>
                <?php echo sanitize(status_label($status)); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['status'])): ?><span class="error small"><?php echo sanitize($errors['status']); ?></span><?php endif; ?>
        </label>

        <label class="field col-span-4">
          <span class="lbl">Due Date</span>
          <input type="date" name="due_date" value="<?php echo sanitize($task['due_date'] ?? ''); ?>">
          <?php if (!empty($errors['due_date'])): ?><span class="error small"><?php echo sanitize($errors['due_date']); ?></span><?php endif; ?>
        </label>

        <label class="field col-span-12">
          <span class="lbl">Description</span>
          <textarea name="description" rows="5" placeholder="Short context, steps, or acceptance criteria"><?php echo sanitize($task['description'] ?? ''); ?></textarea>
        </label>
      </div>

      <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">

      <div class="form-actions-compact">
        <button class="btn primary btn-compact" type="submit">Save</button>
        <a class="btn btn-compact" href="task_view.php?id=<?php echo (int)$taskId; ?>">Cancel</a>
      </div>
    </form>

    <!-- RIGHT: DRAG & DROP PHOTO SLOTS -->
    <section class="photos-panel">
      <div class="panel-head">
        <h2 style="margin:0;">Photos</h2>
        <span class="muted small">(<?php echo (int)$photoCnt; ?>/3)</span>
      </div>

      <div class="slot-grid" id="photoSlots">
        <?php for ($i = 1; $i <= 3; $i++): ?>
          <?php $photo = $photos[$i] ?? null; $src = $photo ? photo_public_url($photo, 1200) : null; ?>
          <div class="dz" data-position="<?php echo $i; ?>" <?php echo $src ? 'data-has="1"' : ''; ?>>
            <?php if ($src): ?>
              <img src="<?php echo sanitize($src); ?>" alt="Photo <?php echo $i; ?>">
            <?php endif; ?>
            <div class="dz-hint">
              
              Drop image here or click
            </div>
            <div class="slot-actions">
              <button type="button" class="btn small btn-compact js-choose">Upload</button>
              <?php if ($photo): ?>
                <button type="button"
                        class="btn small danger btn-compact js-remove"
                        data-photo-id="<?php echo (int)$photo['id']; ?>">Remove</button>
              <?php endif; ?>
            </div>
            <input class="hidden-input" type="file" accept="image/*,image/heic,image/heif">
          </div>
        <?php endfor; ?>
      </div>

      <p class="muted small" style="margin-top:.5rem">JPG/PNG/WebP/HEIC up to 70&nbsp;MB. Drag files on a slot, or click it.</p>
    </section>
  </div>

  <!-- hidden delete form -->
  <form id="deleteTaskForm" method="post" style="display:none">
    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="delete_task" value="1">
  </form>
</section>

<script>
(() => {
  const TASK_ID = <?php echo (int)$taskId; ?>;
  const CSRF_NAME = <?php echo json_encode(CSRF_TOKEN_NAME); ?>;
  const CSRF_VAL  = <?php echo json_encode(csrf_token()); ?>;

  const UPLOAD_URL = '/upload.php';        // <-- adjust if different
  const REMOVE_URL = '/remove_photo.php';  // <-- adjust if different

  function uploadToSlot(slotEl, file) {
    if (!file) return;
    const pos = parseInt(slotEl.getAttribute('data-position'), 10) || 0;
    if (!pos) return;

    const fd = new FormData();
    fd.append('task_id', String(TASK_ID));
    fd.append('position', String(pos));
    fd.append('photo', file);
    fd.append(CSRF_NAME, CSRF_VAL);

    slotEl.classList.add('dz-over');
    fetch(UPLOAD_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(async (res) => {
        let ok = res.ok;
        try {
          const data = await res.clone().json();
          if (data && typeof data === 'object' && 'ok' in data) ok = !!data.ok;
        } catch (_) {}
        if (!ok) {
          const t = await res.text();
          throw new Error(t.slice(0, 400));
        }
        location.reload();
      })
      .catch(err => alert('Upload failed: ' + (err?.message || err)))
      .finally(() => slotEl.classList.remove('dz-over'));
  }

  function bindSlot(slotEl) {
    const fileInput = slotEl.querySelector('.hidden-input');

    // Click on slot or "Upload" button triggers file input
    slotEl.addEventListener('click', (e) => {
      if (e.target.closest('.js-remove')) return; // let remove handler work
      if (e.target.closest('.js-choose') || e.target === slotEl || e.target.classList.contains('dz-hint')) {
        fileInput?.click();
      }
    });

    fileInput?.addEventListener('change', () => {
      const f = fileInput.files?.[0];
      if (f) uploadToSlot(slotEl, f);
    });

    // Drag & drop
    ['dragenter','dragover'].forEach(ev =>
      slotEl.addEventListener(ev, (e) => { e.preventDefault(); e.stopPropagation(); slotEl.classList.add('dz-over'); })
    );
    ;['dragleave','dragend','drop'].forEach(ev =>
      slotEl.addEventListener(ev, (e) => { e.preventDefault(); e.stopPropagation(); if (ev !== 'drop') slotEl.classList.remove('dz-over'); })
    );
    slotEl.addEventListener('drop', (e) => {
      slotEl.classList.remove('dz-over');
      const file = e.dataTransfer?.files?.[0];
      if (file) uploadToSlot(slotEl, file);
    });

    // Remove
    slotEl.querySelector('.js-remove')?.addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      const photoId = btn.getAttribute('data-photo-id');
      if (!photoId) return;
      if (!confirm('Remove this photo?')) return;

      const body = new URLSearchParams({ photo_id: photoId });
      body.append(CSRF_NAME, CSRF_VAL);

      try {
        const res = await fetch(REMOVE_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body,
          credentials: 'same-origin'
        });
        let ok = res.ok;
        try {
          const data = await res.clone().json();
          if (data && typeof data === 'object' && 'ok' in data) ok = !!data.ok;
        } catch (_) {}
        if (!ok) {
          const t = await res.text();
          throw new Error(t.slice(0, 400));
        }
        location.reload();
      } catch (err) {
        alert('Remove failed: ' + (err?.message || err));
      }
    });
  }

  document.querySelectorAll('.dz').forEach(bindSlot);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>