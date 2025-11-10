<?php
// admin_users.php (schema-flexible)
// Access allowed ONLY to admin@example.com. No DB role checks.
//
// Works with users table shapes like:
//  - id, email, password_hash
//  - id, email, password
//  - optional: name, created_at (auto-detected)

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_login();

// -------- Access control: email check only --------
$me = current_user();
if (!$me || strtolower($me['email'] ?? '') !== 'admin@example.com') {
    http_response_code(403);
    echo "<h1>403 Forbidden</h1><p>You do not have access to this page.</p>";
    exit;
}

// -------- Helpers --------
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post(string $k, $d = '') { return $_POST[$k] ?? $d; }
if (!defined('CSRF_TOKEN_NAME')) define('CSRF_TOKEN_NAME', 'csrf_token');

// Safe header/footer includes (optional files)
function safe_header(string $title=''): void {
    if ($title !== '') $GLOBALS['title'] = $title;
    $p = __DIR__ . '/includes/header.php';
    if (is_file($p)) include $p;
}
function safe_footer(): void {
    $p = __DIR__ . '/includes/footer.php';
    if (is_file($p)) include $p;
}

$pdo = get_pdo();

// -------- Discover columns --------
$columns = [];
try {
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Users table error</h1><pre>".h($e->getMessage())."</pre>";
    exit;
}

$hasName       = in_array('name', $columns, true);
$hasCreatedAt  = in_array('created_at', $columns, true);
$pwdCol        = in_array('password_hash', $columns, true) ? 'password_hash'
                : (in_array('password', $columns, true) ? 'password' : null);

if ($pwdCol === null) {
    http_response_code(500);
    echo "<h1>Schema issue</h1><p>Neither <code>password_hash</code> nor <code>password</code> column exists in <code>users</code> table.</p>";
    exit;
}

// -------- Actions --------
$flash = [];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $email = trim((string)post('email'));
                $name  = trim((string)post('name'));
                $pwd   = (string)post('password');

                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Valid email is required.';
                }
                if (strlen($pwd) < 6) {
                    $errors[] = 'Password must be at least 6 characters.';
                }

                if (!$errors) {
                    $hash = password_hash($pwd, PASSWORD_DEFAULT);

                    // Build INSERT dynamically based on available columns
                    $cols = ['email', $pwdCol];
                    $vals = [$email, $hash];
                    $ph   = ['?', '?'];

                    if ($hasName) {
                        $cols[] = 'name';
                        $vals[] = ($name !== '' ? $name : null);
                        $ph[]   = '?';
                    }
                    if ($hasCreatedAt) {
                        $cols[] = 'created_at';
                        $ph[]   = 'NOW()';
                        // no bound value for NOW()
                    }

                    $colSql = implode(', ', $cols);
                    $phSql  = implode(', ', $ph);
                    $sql    = "INSERT INTO users ($colSql) VALUES ($phSql)";
                    $stmt   = $pdo->prepare($sql);

                    // Bind only the placeholders that are '?'
                    $bindVals = [];
                    foreach ($ph as $i => $p) {
                        if ($p === '?') $bindVals[] = $vals[$i];
                    }

                    $stmt->execute($bindVals);
                    $flash[] = "User “".h($email)."” created.";
                }

            } elseif ($action === 'delete') {
                $uid = (int)post('user_id', 0);
                if ($uid <= 0) { $errors[] = 'Invalid user id.'; }
                if ($uid === (int)($me['id'] ?? 0)) { $errors[] = "You can't delete your own account."; }
                if (!$errors) {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
                    $stmt->execute([$uid]);
                    $flash[] = "User deleted.";
                }

            } elseif ($action === 'reset_password') {
                $uid = (int)post('user_id', 0);
                $pwd = (string)post('new_password', '');
                if ($uid <= 0) { $errors[] = 'Invalid user id.'; }
                if (strlen($pwd) < 6) { $errors[] = 'Password must be at least 6 characters.'; }
                if (!$errors) {
                    $hash = password_hash($pwd, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET {$pwdCol}=? WHERE id=?");
                    $stmt->execute([$hash, $uid]);
                    $flash[] = "Password updated.";
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// -------- Fetch users list (dynamic SELECT) --------
$selectCols = ['id', 'email'];
if ($hasName)      $selectCols[] = 'name';
if ($hasCreatedAt) $selectCols[] = 'created_at';
$selectSql = implode(', ', $selectCols);

try {
    $users = $pdo->query("SELECT $selectSql FROM users ORDER BY ".($hasCreatedAt ? 'created_at' : 'id')." DESC")->fetchAll();
} catch (Throwable $e) {
    $errors[] = "Failed to fetch users: ".$e->getMessage();
    $users = [];
}

// -------- Render --------
safe_header('User Management');
?>
<section class="card">
  <div class="card-header">
    <div class="title">User Management</div>
    <div class="meta">Only <strong>admin@example.com</strong> can access this page.</div>
  </div>

  <?php foreach ($flash as $m): ?>
    <div class="flash flash-success"><?php echo $m; ?></div>
  <?php endforeach; ?>
  <?php if ($errors): ?>
    <div class="flash flash-error">
      <?php foreach ($errors as $e): ?>
        <div><?php echo h($e); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="grid two">
    <div class="card sub">
      <div class="card-header"><div class="title">Create User</div></div>
      <form method="post" autocomplete="off">
        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="create">

        <label>Email
          <input type="email" name="email" required>
        </label>

        <?php if ($hasName): ?>
        <label>Name (optional)
          <input type="text" name="name">
        </label>
        <?php endif; ?>

        <label>Password
          <input type="password" name="password" minlength="6" required>
        </label>

        <div class="card-footer">
          <button class="btn primary" type="submit">Create</button>
        </div>
      </form>
    </div>

    <div class="card sub">
      <div class="card-header"><div class="title">Reset Password</div></div>
      <form method="post" autocomplete="off">
        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="reset_password">
        <label>User
          <select name="user_id" required>
            <option value="">Select user…</option>
            <?php foreach ($users as $u): ?>
              <option value="<?php echo (int)$u['id']; ?>">
                <?php
                  $label = $u['email'];
                  if ($hasName && !empty($u['name'])) $label .= " (".$u['name'].")";
                  echo h($label);
                ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>New Password
          <input type="password" name="new_password" minlength="6" required>
        </label>
        <div class="card-footer">
          <button class="btn primary" type="submit">Update</button>
        </div>
      </form>
    </div>
  </div>
</section>

<section class="card">
  <div class="card-header"><div class="title">All Users</div></div>
  <div class="table">
    <table>
      <thead>
        <tr>
          <th style="white-space:nowrap;">ID</th>
          <th>Email</th>
          <?php if ($hasName): ?><th>Name</th><?php endif; ?>
          <?php if ($hasCreatedAt): ?><th>Created</th><?php endif; ?>
          <th style="width:220px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td data-label="ID"><?php echo (int)$u['id']; ?></td>
            <td data-label="Email"><?php echo h($u['email']); ?></td>
            <?php if ($hasName): ?>
              <td data-label="Name"><?php echo h($u['name'] ?? ''); ?></td>
            <?php endif; ?>
            <?php if ($hasCreatedAt): ?>
              <td data-label="Created"><?php echo h((string)$u['created_at']); ?></td>
            <?php endif; ?>
            <td data-label="Actions">
              <form method="post" onsubmit="return confirm('Delete this user?');" style="display:inline;">
                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                <button class="btn small danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
          <tr><td colspan="<?php echo 3 + (int)$hasName + (int)$hasCreatedAt; ?>" class="muted">No users found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php safe_footer();
