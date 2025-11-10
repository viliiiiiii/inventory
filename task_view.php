<?php
require_once __DIR__ . '/helpers.php';
require_login();

$taskId = (int)($_GET['id'] ?? 0);
$task = fetch_task($taskId);
if (!$task) {
    redirect_with_message('tasks.php', 'Task not found.', 'error');
}

$photos = fetch_task_photos($taskId);              // 1..3 indexed usually
$photoCount = 0;
foreach ([1,2,3] as $i) { if (!empty($photos[$i])) $photoCount++; }

$pageTitle = 'Task #' . $taskId;
include __DIR__ . '/includes/header.php';
?>
<style>
/* Layout */
.card.card-compact { padding: 14px 16px; }
.card-compact .card-header { margin-bottom: 10px; }

/* Title row */
.task-title-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap;
}
.task-title-row h1 {
  margin: 0; font-size: 20px; font-weight: 700;
}

/* Details, compact two columns on desktop */
.details-simple {
  display: grid; gap: 8px; margin-top: 4px;
  grid-template-columns: repeat(2, minmax(220px, 1fr));
}
@media (max-width: 700px) { .details-simple { grid-template-columns: 1fr; } }
.details-simple p { margin: 0; font-size: 13px; color: #0f172a; }
.details-simple strong { color: #6b7280; font-weight: 600; }

/* Description box */
.desc-box {
  margin-top: 10px; padding: 10px 12px;
  border: 1px solid #eef1f6; border-radius: 8px; background: #fff;
}

/* Photo grid */
.photo-grid {
  display: grid; gap: 10px; margin-top: 8px;
  grid-template-columns: repeat(3, 1fr);
}
@media (max-width: 1000px) { .photo-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px)  { .photo-grid { grid-template-columns: 1fr; } }

.photo-grid img {
  width: 100%; height: 230px; object-fit: cover;
  border-radius: 10px; border: 1px solid #eef1f6;
  background: #f8fafc; cursor: zoom-in;
  transition: transform .12s ease, box-shadow .12s ease;
}
.photo-grid img:hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 26px rgba(15, 23, 42, .12);
}

/* Fullscreen overlay (also used if Fullscreen API not available) */
.fs {
  position: fixed; inset: 0; display: none;
  background: rgba(15, 23, 42, .92);
  align-items: center; justify-content: center; z-index: 1100;
}
.fs.open { display: flex; }
.fs img {
  max-width: 96vw; max-height: 96vh;
  box-shadow: 0 18px 60px rgba(0,0,0,.45);
  border-radius: 10px;
}
.fs .fs-close {
  position: fixed; top: 14px; right: 14px;
  width: 40px; height: 40px; border-radius: 999px;
  background: rgba(0,0,0,.35); color: #fff;
  border: 0; font-size: 22px; line-height: 40px;
  cursor: pointer;
}
.fs .fs-close:hover { background: rgba(0,0,0,.5); }
</style>

<section class="card card-compact">
  <div class="card-header">
    <div class="task-title-row">
      <h1>
        <?php
          $titleBits = ['Task #' . (int)$taskId];
          if (!empty($task['title'])) $titleBits[] = sanitize($task['title']);
          echo implode(' — ', $titleBits);
        ?>
      </h1>
      <div class="actions" style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn" href="tasks.php">Back to list</a>
        <a class="btn" href="export_pdf.php?selected=<?php echo (int)$taskId; ?>" target="_blank">Export PDF</a>
        <a class="btn" href="export_room_pdf.php?room_id=<?php echo (int)$task['room_id']; ?>" target="_blank">Export Room PDF</a>
      </div>
    </div>
  </div>

  <!-- Details -->
  <div class="details-simple">
    <p><strong>Building:</strong> <?php echo sanitize($task['building_name']); ?></p>
    <p><strong>Room:</strong> <?php echo sanitize($task['room_number'] . ($task['room_label'] ? ' - ' . $task['room_label'] : '')); ?></p>
    <p><strong>Priority:</strong>
      <span class="badge <?php echo priority_class($task['priority']); ?>">
        <?php echo sanitize(priority_label($task['priority'])); ?>
      </span>
    </p>
    <p><strong>Status:</strong>
      <span class="badge <?php echo status_class($task['status']); ?>">
        <?php echo sanitize(status_label($task['status'])); ?>
      </span>
    </p>
    <p><strong>Assigned To:</strong> <?php echo sanitize($task['assigned_to'] ?? ''); ?></p>
    <p><strong>Due Date:</strong> <?php echo $task['due_date'] ? sanitize($task['due_date']) : '—'; ?></p>
    <p><strong>Created:</strong> <?php echo sanitize($task['created_at']); ?></p>
    <p><strong>Updated:</strong> <?php echo $task['updated_at'] ? sanitize($task['updated_at']) : '—'; ?></p>
  </div>

  <!-- Description -->
  <div class="desc-box">
    <strong style="color:#6b7280;">Description</strong>
    <div><?php echo nl2br(sanitize($task['description'] ?? 'No description.')); ?></div>
  </div>
</section>

<section class="card card-compact">
  <div class="card-header" style="margin-bottom:6px;">
    <h2 style="margin:0;">Photos <span class="muted small">(<?php echo (int)$photoCount; ?>/3)</span></h2>
  </div>

  <?php if ($photoCount > 0): ?>
    <div class="photo-grid" id="photoGrid">
      <?php foreach ([1,2,3] as $i): if (empty($photos[$i])) continue;
        // Smaller thumbnail + full-size source
        $thumb = photo_public_url($photos[$i], 1200);
        $full  = photo_public_url($photos[$i], 2400);
      ?>
        <img
          class="js-fs"
          src="<?php echo sanitize($thumb); ?>"
          data-full="<?php echo sanitize($full); ?>"
          alt="Task photo <?php echo (int)$i; ?>">
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted">No photos uploaded.</p>
  <?php endif; ?>
</section>

<!-- Fullscreen viewer -->
<div class="fs" id="fsOverlay" aria-hidden="true">
  <button class="fs-close" id="fsClose" aria-label="Close">&times;</button>
  <img id="fsImg" alt="">
</div>

<script>
(() => {
  const overlay = document.getElementById('fsOverlay');
  const fsImg   = document.getElementById('fsImg');
  const fsClose = document.getElementById('fsClose');

  function openFs(src) {
    fsImg.src = src;
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');

    // Try to enter real fullscreen; overlay works even if this fails.
    if (overlay.requestFullscreen) {
      overlay.requestFullscreen().catch(() => {});
    }
  }
  function closeFs() {
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    fsImg.src = '';
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => {});
    }
  }

  document.addEventListener('click', (e) => {
    const img = e.target.closest('img.js-fs');
    if (img) {
      const src = img.dataset.full || img.src;
      openFs(src);
      return;
    }
    if (e.target === overlay) {
      closeFs();
    }
  });

  fsClose.addEventListener('click', closeFs);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeFs();
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
