<?php
if (!isset($title)) { $title = APP_TITLE; }
$roleKey = current_user_role_key();
$me = current_user();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/** Small helper */
function bc_s($s){ return sanitize($s); }

/** Build breadcrumbs for known routes */
function build_breadcrumbs(string $path): array {
  $crumbs = [
    ['label' => 'Dashboard', 'href' => '/index.php'],
  ];

  // Normalize
  $script = basename($path);
  $dir    = trim(dirname($path), '/');

  // Tasks
  if ($script === 'tasks.php' || preg_match('#^task_#', $script)) {
    $crumbs[] = ['label' => 'Tasks', 'href' => '/tasks.php'];

    if ($script === 'task_view.php' && isset($_GET['id'])) {
      $id = (int)$_GET['id'];
      $crumbs[] = ['label' => "Task #{$id}", 'href' => null];
    } elseif ($script === 'task_edit.php') {
      $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
      if ($id) {
        $crumbs[] = ['label' => "Task #{$id}", 'href' => "/task_view.php?id={$id}"];
        $crumbs[] = ['label' => 'Edit', 'href' => null];
      } else {
        $crumbs[] = ['label' => 'New Task', 'href' => null];
      }
    }
  }

  // Rooms
  if ($script === 'rooms.php' || preg_match('#^rooms(/|$)#', $path)) {
    $crumbs[] = ['label' => 'Rooms', 'href' => '/rooms.php'];
  }

  // Inventory
  if ($script === 'inventory.php' || preg_match('#^inventory(/|$)#', $path)) {
    $crumbs[] = ['label' => 'Inventory', 'href' => '/inventory.php'];
  }

  // Notes
  if (str_starts_with($dir, 'notes') || $dir === 'notes') {
    $crumbs[] = ['label' => 'Notes', 'href' => '/notes/index.php'];
    if ($script === 'view.php' && isset($_GET['id'])) {
      $id = (int)$_GET['id'];
      $crumbs[] = ['label' => "Note #{$id}", 'href' => null];
    } elseif ($script === 'edit.php') {
      $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
      if ($id) {
        $crumbs[] = ['label' => "Note #{$id}", 'href' => "/notes/view.php?id={$id}"];
        $crumbs[] = ['label' => 'Edit', 'href' => null];
      } else {
        $crumbs[] = ['label' => 'New Note', 'href' => null];
      }
    } elseif ($script === 'index.php') {
      // just "Notes" already added
    }
  }

  // Account
  if (str_starts_with($dir, 'account') || $dir === 'account') {
    $crumbs[] = ['label' => 'Account', 'href' => '/account/profile.php'];
    if ($script === 'profile.php') {
      $crumbs[] = ['label' => 'Profile', 'href' => null];
    }
  }

  // Admin
  if (str_starts_with($dir, 'admin') || $dir === 'admin') {
    $crumbs[] = ['label' => 'Admin', 'href' => '/admin/activity.php'];
    if ($script === 'users.php')      $crumbs[] = ['label' => 'Users', 'href' => null];
    if ($script === 'sectors.php')    $crumbs[] = ['label' => 'Sectors', 'href' => null];
    if ($script === 'activity.php')   $crumbs[] = ['label' => 'Activity', 'href' => null];
    if ($script === 'settings.php')   $crumbs[] = ['label' => 'Settings', 'href' => null];
  }

  // If we only have Dashboard and we’re on Dashboard, keep it as one crumb
  // Otherwise, try to add a fall-back current page from $title if needed
  if (count($crumbs) === 1 && ($path !== '/' && $path !== '/index.php')) {
    $label = $script ?: 'Page';
    $crumbs[] = ['label' => $label, 'href' => null];
  }

  return $crumbs;
}

$breadcrumbs = build_breadcrumbs($path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo bc_s($title); ?> - <?php echo bc_s(APP_TITLE); ?></title>
  <link rel="stylesheet" href="/assets/css/app.css?v=pro-1.1">
  <link rel="icon" href="/assets/favicon.ico">
  <meta name="theme-color" content="#f6f9ff">
  <script type="module" src="/assets/js/app.js?v=pro-1.1" defer></script>
  <style>
    /* Lightweight breadcrumb styling (move to app.css later if you want) */
    .breadcrumbs { margin: 10px 0 14px; font-size: 13px; color: #475569; }
    .breadcrumbs ol {
      list-style:none; padding:0; margin:0; display:flex; flex-wrap:wrap; gap:6px;
      align-items:center;
    }
    .breadcrumbs li { display:flex; align-items:center; gap:6px; }
    .breadcrumbs a {
      color:#1d4ed8; text-decoration:none; border-bottom:1px solid #93c5fd;
    }
    .breadcrumbs li + li::before {
      content:"/"; color:#94a3b8;
    }
    .breadcrumbs [aria-current="page"] {
      color:#0f172a; font-weight:600; border-bottom: none;
    }
  </style>
</head>
<body
  data-notif-stream="/notifications/stream.php"
  data-notif-poll="/notifications/api.php?action=unread_count">
<header class="navbar">
  <div class="navbar__inner container">
    <a href="/index.php" class="brand" aria-label="<?php echo bc_s(APP_TITLE); ?>">
      <span class="brand__spark" aria-hidden="true"></span>
      <span class="brand__mark">
        <img src="/assets/logo.png" alt="" class="brand__logo">
      </span>
      <span class="brand__text">
        <span class="brand__title"><?php echo bc_s(APP_TITLE); ?></span>
        <span class="brand__subtitle">Field Ops Hub</span>
      </span>
    </a>

    <!-- Mobile toggle -->
    <button id="navToggle" class="nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="navPanel">
      <span class="nav-toggle__icon" aria-hidden="true">
        <span class="nav-toggle__line"></span>
        <span class="nav-toggle__line"></span>
        <span class="nav-toggle__line"></span>
      </span>
      <span class="nav-toggle__label">Menu</span>
    </button>

    <!-- Collapsible panel -->
    <div id="navPanel" class="nav-panel" data-role="nav-panel">
      <nav class="nav-panel__primary" aria-label="Main">
        <ul class="nav">
          <li>
            <a class="nav__link<?= ($path === '/' || $path === '/index.php') ? ' is-active' : '' ?>"
               <?= ($path === '/' || $path === '/index.php') ? 'aria-current="page"' : '' ?>
               href="/index.php"><span class="nav__label">Dashboard</span></a>
          </li>

          <li>
            <a class="nav__link<?= preg_match('#^/(tasks\\.php|task_)#', $path) ? ' is-active' : '' ?>"
               <?= preg_match('#^/(tasks\\.php|task_)#', $path) ? 'aria-current="page"' : '' ?>
               href="/tasks.php"><span class="nav__label">Tasks</span></a>
          </li>

          <li>
            <a class="nav__link<?= preg_match('#^/rooms(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
               <?= preg_match('#^/rooms(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
               href="/rooms.php"><span class="nav__label">Rooms</span></a>
          </li>

          <li>
            <a class="nav__link<?= preg_match('#^/inventory(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
               <?= preg_match('#^/inventory(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
               href="/inventory.php"><span class="nav__label">Inventory</span></a>
          </li>

          <li>
            <a class="nav__link<?= preg_match('#^/notes(/|$)#', $path) ? ' is-active' : '' ?>"
               <?= preg_match('#^/notes(/|$)#', $path) ? 'aria-current="page"' : '' ?>
               href="/notes/index.php"><span class="nav__label">Notes</span></a>
          </li>
          <?php if ($roleKey === 'root'): ?>
            <li class="nav__sep" aria-hidden="true"></li>

            <li>
              <a class="nav__link<?= preg_match('#^/admin/users(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
                 <?= preg_match('#^/admin/users(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
                 href="/admin/users.php"><span class="nav__label">Users</span></a>
            </li>

            <li>
              <a class="nav__link<?= preg_match('#^/admin/sectors(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
                 <?= preg_match('#^/admin/sectors(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
                 href="/admin/sectors.php"><span class="nav__label">Sectors</span></a>
            </li>

            <li>
              <a class="nav__link<?= preg_match('#^/admin/activity(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
                 <?= preg_match('#^/admin/activity(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
                 href="/admin/activity.php"><span class="nav__label">Activity</span></a>
            </li>
          <?php endif; ?>
        </ul>
      </nav>

      <div class="nav-panel__actions">
        <a class="nav__bell" href="/notifications/index.php" aria-label="Open notifications">
          <span class="nav__bell-icon" aria-hidden="true">🔔</span>
          <span id="notifDot" class="nav__bell-dot" aria-hidden="true"></span>
        </a>

        <button type="button" class="nav__command" data-command-open>
          <span class="nav__command-icon" aria-hidden="true">⌘</span>
          <span class="nav__command-label">Quick Find</span>
          <span class="nav__command-hint">Ctrl&nbsp;+&nbsp;K</span>
        </button>

        <?php if ($me): ?>
          <a class="nav-user__email nav-user__profile-link"
             href="/account/profile.php"
             title="Open my profile">
            <?php echo bc_s($me['email'] ?? ''); ?>
          </a>
          <a class="btn small" href="/logout.php">Logout</a>
        <?php else: ?>
          <a class="btn small" href="/login.php">Login</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>

<div id="commandPalette" class="command-palette" hidden aria-hidden="true">
  <div class="command-palette__backdrop" data-command-close></div>
  <div class="command-palette__panel" role="dialog" aria-modal="true" aria-labelledby="commandPaletteLabel">
    <div class="command-palette__search">
      <label id="commandPaletteLabel" class="sr-only" for="commandPaletteInput">Quick find</label>
      <input id="commandPaletteInput" type="search" name="command" autocomplete="off" placeholder="Search destinations or type #ID to open a task">
      <div class="command-palette__shortcut" aria-hidden="true">
        <kbd>Ctrl</kbd>
        <span>+</span>
        <kbd>K</kbd>
      </div>
    </div>
    <ul id="commandPaletteResults" class="command-palette__results" role="listbox"></ul>
    <footer class="command-palette__hint">
      <p>Use ↑↓ to navigate, Enter to open. Try typing <strong>#42</strong> to jump to a task.</p>
    </footer>
  </div>
</div>

<div id="toastStack" class="toast-stack" aria-live="polite" aria-atomic="false"></div>

<main class="container" id="app-main">
  <!-- Breadcrumbs -->
  <?php if (!empty($breadcrumbs) && is_array($breadcrumbs)): ?>
    <nav class="breadcrumbs" aria-label="Breadcrumb">
      <ol>
        <?php
          $lastIdx = count($breadcrumbs) - 1;
          foreach ($breadcrumbs as $i => $c) {
            $label = bc_s($c['label'] ?? '');
            $href  = $c['href'] ?? null;
            if ($i === $lastIdx || !$href) {
              echo '<li><span aria-current="page">'.$label.'</span></li>';
            } else {
              echo '<li><a href="'.bc_s($href).'">'.$label.'</a></li>';
            }
          }
        ?>
      </ol>
    </nav>
  <?php endif; ?>

  <?php flash_message(); ?>