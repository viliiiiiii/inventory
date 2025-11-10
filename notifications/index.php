<?php
// notifications/index.php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$me = current_user();
$userId = (int)$me['id'];
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;
$list   = notif_list($userId, $per, ($page-1)*$per);
$unreadTotal = notif_unread_count($userId);

$typeLabels = [
  'task.assigned'   => 'Task assignment',
  'task.unassigned' => 'Task reassigned',
  'task.updated'    => 'Task updated',
  'note.shared'     => 'Note shared',
  'note.comment'    => 'New note comment',
];

$typeIcons = [
  'task.assigned'   => '🧭',
  'task.unassigned' => '🔁',
  'task.updated'    => '🛠️',
  'note.shared'     => '🗂️',
  'note.comment'    => '💬',
];

if (!function_exists('notif_relative_time')) {
    function notif_relative_time(?string $timestamp): string {
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
        return $dt->format('M j, Y');
    }
}

$title = 'Notifications';
include __DIR__ . '/../includes/header.php';
?>
<section class="card card--glass">
  <div class="card-header card-header--stack">
    <div>
      <h1>Notifications</h1>
      <p class="muted">Live updates for tasks, notes, and shares.</p>
    </div>
    <div class="card-header__meta">
      <div class="unread-pill" data-unread-wrapper>
        <span class="unread-pill__count" data-unread-count><?php echo (int)$unreadTotal; ?></span>
        <span class="unread-pill__label">Unread</span>
      </div>
      <form method="post" action="/notifications/api.php" class="inline" data-action="mark-all">
        <input type="hidden" name="action" value="mark_all_read">
        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
        <button class="btn ghost small" type="submit" <?php echo $unreadTotal ? '' : 'disabled'; ?>>Mark all read</button>
      </form>
    </div>
  </div>

  <?php if (!$list): ?>
    <div class="notif-empty" role="status">
      <div class="notif-empty__icon">✨</div>
      <h2>No notifications yet</h2>
      <p class="muted">We’ll keep this space updated when there’s activity you should know about.</p>
    </div>
  <?php else: ?>
    <div class="notif-feed" data-feed>
      <?php foreach ($list as $n):
        $typeKey = (string)($n['type'] ?? 'general');
        $label   = $typeLabels[$typeKey] ?? ucwords(str_replace(['.', '_'], ' ', $typeKey));
        $icon    = $typeIcons[$typeKey] ?? '🔔';
        $timeRel = notif_relative_time($n['created_at'] ?? null);
        $timeAbs = $n['created_at'] ?? '';
        $isUnread = empty($n['is_read']);
      ?>
        <article class="notif<?php echo $isUnread ? ' is-unread' : ''; ?>" data-id="<?php echo (int)$n['id']; ?>" data-type="<?php echo sanitize($typeKey); ?>">
          <div class="notif__icon" aria-hidden="true"><?php echo $icon; ?></div>
          <div class="notif__body">
            <header class="notif__header">
              <div class="notif__headline">
                <span class="notif__label"><?php echo sanitize($n['title'] ?: $label); ?></span>
                <?php if ($isUnread): ?><span class="badge badge--accent" data-pill="new">New</span><?php endif; ?>
              </div>
              <?php if ($timeAbs): ?>
                <time class="notif__time" datetime="<?php echo sanitize($timeAbs); ?>"><?php echo sanitize($timeRel ?: $timeAbs); ?></time>
              <?php endif; ?>
            </header>
            <?php if (!empty($n['body'])): ?>
              <p class="notif__text"><?php echo nl2br(sanitize($n['body'])); ?></p>
            <?php endif; ?>
            <footer class="notif__actions">
              <?php if (!empty($n['url'])): ?>
                <a class="btn ghost small" href="<?php echo sanitize($n['url']); ?>">Open</a>
              <?php endif; ?>
              <?php if ($isUnread): ?>
                <form method="post" action="/notifications/api.php" class="notif__mark" data-action="mark-read">
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                  <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                  <button class="btn ghost small" type="submit">Mark read</button>
                </form>
              <?php endif; ?>
            </footer>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const countNode = document.querySelector('[data-unread-count]');
  const wrapper = document.querySelector('[data-unread-wrapper]');
  const dot = document.getElementById('notifDot');
  const markAllForm = document.querySelector('form[data-action="mark-all"]');
  const markAllBtn = markAllForm ? markAllForm.querySelector('button[type="submit"]') : null;

  function renderCount(value) {
    const count = Math.max(0, Number(value || 0));
    if (countNode) {
      countNode.textContent = count;
    }
    if (wrapper) {
      wrapper.classList.toggle('is-zero', count === 0);
    }
    if (dot) {
      if (count > 0) {
        dot.textContent = count;
        dot.style.display = 'inline-block';
      } else {
        dot.style.display = 'none';
      }
    }
    if (markAllBtn) {
      markAllBtn.disabled = count === 0;
    }
  }

  async function postForm(form) {
    const data = new FormData(form);
    const res = await fetch(form.action, {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    });
    if (!res.ok) {
      throw new Error('Request failed');
    }
    return res.json();
  }

  function handleMarkRead(form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        const json = await postForm(form);
        if (!json || !json.ok) return;
        const parent = form.closest('.notif');
        if (parent) {
          parent.classList.remove('is-unread');
          const pill = parent.querySelector('[data-pill="new"]');
          if (pill) pill.remove();
        }
        renderCount(json.count);
      } catch (err) {
        console.error(err);
        form.submit(); // fallback to default behaviour
      }
    });
  }

  document.querySelectorAll('form[data-action="mark-read"]').forEach(handleMarkRead);

  if (markAllForm) {
    markAllForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!confirm('Mark all notifications as read?')) {
        return;
      }
      try {
        const json = await postForm(markAllForm);
        if (!json || !json.ok) return;
        document.querySelectorAll('.notif.is-unread').forEach((item) => {
          item.classList.remove('is-unread');
          const pill = item.querySelector('[data-pill="new"]');
          if (pill) pill.remove();
        });
        renderCount(json.count);
      } catch (err) {
        console.error(err);
        markAllForm.submit();
      }
    });
  }

  renderCount(countNode ? countNode.textContent : 0);
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>