<?php
// includes/notifications.php
// Requires: get_pdo(), current_user(), require_login(), sanitize(), csrf_token() (same helpers you use elsewhere)

function notif_pdo(): PDO {
    // Use your primary connection. If you have a 'core' pool, swap to get_pdo('core')
    return get_pdo();
}


function notif_resolve_local_user_id(?int $userId): ?int {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return null;
    }

    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $appsPdo = notif_pdo();

    // Fast path: the identifier is already a local user id.
    try {
        $st = $appsPdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) {
            return $cache[$userId] = (int)$row['id'];
        }
    } catch (Throwable $e) {
        // If the lookup fails we keep probing using the CORE record.
    }

    // Fallback: resolve via CORE directory and match on email.
    $coreEmail = null;
    $coreRole  = null;
    try {
        if (function_exists('core_user_record')) {
            $core = core_user_record($userId);
            if ($core) {
                $coreEmail = $core['email'] ?? null;
                $coreRole  = $core['role_key'] ?? ($core['role'] ?? null);
            }
        }
    } catch (Throwable $e) {
        // ignore and continue to the provisioning path below
    }

    if (!$coreEmail) {
        return $cache[$userId] = null;
    }

    try {
        $st = $appsPdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $st->execute([':email' => $coreEmail]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) {
            return $cache[$userId] = (int)$row['id'];
        }

        // No local row with that email yet – provision a shadow account so the
        // notification rows can satisfy their foreign key.
        $role = 'user';
        $roleKey = is_string($coreRole) ? strtolower($coreRole) : '';
        if (in_array($roleKey, ['admin', 'manager', 'root'], true)) {
            $role = 'admin';
        }

        $password = password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT);
        $ins = $appsPdo->prepare('INSERT INTO users (email, password_hash, role, created_at) VALUES (:email, :hash, :role, NOW())');
        $ins->execute([
            ':email' => $coreEmail,
            ':hash'  => $password,
            ':role'  => $role,
        ]);

        return $cache[$userId] = (int)$appsPdo->lastInsertId();
    } catch (Throwable $e) {
        try {
            error_log('notif_resolve_local_user_id failed for ' . $userId . ': ' . $e->getMessage());
        } catch (Throwable $_) {}
    }

    return $cache[$userId] = null;
}

/** Map a list of user identifiers (CORE or local) to local ids. */
function notif_resolve_local_user_ids(array $userIds): array {
    $out = [];
    foreach ($userIds as $uid) {
        $local = notif_resolve_local_user_id((int)$uid);
        if ($local) {
            $out[] = $local;
        }
    }
    return array_values(array_unique($out));
}

/** Upsert per-type preference (web/email/push + mute) */
function notif_set_type_pref(int $userId, string $type, array $prefs): void {
    $pdo = notif_pdo();
    $allow_web   = (int)($prefs['allow_web']   ?? 1);
    $allow_email = (int)($prefs['allow_email'] ?? 0);
    $allow_push  = (int)($prefs['allow_push']  ?? 0);
    $mute_until  = $prefs['mute_until'] ?? null;

    $sql = "INSERT INTO notification_type_prefs (user_id, notif_type, allow_web, allow_email, allow_push, mute_until)
            VALUES (:u,:t,:w,:e,:p,:m)
            ON DUPLICATE KEY UPDATE allow_web=VALUES(allow_web), allow_email=VALUES(allow_email),
                                    allow_push=VALUES(allow_push), mute_until=VALUES(mute_until)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':u'=>$userId, ':t'=>$type, ':w'=>$allow_web, ':e'=>$allow_email, ':p'=>$allow_push, ':m'=>$mute_until
    ]);
}

/** Get effective channel permissions for user+type (defaults if no row). */
function notif_get_type_pref(int $userId, string $type): array {
    static $cache = [];
    $key = $userId . '|' . $type;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $pdo = notif_pdo();
    $stmt = $pdo->prepare("SELECT allow_web, allow_email, allow_push, mute_until
                           FROM notification_type_prefs
                           WHERE user_id=:u AND notif_type=:t");
    $stmt->execute([':u'=>$userId, ':t'=>$type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $cache[$key] = ['allow_web'=>1,'allow_email'=>0,'allow_push'=>0,'mute_until'=>null];
    }
    return $cache[$key] = $row;
}

/** Subscribe a user to events for an entity (e.g. note.comment on note #123). */
function notif_subscribe_user(int $userId, ?string $entityType, ?int $entityId, string $event, string $channels = 'web,email'): void {
    $pdo = notif_pdo();
    $sql = "INSERT INTO notification_subscriptions (user_id, entity_type, entity_id, event, channels, is_enabled)
            VALUES (:u,:et,:eid,:ev,:ch,1)
            ON DUPLICATE KEY UPDATE channels=VALUES(channels), is_enabled=1";
    $pdo->prepare($sql)->execute([
        ':u'=>$userId, ':et'=>$entityType, ':eid'=>$entityId, ':ev'=>$event, ':ch'=>$channels
    ]);
}

/** Unsubscribe */
function notif_unsubscribe_user(int $userId, ?string $entityType, ?int $entityId, string $event): void {
    $pdo = notif_pdo();
    $pdo->prepare("UPDATE notification_subscriptions
                   SET is_enabled=0
                   WHERE user_id=:u AND entity_type <=> :et AND entity_id <=> :eid AND event=:ev")
        ->execute([':u'=>$userId, ':et'=>$entityType, ':eid'=>$entityId, ':ev'=>$event]);
}

/** Insert one notification for one user, respecting their per-type prefs and mute. */
function notif_emit(array $args): ?int {
    // $args: user_id, type, entity_type?, entity_id?, title?, body?, url?, data?, actor_user_id?
    $pdo = notif_pdo();

    $userId = (int)$args['user_id'];
    $type   = (string)$args['type'];

    $prefs  = notif_get_type_pref($userId, $type);
    $now    = new DateTimeImmutable('now');
    if (!empty($prefs['mute_until'])) {
        try {
            $mute = new DateTimeImmutable((string)$prefs['mute_until']);
            if ($mute > $now) {
                // muted: skip entirely
                return null;
            }
        } catch (Throwable $e) {}
    }

    $allow_web   = !empty($prefs['allow_web']);
    $allow_email = !empty($prefs['allow_email']);
    $allow_push  = !empty($prefs['allow_push']);

    // Write the web notification row when allowed
    $notifId = null;
    if ($allow_web) {
        $stmt = $pdo->prepare("INSERT INTO notifications
          (user_id, actor_user_id, type, entity_type, entity_id, title, body, data, url, is_read)
          VALUES (:u,:a,:t,:et,:eid,:ti,:bo,:da,:url,0)");
        $stmt->execute([
            ':u'  => $userId,
            ':a'  => isset($args['actor_user_id']) ? (int)$args['actor_user_id'] : null,
            ':t'  => $type,
            ':et' => $args['entity_type'] ?? null,
            ':eid'=> isset($args['entity_id']) ? (int)$args['entity_id'] : null,
            ':ti' => $args['title'] ?? null,
            ':bo' => $args['body'] ?? null,
            ':da' => !empty($args['data']) ? json_encode($args['data'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
            ':url'=> $args['url'] ?? null,
        ]);
        $notifId = (int)$pdo->lastInsertId();
    }

    // Queue background channels (email/push) if allowed for this user+type
    if ($notifId && ($allow_email || $allow_push)) {
        $ins = $pdo->prepare("INSERT INTO notification_channels_queue (notification_id, channel, status, scheduled_at)
                              VALUES (:nid, :ch, 'pending', NULL)");
        if ($allow_email) { $ins->execute([':nid'=>$notifId, ':ch'=>'email']); }
        if ($allow_push)  { $ins->execute([':nid'=>$notifId, ':ch'=>'push']); }
    }

    return $notifId;
}

/** Broadcast to many users (array of user IDs) */
function notif_broadcast(array $userIds, array $payload): array {
    $ids = [];
    foreach ($userIds as $uid) {
        $payload['user_id'] = (int)$uid;
        $id = notif_emit($payload);
        if ($id) $ids[] = $id;
    }
    return $ids;
}

/** Broadcast to subscribers of an entity+event (matches notification_subscriptions). */
function notif_broadcast_to_subscribers(string $eventType, ?string $entityType, ?int $entityId, array $payload): array {
    $pdo = notif_pdo();
    $sql = "SELECT user_id, channels
              FROM notification_subscriptions
             WHERE is_enabled=1
               AND event=:ev
               AND (entity_type <=> :et) AND (entity_id <=> :eid)";
    $st = $pdo->prepare($sql);
    $st->execute([':ev'=>$eventType, ':et'=>$entityType, ':eid'=>$entityId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $payload['user_id'] = (int)$r['user_id'];
        // Note: per-type prefs still apply inside notif_emit()
        $id = notif_emit($payload);
        if ($id) $out[] = $id;
    }
    return $out;
}

/** Unread count for the header bell */
function notif_unread_count(int $userId): int {
    $pdo = notif_pdo();
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0");
    $st->execute([':u'=>$userId]);
    return (int)$st->fetchColumn();
}

function notif_recent_unread(int $userId, int $limit = 3): array {
    $limit = max(1, (int)$limit);
    $pdo = notif_pdo();
    $st = $pdo->prepare("SELECT id, title, body, url, created_at FROM notifications WHERE user_id = :u AND is_read = 0 ORDER BY id DESC LIMIT :lim");
    $st->bindValue(':u', $userId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static function ($row) {
        return [
            'id'         => (int)($row['id'] ?? 0),
            'title'      => $row['title'] ?? '',
            'body'       => $row['body'] ?? '',
            'url'        => $row['url'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }, $rows);
}

/** Paginated list */
function notif_list(int $userId, int $limit = 20, int $offset = 0): array {
    $pdo = notif_pdo();
    $st = $pdo->prepare("SELECT * FROM notifications
                         WHERE user_id=:u
                         ORDER BY id DESC
                         LIMIT :lim OFFSET :off");
    $st->bindValue(':u', $userId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function notif_mark_read(int $userId, int $notifId): void {
    $pdo = notif_pdo();
    $pdo->prepare("UPDATE notifications SET is_read=1, read_at=NOW()
                   WHERE id=:id AND user_id=:u")->execute([':id'=>$notifId, ':u'=>$userId]);
}
function notif_mark_all_read(int $userId): void {
    $pdo = notif_pdo();
    $pdo->prepare("UPDATE notifications SET is_read=1, read_at=NOW()
                   WHERE user_id=:u AND is_read=0")->execute([':u'=>$userId]);
}
function notif_touch_web_device(int $userId, string $userAgent): void {
    $pdo = notif_pdo();
    $ua   = substr($userAgent, 0, 255);

    $sessionId = session_id();
    if ($sessionId === '' || $sessionId === false) {
        $sessionId = $_COOKIE['PHPSESSID'] ?? bin2hex(random_bytes(8));
    }

    $fingerprint = implode('|', [
        (string)$userId,
        (string)$sessionId,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        $ua,
    ]);
    $endpoint = 'internal-webpush://' . sha1($fingerprint);

    $stmt = $pdo->prepare("
        INSERT INTO notification_devices (user_id, kind, endpoint, user_agent, created_at, last_used_at)
        VALUES (:u, 'webpush', :ep, :ua, NOW(), NOW())
        ON DUPLICATE KEY UPDATE last_used_at = NOW(), user_agent = VALUES(user_agent), endpoint = VALUES(endpoint)
    "
    );

    try {
        $stmt->execute([':u' => $userId, ':ep' => $endpoint, ':ua' => $ua]);
    } catch (Throwable $e) {
        try {
            error_log('notif_touch_web_device failed: ' . $e->getMessage());
        } catch (Throwable $_) {}
    }
}