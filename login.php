<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// Already logged in? Bounce to home (or ?next)
if (current_user()) {
    $next = $_GET['next'] ?? '';
    $dest = '/index.php';
    if ($next) {
        // only allow internal, relative paths
        $p = parse_url($next, PHP_URL_PATH);
        if (is_string($p) && str_starts_with($p, '/')) {
            $dest = $p . (($_SERVER['QUERY_STRING'] ?? '') && !str_contains($p, '?') ? '' : '');
        }
    }
    header('Location: ' . $dest);
    exit;
}

/* ----------------------
   Simple rate limiting
   ---------------------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bucketKey = 'login_rl_' . $ip;
if (!isset($_SESSION)) { session_start(); } // should already be started in helpers, but be safe

$bucket = $_SESSION[$bucketKey] ?? ['count' => 0, 'until' => 0];
$now    = time();

$cooldownActive = ($bucket['until'] ?? 0) > $now;
$timeLeft       = max(0, (int)($bucket['until'] ?? 0) - $now);

$error = '';
$next  = (string)($_GET['next'] ?? ($_POST['next'] ?? ''));

// Preserve entered email between attempts
$prefillEmail = (string)($_POST['email'] ?? '');

// If too many recent failures, short-circuit
if (is_post() && $cooldownActive) {
    $mins = ceil($timeLeft / 60);
    $error = "Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.';
} elseif (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $error = 'Invalid CSRF token.';
    } else {
        // Honeypot: real users leave this blank
        $hp = trim((string)($_POST['company'] ?? ''));
        if ($hp !== '') {
            // Treat as success to bots, but do nothing.
            $error = 'Invalid credentials.'; // generic
        } else {
            $email    = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($email === '' || $password === '') {
                $error = 'Email and password are required.';
            } else {
                // Attempt login
                if (!attempt_login($email, $password)) {
                    // bump bucket
                    $bucket['count'] = (int)($bucket['count'] ?? 0) + 1;
                    if ($bucket['count'] >= 5) {
                        $bucket['until'] = $now + 5 * 60; // 5 minutes
                        $bucket['count'] = 0;             // reset count after applying cooldown
                    }
                    $_SESSION[$bucketKey] = $bucket;
                    $error = 'Invalid credentials.';
                } else {
                    // success: clear rate limit
                    unset($_SESSION[$bucketKey]);

                    // Safe redirect: only allow relative internal path
                    $dest = '/index.php';
                    if ($next) {
                        $path = parse_url($next, PHP_URL_PATH);
                        $qs   = parse_url($next, PHP_URL_QUERY);
                        if (is_string($path) && str_starts_with($path, '/')) {
                            $dest = $path . ($qs ? ('?' . $qs) : '');
                        }
                    }
                    redirect_with_message($dest, 'Welcome back!');
                }
            }
        }
    }
}

$title = 'Login';
include __DIR__ . '/includes/header.php';
?>
<!-- Prevent indexing -->
<meta name="robots" content="noindex, nofollow">

<style>
.auth-wrapper {
  min-height: calc(100dvh - 120px);
  display: grid;
  place-items: center;
  padding: 24px 16px;
}
.auth-card {
  width: min(440px, 100%);
  padding: 20px 18px;
}
.auth-card h1 { margin: 0 0 8px; font-size: 20px; }
.auth-sub { margin: 0 0 16px; color: #6b7280; font-size: 13px; }

.form-row { display: grid; gap: 6px; margin-bottom: 12px; }
.form-row label { font-weight: 600; color: #0f172a; font-size: 13px; }
.input {
  width: 100%; padding: 10px 12px; border-radius: 10px;
  border: 1px solid #e6e9ef; background: #fff;
}
.input:focus { outline: none; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(147, 197, 253, .25); }

.pw-wrap { position: relative; }
.pw-toggle {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  border: 0; background: transparent; cursor: pointer;
  color: #6b7280; padding: 6px; border-radius: 8px;
}
.pw-toggle:hover { color: #374151; background: #f3f4f6; }

.actions { display: flex; gap: 10px; align-items: center; justify-content: space-between; margin-top: 6px; }
.btn.wide { width: 100%; }

.small-links { display: flex; justify-content: space-between; margin-top: 8px; font-size: 12px; }
.small-links a { color: #1d4ed8; text-decoration: none; }
.small-links a:hover { text-decoration: underline; }

/* hide honeypot */
.hp { position: absolute !important; left: -10000px !important; width: 1px; height: 1px; overflow: hidden; }
</style>

<div class="auth-wrapper">
  <form method="post" class="card auth-card" action="/login.php<?php echo $next ? ('?next=' . urlencode($next)) : ''; ?>" novalidate>
    <h1>Punch List Login</h1>
    <p class="auth-sub">Sign in to continue.</p>

    <?php if ($error): ?>
      <div class="flash flash-error" role="alert"><?php echo sanitize($error); ?></div>
    <?php elseif (!empty($_GET['msg'])): ?>
      <div class="flash"><?php echo sanitize((string)$_GET['msg']); ?></div>
    <?php endif; ?>

    <div class="form-row">
      <label for="email">Email</label>
      <input
        id="email"
        class="input"
        type="email"
        name="email"
        required
        autocomplete="username"
        autofocus
        value="<?php echo sanitize($prefillEmail); ?>">
    </div>

    <div class="form-row">
      <label for="password">Password</label>
      <div class="pw-wrap">
        <input
          id="password"
          class="input"
          type="password"
          name="password"
          required
          autocomplete="current-password">
        <button class="pw-toggle" type="button" aria-label="Show password" title="Show password" id="pwToggle">👁</button>
      </div>
    </div>

    <!-- Honeypot (leave empty) -->
    <div class="hp" aria-hidden="true">
      <label for="company">Company</label>
      <input id="company" type="text" name="company" tabindex="-1" autocomplete="off">
    </div>

    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
    <?php if ($next): ?>
      <input type="hidden" name="next" value="<?php echo sanitize($next); ?>">
    <?php endif; ?>

    <div class="actions">
      <button type="submit" class="btn primary wide">Login</button>
    </div>

    <div class="small-links">
      <span></span>
      <span class="muted">Need help? Contact your admin.</span>
    </div>
  </form>
</div>

<script>
(function(){
  const t = document.getElementById('pwToggle');
  const p = document.getElementById('password');
  if (!t || !p) return;
  t.addEventListener('click', () => {
    const show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    t.textContent = show ? '🙈' : '👁';
    t.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
