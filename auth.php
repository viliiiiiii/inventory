<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Attempt login using CORE users first (core_db.users).
 * Falls back to legacy punchlist.users (apps DB) once, and auto-seeds CORE.
 */
function attempt_login(string $email, string $password): bool {
    $email = trim($email);
    if ($email === '' || $password === '') return false;

    // --- 1) CORE auth
    $user = core_find_user_by_email($email);
    if ($user && !empty($user['pass_hash']) && password_verify($password, (string)$user['pass_hash'])) {
        auth_login((int)$user['id']);
        enforce_not_suspended();
        log_event('login', 'user', (int)$user['id']);
        return true;
    }

    // --- 2) Legacy fallback (apps DB) — optional one-time bridge
    try {
        $pdo = get_pdo('apps');
        $st = $pdo->prepare("SELECT id, email, pass_hash FROM users WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        $legacy = $st->fetch();
    } catch (Throwable $e) {
        $legacy = null;
    }

    if ($legacy && !empty($legacy['pass_hash']) && password_verify($password, (string)$legacy['pass_hash'])) {
        // Seed into CORE if missing, default to 'admin' role (fallback to first role if admin missing)
        try {
            $core = get_pdo('core');
            $roleId = (int)($core->query("SELECT id FROM roles WHERE key_slug='admin'")->fetchColumn() ?: 0);
            if (!$roleId) {
                $roleId = (int)($core->query("SELECT id FROM roles LIMIT 1")->fetchColumn() ?: 0);
            }
            if ($roleId) {
                $ins = $core->prepare("INSERT IGNORE INTO users (email, pass_hash, role_id) VALUES (?, ?, ?)");
                $ins->execute([$legacy['email'], $legacy['pass_hash'], $roleId]);
            }
            // Fetch the now-seeded CORE user
            $user = core_find_user_by_email($email);
            if ($user) {
                auth_login((int)$user['id']);
                log_event('login', 'user', (int)$user['id'], ['source' => 'legacy_seed']);
                return true;
            }
        } catch (Throwable $e) {
            // If CORE unavailable, keep legacy session for compatibility (not ideal)
        }

        // Last-resort: legacy session payload (avoid if possible, but keeps the app usable)
        $_SESSION['user'] = [
            'id'    => (int)$legacy['id'],
            'email' => (string)$legacy['email'],
            // no role info here; permissions will be minimal
        ];
        log_event('login', 'user', (int)$legacy['id'], ['source' => 'legacy_session']);
        return true;
    }

    return false;
}

/** Sign the user out and redirect */
function logout_and_redirect(string $to = 'login.php'): void {
    auth_logout();
    redirect_with_message($to, 'You have been signed out.', 'success');
}
