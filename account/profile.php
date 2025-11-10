<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_login();
require_once __DIR__ . '/../includes/notifications.php';

/* ---------- Data source detection ---------- */
function profile_resolve_user_store(): array {
    static $store = null;
    if ($store !== null) {
        return $store;
    }

    // Force "core" as the authoritative users DB.
    // Assumes get_pdo('core') is configured for your core_db database.
    $pdo = get_pdo('core');

    // Sanity check: make sure core.users looks like we expect
    $cols = [];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cols = array_map('strval', $cols);
    } catch (Throwable $e) {
        throw new RuntimeException('Could not read columns from core.users');
    }

    if (!in_array('pass_hash', $cols, true)) {
        throw new RuntimeException('core.users is missing expected column pass_hash');
    }

    // Cache the chosen store
    $store = [
        'db_key'          => 'core',
        'schema'          => 'core',
        'password_column' => 'pass_hash',
        'role_column'     => 'role_id',
        'pdo'             => $pdo,
        'columns'         => $cols,
    ];
    return $store;
}


function pick_users_pdo(): PDO
{
    $store = profile_resolve_user_store();
    return $store['pdo'];
}

function profile_store_schema(): string
{
    $store = profile_resolve_user_store();
    return (string)$store['schema'];
}

function profile_password_column(): string
{
    $store = profile_resolve_user_store();
    return (string)$store['password_column'];
}

function fetch_user(PDO $pdo, int $id): ?array
{
    $store = profile_resolve_user_store();
    if ($store['schema'] === 'core') {
        $sql = 'SELECT u.id, u.email, u.pass_hash AS password_hash, u.role_id, u.created_at, '
             . 'u.suspended_at, u.suspended_by, u.sector_id, '
             . 'r.label AS role_label, r.key_slug AS role_key, s.name AS sector_name '
             . 'FROM users u '
             . 'LEFT JOIN roles r   ON r.id = u.role_id '
             . 'LEFT JOIN sectors s ON s.id = u.sector_id '
             . 'WHERE u.id = ?';
    } else {
        $sql = 'SELECT id, email, password_hash, role, created_at FROM users WHERE id = ?';
    }

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    if ($store['schema'] === 'core') {
        if (isset($row['role_label']) && $row['role_label'] !== null) {
            $row['role'] = $row['role_label'];
        } elseif (isset($row['role_key'])) {
            $row['role'] = ucfirst(str_replace('_', ' ', (string)$row['role_key']));
        }
    } else {
        $role = (string)($row['role'] ?? '');
        $row['role_label'] = $role === '' ? '' : ucfirst(str_replace('_', ' ', $role));
        $row['role_key']   = $role;
    }

    return $row;
}

function profile_sync_shadow_email(int $userId, string $email, string $sourceSchema): void
{
    if ($sourceSchema !== 'core') {
        try {
            $core = get_pdo('core');
            $stmt = $core->prepare('UPDATE `users` SET `email` = ? WHERE `id` = ?');
            $stmt->execute([$email, $userId]);
        } catch (Throwable $e) {
        }
    }
    if ($sourceSchema !== 'punchlist') {
        try {
            $apps = get_pdo();
            $stmt = $apps->prepare('UPDATE `users` SET `email` = ? WHERE `id` = ?');
            $stmt->execute([$email, $userId]);
        } catch (Throwable $e) {
        }
    }
}

function profile_sync_shadow_password(int $userId, string $hash, string $sourceSchema): void
{
    if ($sourceSchema !== 'core') {
        try {
            $core = get_pdo('core');
            $stmt = $core->prepare('UPDATE `users` SET `pass_hash` = ? WHERE `id` = ?');
            $stmt->execute([$hash, $userId]);
        } catch (Throwable $e) {
        }
    }
    if ($sourceSchema !== 'punchlist') {
        try {
            $apps = get_pdo();
            $stmt = $apps->prepare('UPDATE `users` SET `password_hash` = ? WHERE `id` = ?');
            $stmt->execute([$hash, $userId]);
        } catch (Throwable $e) {
        }
    }
}

function profile_avatar_initial(?string $email): string
{
    $email = trim((string)$email);
    if ($email === '') {
        return 'U';
    }
    $first = strtoupper($email[0]);
    if (!preg_match('/[A-Z0-9]/', $first)) {
        $first = '#';
    }
    return $first;
}

function profile_format_datetime(?string $timestamp): string
{
    if (!$timestamp) {
        return '';
    }
    try {
        $dt = new DateTimeImmutable((string)$timestamp);
        return $dt->format('M j, Y · H:i');
    } catch (Throwable $e) {
        return (string)$timestamp;
    }
}

function profile_relative_time(?string $timestamp): string
{
    if (!$timestamp) {
        return '';
    }
    try {
        $dt  = new DateTimeImmutable((string)$timestamp);
        $now = new DateTimeImmutable('now');
    } catch (Throwable $e) {
        return '';
    }

    $diff = $now->getTimestamp() - $dt->getTimestamp();
    $suffix = $diff >= 0 ? 'ago' : 'from now';
    $diff = abs($diff);

    $units = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
        1        => 'second',
    ];

    foreach ($units as $secs => $label) {
        if ($diff >= $secs) {
            $value = (int)floor($diff / $secs);
            if ($value > 1) {
                $label .= 's';
            }
            return $value . ' ' . $label . ' ' . $suffix;
        }
    }

    return 'just now';
}

function profile_format_ip($raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (function_exists('inet_ntop')) {
        $ip = @inet_ntop((string)$raw);
        if ($ip !== false) {
            return $ip;
        }
    }
    if (is_string($raw) && preg_match('/^[0-9.]+$/', $raw)) {
        return $raw;
    }
    return null;
}

function profile_summarize_user_agent(?string $ua): string
{
    $ua = trim((string)$ua);
    if ($ua === '') {
        return 'Unknown device';
    }

    $uaLower = strtolower($ua);
    $browser = 'Browser';
    if (str_contains($uaLower, 'edg/')) {
        $browser = 'Edge';
    } elseif (str_contains($uaLower, 'chrome')) {
        $browser = 'Chrome';
    } elseif (str_contains($uaLower, 'firefox')) {
        $browser = 'Firefox';
    } elseif (str_contains($uaLower, 'safari')) {
        $browser = 'Safari';
    } elseif (str_contains($uaLower, 'opera') || str_contains($uaLower, 'opr/')) {
        $browser = 'Opera';
    }

    $os = '';
    if (str_contains($uaLower, 'iphone') || str_contains($uaLower, 'ipad')) {
        $os = 'iOS';
    } elseif (str_contains($uaLower, 'android')) {
        $os = 'Android';
    } elseif (str_contains($uaLower, 'windows')) {
        $os = 'Windows';
    } elseif (str_contains($uaLower, 'mac os')) {
        $os = 'macOS';
    } elseif (str_contains($uaLower, 'linux')) {
        $os = 'Linux';
    }

    $parts = array_filter([$browser, $os]);
    return implode(' · ', $parts) ?: $browser;
}

function fetch_recent_security_events(int $userId, int $limit = 6): array
{
    try {
        $pdo = get_pdo('core');
    } catch (Throwable $e) {
        return [];
    }

    try {
        $sql = 'SELECT ts, action, meta, ip, ua FROM activity_log '
             . 'WHERE user_id = :uid AND action IN ("login","logout","user.password_change","user.email_change") '
             . 'ORDER BY ts DESC LIMIT :lim';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $events = [];
    foreach ($rows as $row) {
        $events[] = profile_describe_security_event($row);
    }
    return $events;
}

function profile_describe_security_event(array $row): array
{
    $action = (string)($row['action'] ?? '');
    $ts     = $row['ts'] ?? null;
    $title  = match ($action) {
        'login'               => 'Signed in',
        'logout'              => 'Signed out',
        'user.password_change'=> 'Password updated',
        'user.email_change'   => 'Email updated',
        default               => ucfirst(str_replace('_', ' ', $action)),
    };

    $ip    = profile_format_ip($row['ip'] ?? null);
    $agent = profile_summarize_user_agent($row['ua'] ?? '');
    $metaParts = [];
    if ($ip) {
        $metaParts[] = $ip;
    }
    if ($agent) {
        $metaParts[] = $agent;
    }

    $details = '';
    if (!empty($row['meta'])) {
        $decoded = json_decode((string)$row['meta'], true);
        if (is_array($decoded)) {
            if (isset($decoded['old'], $decoded['new'])) {
                $details = 'Changed ' . (string)$decoded['old'] . ' → ' . (string)$decoded['new'];
            } elseif (isset($decoded['email'])) {
                $details = (string)$decoded['email'];
            }
        }
    }

    return [
        'title'       => $title,
        'meta'        => implode(' • ', $metaParts),
        'details'     => $details,
        'ts'          => $ts,
        'relative'    => profile_relative_time($ts),
        'formatted'   => profile_format_datetime($ts),
    ];
}

function profile_notification_types(): array
{
    return [
        'task.assigned'   => [
            'label'       => 'Task assignments',
            'description' => 'Alerts when someone assigns a task to you or your team.',
        ],
        'task.updated'    => [
            'label'       => 'Task progress',
            'description' => 'Heads-up when priority, due dates, or status change on tasks you follow.',
        ],
        'note.activity'   => [
            'label'       => 'Note collaboration',
            'description' => 'Comments, mentions, and edits on notes you created or follow.',
        ],
        'system.broadcast'=> [
            'label'       => 'System announcements',
            'description' => 'Release notes and scheduled maintenance updates from the team.',
        ],
    ];
}

function profile_fetch_notification_devices(int $localUserId): array
{
    try {
        $pdo = notif_pdo();
        $stmt = $pdo->prepare('SELECT id, kind, user_agent, created_at, last_used_at '
                             . 'FROM notification_devices WHERE user_id = :u '
                             . 'ORDER BY COALESCE(last_used_at, created_at) DESC');
        $stmt->execute([':u' => $localUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function profile_mute_field_state(?string $muteUntil): array
{
    $state = [
        'select'      => 'off',
        'description' => '',
        'until'       => $muteUntil,
    ];

    if (!$muteUntil) {
        return $state;
    }

    try {
        $until = new DateTimeImmutable($muteUntil);
        $now   = new DateTimeImmutable('now');
    } catch (Throwable $e) {
        return $state;
    }

    if ($until <= $now) {
        return $state;
    }

    $diff = $until->getTimestamp() - $now->getTimestamp();
    $map  = [
        '1h' => 3600,
        '4h' => 14400,
        '1d' => 86400,
        '3d' => 259200,
        '7d' => 604800,
    ];

    foreach ($map as $key => $seconds) {
        if (abs($diff - $seconds) <= 300) {
            $state['select']      = $key;
            $state['description'] = 'Snoozed until ' . profile_format_datetime($muteUntil);
            return $state;
        }
    }

    if ($diff >= 86400 * 90) {
        $state['select']      = 'forever';
        $state['description'] = 'Muted until you turn it back on';
        return $state;
    }

    $state['select']      = 'keep';
    $state['description'] = 'Snoozed until ' . profile_format_datetime($muteUntil);
    return $state;
}

$errors = [];
$me     = current_user();
$userId = (int)($me['id'] ?? 0);

try {
    $pdo  = pick_users_pdo();
    $user = fetch_user($pdo, $userId);
    if (!$user) {
        http_response_code(404);
        exit('User not found.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Profile error</h1><p>Could not access the users table. '
       . 'Make sure it exists on the expected database connection.</p>';
    exit;
}

$storeSchema = profile_store_schema();
$notificationTypes = profile_notification_types();
$notificationPrefs = [];
$notificationUserId = null;
if (function_exists('notif_resolve_local_user_id')) {
    try {
        $notificationUserId = notif_resolve_local_user_id($userId);
        if ($notificationUserId) {
            foreach ($notificationTypes as $type => $meta) {
                try {
                    $pref = notif_get_type_pref($notificationUserId, $type);
                } catch (Throwable $e) {
                    $pref = ['allow_web' => 1, 'allow_email' => 0, 'allow_push' => 0, 'mute_until' => null];
                }
                $notificationPrefs[$type] = [
                    'allow_web'   => !empty($pref['allow_web']),
                    'allow_email' => !empty($pref['allow_email']),
                    'allow_push'  => !empty($pref['allow_push']),
                    'mute_until'  => $pref['mute_until'] ?? null,
                ];
            }
        }
    } catch (Throwable $e) {
        $notificationUserId = null;
    }
}
$notificationsAvailable = $notificationUserId !== null;
$notificationDevices = ($notificationsAvailable) ? profile_fetch_notification_devices($notificationUserId) : [];

/* ---------- POST handlers ---------- */
if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'change_email') {
            $newEmail = trim((string)($_POST['email'] ?? ''));
            if ($newEmail === '') {
                $errors[] = 'Email is required.';
            } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                try {
                    $st = $pdo->prepare('SELECT 1 FROM `users` WHERE `email` = ? AND `id` <> ? LIMIT 1');
                    $st->execute([$newEmail, $userId]);
                    if ($st->fetchColumn()) {
                        $errors[] = 'That email is already in use.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Could not validate email uniqueness.';
                }
            }

            if (!$errors) {
                try {
                    $oldEmail = (string)$user['email'];
                    $columnEmail = 'email';
                    $stmt = $pdo->prepare('UPDATE `users` SET `' . $columnEmail . '` = ? WHERE `id` = ?');
                    $stmt->execute([$newEmail, $userId]);

                    profile_sync_shadow_email($userId, $newEmail, $storeSchema);

                    if (function_exists('log_event')) {
                        log_event('user.email_change', 'user', $userId, ['old' => $oldEmail, 'new' => $newEmail]);
                    }
                    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['email'] = $newEmail;
                    }
                    redirect_with_message('/account/profile.php', 'Email updated.', 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Failed to update email.';
                }
            }
        }

        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if ($current === '' || $new === '' || $confirm === '') {
                $errors[] = 'All password fields are required.';
            } elseif (!password_verify($current, (string)$user['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            }

            if (!$errors) {
                try {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $column = profile_password_column();
                    $stmt = $pdo->prepare('UPDATE `users` SET `' . $column . '` = ? WHERE `id` = ?');
                    $stmt->execute([$hash, $userId]);

                    profile_sync_shadow_password($userId, $hash, $storeSchema);

                    if (function_exists('log_event')) {
                        log_event('user.password_change', 'user', $userId);
                    }

                    redirect_with_message('/account/profile.php', 'Password updated.', 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Failed to update password.';
                }
            }
        }

        if ($action === 'update_prefs') {
            if (!$notificationsAvailable) {
                $errors[] = 'Notification preferences are not available right now.';
            } else {
                $prefsInput = $_POST['prefs'] ?? [];
                $now = new DateTimeImmutable('now');
                foreach ($notificationTypes as $type => $meta) {
                    $incoming = $prefsInput[$type] ?? [];
                    $update = [
                        'allow_web'   => !empty($incoming['allow_web']) ? 1 : 0,
                        'allow_email' => !empty($incoming['allow_email']) ? 1 : 0,
                        'allow_push'  => !empty($incoming['allow_push']) ? 1 : 0,
                    ];

                    $choice        = (string)($incoming['mute_for'] ?? 'off');
                    $existingMute  = $notificationPrefs[$type]['mute_until'] ?? null;
                    $muteUntil     = null;

                    switch ($choice) {
                        case 'keep':
                            $muteUntil = $existingMute;
                            break;
                        case 'off':
                            $muteUntil = null;
                            break;
                        case '1h':
                            $muteUntil = $now->modify('+1 hour')->format('Y-m-d H:i:s');
                            break;
                        case '4h':
                            $muteUntil = $now->modify('+4 hours')->format('Y-m-d H:i:s');
                            break;
                        case '1d':
                            $muteUntil = $now->modify('+1 day')->format('Y-m-d H:i:s');
                            break;
                        case '3d':
                            $muteUntil = $now->modify('+3 days')->format('Y-m-d H:i:s');
                            break;
                        case '7d':
                            $muteUntil = $now->modify('+7 days')->format('Y-m-d H:i:s');
                            break;
                        case 'forever':
                            $muteUntil = $now->modify('+5 years')->format('Y-m-d H:i:s');
                            break;
                        default:
                            $muteUntil = null;
                            break;
                    }

                    if ($choice === 'keep' && !$existingMute) {
                        $muteUntil = null;
                    }

                    $update['mute_until'] = $muteUntil;

                    try {
                        notif_set_type_pref($notificationUserId, $type, $update);
                    } catch (Throwable $e) {
                        $errors[] = 'Failed to save notification preferences for ' . $meta['label'] . '.';
                        break;
                    }
                }

                if (!$errors) {
                    redirect_with_message('/account/profile.php', 'Notification preferences updated.', 'success');
                }
            }
        }

        if ($action === 'revoke_device') {
            if (!$notificationsAvailable) {
                $errors[] = 'Notifications are not available right now.';
            } else {
                $deviceId = (int)($_POST['device_id'] ?? 0);
                if ($deviceId <= 0) {
                    $errors[] = 'Device not found.';
                } else {
                    try {
                        $pdoNotif = notif_pdo();
                        $stmt = $pdoNotif->prepare('DELETE FROM notification_devices WHERE id = :id AND user_id = :uid');
                        $stmt->execute([':id' => $deviceId, ':uid' => $notificationUserId]);
                        if ($stmt->rowCount() > 0) {
                            redirect_with_message('/account/profile.php', 'Device disconnected.', 'success');
                        } else {
                            $errors[] = 'Device not found or already removed.';
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Could not remove the device.';
                    }
                }
            }
        }
    }

    try {
        $user = fetch_user($pdo, $userId) ?? $user;
    } catch (Throwable $e) {
    }

    if ($notificationsAvailable) {
        $notificationPrefs = [];
        foreach ($notificationTypes as $type => $meta) {
            try {
                $pref = notif_get_type_pref($notificationUserId, $type);
            } catch (Throwable $e) {
                $pref = ['allow_web' => 1, 'allow_email' => 0, 'allow_push' => 0, 'mute_until' => null];
            }
            $notificationPrefs[$type] = [
                'allow_web'   => !empty($pref['allow_web']),
                'allow_email' => !empty($pref['allow_email']),
                'allow_push'  => !empty($pref['allow_push']),
                'mute_until'  => $pref['mute_until'] ?? null,
            ];
        }
        $notificationDevices = profile_fetch_notification_devices($notificationUserId);
    }
}

/* ---------- Derived view data ---------- */
$statusLabel = 'Active';
$statusClass = 'badge -success';
if (!empty($user['suspended_at'])) {
    $statusLabel = 'Suspended';
    $statusClass = 'badge -danger';
}
$roleLabel = (string)($user['role'] ?? $user['role_label'] ?? '');
if ($roleLabel === '' && !empty($user['role_key'])) {
    $roleLabel = ucfirst(str_replace('_', ' ', (string)$user['role_key']));
}
$sectorLabel = (string)($user['sector_name'] ?? '');
$joinedFull  = profile_format_datetime($user['created_at'] ?? null);
$joinedRel   = profile_relative_time($user['created_at'] ?? null);
$securityEvents = fetch_recent_security_events($userId, 6);

$title = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>
<section class="profile-hero card">
  <div class="profile-hero__identity">
    <span class="profile-hero__avatar"><?php echo sanitize(profile_avatar_initial($user['email'] ?? '')); ?></span>
    <div>
      <h1>Account center</h1>
      <p class="muted"><?php echo sanitize($user['email'] ?? ''); ?></p>
    </div>
  </div>
  <dl class="profile-hero__meta">
    <div>
      <dt>Status</dt>
      <dd><span class="<?php echo sanitize($statusClass); ?>"><?php echo sanitize($statusLabel); ?></span></dd>
    </div>
    <div>
      <dt>Joined</dt>
      <dd>
        <?php if ($joinedFull): ?>
          <span><?php echo sanitize($joinedFull); ?></span>
          <?php if ($joinedRel): ?><span class="muted">(<?php echo sanitize($joinedRel); ?>)</span><?php endif; ?>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </dd>
    </div>
    <?php if ($roleLabel !== ''): ?>
      <div>
        <dt>Role</dt>
        <dd><span class="badge -info"><?php echo sanitize($roleLabel); ?></span></dd>
      </div>
    <?php endif; ?>
    <?php if ($sectorLabel !== ''): ?>
      <div>
        <dt>Sector</dt>
        <dd><?php echo sanitize($sectorLabel); ?></dd>
      </div>
    <?php endif; ?>
  </dl>
  <div class="profile-hero__actions">
    <a class="chip chip--primary" href="/notifications/index.php">Open notifications</a>
    <a class="chip" href="/tasks.php">View my tasks</a>
    <a class="chip" href="/notes/index.php">Go to notes</a>
  </div>
</section>

<?php if ($errors): ?>
  <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
<?php endif; ?>

<div class="profile-layout">
  <div class="profile-column">
    <form method="post" class="profile-card card">
      <h2>Account</h2>
      <p class="section-subtitle">Update your sign-in email address.</p>
      <label>Email
        <input type="email" name="email" required value="<?php echo sanitize((string)$user['email']); ?>">
      </label>
      <label>Role
        <input type="text" value="<?php echo sanitize($roleLabel ?: '—'); ?>" disabled>
      </label>
      <input type="hidden" name="action" value="change_email">
      <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
      <div class="form-actions">
        <button class="btn primary" type="submit">Save email</button>
      </div>
    </form>

    <form method="post" class="profile-card card">
      <h2>Password</h2>
      <p class="section-subtitle">Use at least 8 characters and mix letters, numbers, and symbols.</p>
      <label>Current password
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <label>New password
        <input type="password" name="new_password" required autocomplete="new-password" minlength="8" placeholder="At least 8 characters">
      </label>
      <label>Confirm new password
        <input type="password" name="confirm_password" required autocomplete="new-password">
      </label>
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
      <div class="form-actions">
        <button class="btn primary" type="submit">Update password</button>
      </div>
    </form>

    <form method="post" class="profile-card card">
      <h2>Notification preferences</h2>
      <p class="section-subtitle">Decide how we nudge you about the work that matters.</p>
      <?php if (!$notificationsAvailable): ?>
        <p class="muted">Notification preferences are temporarily unavailable.</p>
      <?php else: ?>
        <div class="pref-list">
          <?php foreach ($notificationTypes as $type => $meta):
            $pref      = $notificationPrefs[$type] ?? ['allow_web' => true, 'allow_email' => false, 'allow_push' => false, 'mute_until' => null];
            $muteState = profile_mute_field_state($pref['mute_until']);
            $fieldKey  = preg_replace('/[^a-z0-9]+/i', '_', $type);
            $hasExistingMute = !empty($pref['mute_until']) && $muteState['select'] !== 'off';
            $keepLabel = 'Keep current snooze';
            if (!empty($pref['mute_until'])) {
              if ($muteState['select'] === 'forever') {
                $keepLabel = 'Keep mute on';
              } else {
                $keepLabel = 'Keep until ' . profile_format_datetime($pref['mute_until']);
              }
            }
          ?>
            <div class="pref-row">
              <div class="pref-row__info">
                <h3><?php echo sanitize($meta['label']); ?></h3>
                <p class="muted"><?php echo sanitize($meta['description']); ?></p>
              </div>
              <div class="pref-row__toggles">
                <label class="switch">
                  <input type="checkbox" name="prefs[<?php echo sanitize($type); ?>][allow_web]" value="1"<?php echo $pref['allow_web'] ? ' checked' : ''; ?>>
                  <span class="switch__control" aria-hidden="true"></span>
                  <span class="switch__label">In-app</span>
                </label>
                <label class="switch">
                  <input type="checkbox" name="prefs[<?php echo sanitize($type); ?>][allow_email]" value="1"<?php echo $pref['allow_email'] ? ' checked' : ''; ?>>
                  <span class="switch__control" aria-hidden="true"></span>
                  <span class="switch__label">Email</span>
                </label>
                <label class="switch">
                  <input type="checkbox" name="prefs[<?php echo sanitize($type); ?>][allow_push]" value="1"<?php echo $pref['allow_push'] ? ' checked' : ''; ?>>
                  <span class="switch__control" aria-hidden="true"></span>
                  <span class="switch__label">Push</span>
                </label>
              </div>
              <div class="pref-row__mute">
                <label for="mute-<?php echo sanitize($fieldKey); ?>">Snooze</label>
                <select id="mute-<?php echo sanitize($fieldKey); ?>" name="prefs[<?php echo sanitize($type); ?>][mute_for]">
                  <option value="off"<?php echo $muteState['select'] === 'off' ? ' selected' : ''; ?>>Live updates</option>
                  <option value="1h"<?php echo $muteState['select'] === '1h' ? ' selected' : ''; ?>>Pause 1 hour</option>
                  <option value="4h"<?php echo $muteState['select'] === '4h' ? ' selected' : ''; ?>>Pause 4 hours</option>
                  <option value="1d"<?php echo $muteState['select'] === '1d' ? ' selected' : ''; ?>>Pause 1 day</option>
                  <option value="3d"<?php echo $muteState['select'] === '3d' ? ' selected' : ''; ?>>Pause 3 days</option>
                  <option value="7d"<?php echo $muteState['select'] === '7d' ? ' selected' : ''; ?>>Pause 7 days</option>
                  <option value="forever"<?php echo $muteState['select'] === 'forever' ? ' selected' : ''; ?>>Mute until I turn it back on</option>
                  <?php if ($hasExistingMute): ?>
                    <option value="keep"<?php echo $muteState['select'] === 'keep' ? ' selected' : ''; ?>><?php echo sanitize($keepLabel); ?></option>
                  <?php endif; ?>
                </select>
                <input type="hidden" name="prefs[<?php echo sanitize($type); ?>][existing_mute_until]" value="<?php echo sanitize((string)($pref['mute_until'] ?? '')); ?>">
                <?php if ($muteState['description']): ?>
                  <p class="pref-row__hint"><?php echo sanitize($muteState['description']); ?></p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="action" value="update_prefs">
        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
        <div class="form-actions">
          <button class="btn primary" type="submit">Save preferences</button>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="profile-column">
    <section class="profile-card card">
      <h2>Security timeline</h2>
      <p class="section-subtitle">Latest sign-ins and account changes.</p>
      <?php if ($securityEvents): ?>
        <ul class="timeline">
          <?php foreach ($securityEvents as $event): ?>
            <li class="timeline__item">
              <div class="timeline__title"><?php echo sanitize($event['title']); ?></div>
              <?php if ($event['details']): ?>
                <div class="timeline__details"><?php echo sanitize($event['details']); ?></div>
              <?php endif; ?>
              <?php if ($event['meta']): ?>
                <div class="timeline__meta"><?php echo sanitize($event['meta']); ?></div>
              <?php endif; ?>
              <div class="timeline__time"><?php echo sanitize($event['relative']); ?> · <?php echo sanitize($event['formatted']); ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">We have not logged any recent sign-ins yet.</p>
      <?php endif; ?>
    </section>

    <section class="profile-card card">
      <h2>Trusted devices</h2>
      <p class="section-subtitle">Disconnect browsers or mobiles you no longer recognize.</p>
      <?php if (!$notificationsAvailable): ?>
        <p class="muted">Connect a device to enable web or push notifications.</p>
      <?php elseif ($notificationDevices): ?>
        <ul class="device-list">
          <?php foreach ($notificationDevices as $device):
            $kind = (string)($device['kind'] ?? 'webpush');
            $kindLabel = match ($kind) {
              'fcm'  => 'Android push',
              'apns' => 'iOS push',
              default => 'Web push',
            };
            $lastUsed = $device['last_used_at'] ?? $device['created_at'] ?? null;
            $lastRelative = profile_relative_time($lastUsed);
            $lastFormatted = profile_format_datetime($lastUsed);
            $uaLabel = profile_summarize_user_agent($device['user_agent'] ?? '');
          ?>
            <li class="device-row">
              <div class="device-row__main">
                <span class="badge -info"><?php echo sanitize($kindLabel); ?></span>
                <div class="device-row__text">
                  <div class="device-row__label"><?php echo sanitize($uaLabel); ?></div>
                  <?php if ($lastFormatted): ?>
                    <div class="device-row__meta"><?php echo sanitize($lastRelative ?: 'Last seen'); ?> · <?php echo sanitize($lastFormatted); ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <form method="post" class="device-row__actions">
                <input type="hidden" name="action" value="revoke_device">
                <input type="hidden" name="device_id" value="<?php echo (int)$device['id']; ?>">
                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                <button class="btn secondary small" type="submit">Disconnect</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">No connected browsers or mobile devices yet.</p>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php';