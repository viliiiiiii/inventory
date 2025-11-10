<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_login(); // must be signed in to change your password

$errors = [];
$ok = '';

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors['csrf'] = 'Invalid CSRF token.';
    }

    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '') {
        $errors['current_password'] = 'Current password is required.';
    }
    if ($new === '') {
        $errors['new_password'] = 'New password is required.';
    } elseif (strlen($new) < 8) {
        $errors['new_password'] = 'New password must be at least 8 characters.';
    }
    if ($confirm === '' || $new !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    $user = current_user(); // CORE-first user record (contains pass_hash)
    if ($user && empty($errors)) {
        $hash = (string)($user['pass_hash'] ?? '');
        if ($hash !== '' && !password_verify($current, $hash)) {
            $errors['current_password'] = 'Current password is incorrect.';
        }
    }

    if (empty($errors) && $user) {
        $pdo  = get_pdo('core');
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET pass_hash=:h, updated_at=NOW() WHERE id=:id');
        $stmt->execute([':h' => $hash, ':id' => (int)$user['id']]);

        log_event('password_change', 'user', (int)$user['id']);
        $ok = 'Password updated successfully.';
    }
}

$title = 'Change Password';
include __DIR__ . '/includes/header.php';
?>
<div class="container">
    <?php if ($ok): ?>
        <div class="flash flash-success"><?php echo sanitize($ok); ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['csrf'])): ?>
        <div class="flash flash-error"><?php echo sanitize($errors['csrf']); ?></div>
    <?php endif; ?>

    <section class="card">
        <h1>Change Password</h1>
        <form method="post" class="grid two" autocomplete="off" novalidate>
            <label>Current Password
                <input type="password" name="current_password" required autocomplete="current-password">
                <?php if (!empty($errors['current_password'])): ?>
                    <div class="error"><?php echo sanitize($errors['current_password']); ?></div>
                <?php endif; ?>
            </label>

            <label>New Password
                <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                <?php if (!empty($errors['new_password'])): ?>
                    <div class="error"><?php echo sanitize($errors['new_password']); ?></div>
                <?php endif; ?>
            </label>

            <label>Confirm New Password
                <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                <?php if (!empty($errors['confirm_password'])): ?>
                    <div class="error"><?php echo sanitize($errors['confirm_password']); ?></div>
                <?php endif; ?>
            </label>

            <div class="card-footer">
                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                <button class="btn primary" type="submit">Update Password</button>
            </div>
        </form>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
