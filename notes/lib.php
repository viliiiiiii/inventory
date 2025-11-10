<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/**
 * Notes library (owner + explicit shares)
 * Tables expected (in your APP DB):
 *  - notes(id, user_id, note_date, title, body, created_at, updated_at)
 *  - note_photos(id, note_id, position, s3_key, url, created_at)
 *  - notes_shares(id, note_id, user_id, created_at)
 *
 * Optional: if your shares table still uses `shared_with`, detection below handles it.
 */

const NOTES_MAX_MB = 70;
const NOTES_ALLOWED_MIMES = [
    'image/jpeg'          => 'jpg',
    'image/png'           => 'png',
    'image/webp'          => 'webp',
    'image/heic'          => 'heic',
    'image/heif'          => 'heic',
    'image/heic-sequence' => 'heic',
    'image/heif-sequence' => 'heic',
    'application/octet-stream' => null, // fallback by filename
];
function notes__is_safe_identifier(string $name): bool {
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', $name);
}


/* ---------- tiny schema helpers (tolerant) ---------- */
function notes__col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        if ($st->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // hosts may block SHOW commands; fall through to SELECT-based probe
    }

    if (!notes__is_safe_identifier($table) || !notes__is_safe_identifier($col)) {
        return false;
    }

    try {
        $pdo->query("SELECT `$col` FROM `$table` LIMIT 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
function notes__table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        if ($st->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // fall through to SELECT-based probe
    }

    if (!notes__is_safe_identifier($table)) {
        return false;
    }

    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
function notes__shares_column(PDO $pdo): ?string {
    // Prefer `user_id` (current schema). Fall back to legacy `shared_with`.
    if (!notes__table_exists($pdo, 'notes_shares')) return null;
    if (notes__col_exists($pdo, 'notes_shares', 'user_id')) return 'user_id';
    if (notes__col_exists($pdo, 'notes_shares', 'shared_with')) return 'shared_with';
    return null;
}
function notes__ensure_shares_schema(PDO $pdo): ?string {
    $col = notes__shares_column($pdo);
    if ($col) {
        return $col;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS notes_shares (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                note_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_note_user (note_id, user_id),
                INDEX idx_user (user_id),
                CONSTRAINT fk_notes_shares_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        return null;
    }

    $col = notes__shares_column($pdo);
    if ($col) {
        return $col;
    }

    // Table exists but without expected columns: try adding a `user_id` column.
    try {
        $pdo->exec('ALTER TABLE notes_shares ADD COLUMN user_id BIGINT UNSIGNED NULL');
        $pdo->exec('CREATE INDEX idx_user ON notes_shares (user_id)');
    } catch (Throwable $e) {
        // ignore; best effort
    }

    return notes__shares_column($pdo);
}

/* ---------- MIME/extension helpers ---------- */
function notes_ext_for_mime(string $mime): ?string {
    return NOTES_ALLOWED_MIMES[$mime] ?? null;
}
function notes_resolve_ext_and_mime(string $tmpPath, string $origName): array {
    $mime = 'application/octet-stream';
    $fi   = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $mm = @finfo_file($fi, $tmpPath);
        if (is_string($mm) && $mm !== '') $mime = $mm;
        @finfo_close($fi);
    }
    $ext = notes_ext_for_mime($mime);
    if ($ext === null) {
        $fnExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (in_array($fnExt, ['jpg','jpeg','png','webp','heic','heif'], true)) {
            $ext = $fnExt === 'jpeg' ? 'jpg' : ($fnExt === 'heif' ? 'heic' : $fnExt);
            if ($mime === 'application/octet-stream') {
                $mime = [
                    'jpg'  => 'image/jpeg',
                    'png'  => 'image/png',
                    'webp' => 'image/webp',
                    'heic' => 'image/heic',
                ][$ext] ?? 'application/octet-stream';
            }
        }
    }
    return [$ext, $mime];
}

/* ---------- CRUD ---------- */
function notes_insert(array $data): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "INSERT INTO notes (user_id, note_date, title, body)
         VALUES (:user_id, :note_date, :title, :body)"
    );
    $stmt->execute([
        ':user_id'   => (int)$data['user_id'],
        ':note_date' => $data['note_date'],
        ':title'     => $data['title'],
        ':body'      => ($data['body'] ?? '') !== '' ? $data['body'] : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function notes_update(int $id, array $data): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "UPDATE notes SET note_date=:note_date, title=:title, body=:body WHERE id=:id"
    );
    $stmt->execute([
        ':note_date' => $data['note_date'],
        ':title'     => $data['title'],
        ':body'      => ($data['body'] ?? '') !== '' ? $data['body'] : null,
        ':id'        => $id,
    ]);
}

function notes_delete(int $id): void {
    // delete photos and object storage, then note
    $photos = notes_fetch_photos($id);
    foreach ($photos as $p) {
        try { s3_client()->deleteObject(['Bucket'=>S3_BUCKET,'Key'=>$p['s3_key']]); } catch (Throwable $e) {}
    }
    $pdo = get_pdo();
    if (notes__table_exists($pdo, 'note_photos')) {
        $pdo->prepare("DELETE FROM note_photos WHERE note_id=?")->execute([$id]);
    }
    if (notes__table_exists($pdo, 'note_comments')) {
        $pdo->prepare("DELETE FROM note_comments WHERE note_id=?")->execute([$id]);
    }
    if (notes__table_exists($pdo, 'notes_shares')) {
        $pdo->prepare("DELETE FROM notes_shares WHERE note_id=?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM notes WHERE id=?")->execute([$id]);
}

function notes_fetch(int $id): ?array {
    $pdo = get_pdo();
    $st = $pdo->prepare("SELECT * FROM notes WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/* ---------- photos ---------- */
function notes_fetch_photos(int $noteId): array {
    $pdo = get_pdo();
    if (!notes__table_exists($pdo, 'note_photos')) return [];
    $st = $pdo->prepare("SELECT * FROM note_photos WHERE note_id=? ORDER BY position");
    $st->execute([$noteId]);
    $out = [];
    while ($r = $st->fetch()) { $out[(int)$r['position']] = $r; }
    return $out;
}

function notes_upsert_photo(int $noteId, int $position, string $key, string $url): void {
    $pdo = get_pdo();
    if (!notes__table_exists($pdo, 'note_photos')) return;
    $sql = "INSERT INTO note_photos (note_id,position,s3_key,url)
            VALUES (:note_id,:position,:s3_key,:url)
            ON DUPLICATE KEY UPDATE s3_key=VALUES(s3_key), url=VALUES(url), created_at=NOW()";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':note_id'  => $noteId,
        ':position' => $position,
        ':s3_key'   => $key,
        ':url'      => $url,
    ]);
}

function notes_remove_photo_by_id(int $photoId): void {
    $pdo = get_pdo();
    if (!notes__table_exists($pdo, 'note_photos')) return;
    $st = $pdo->prepare("SELECT * FROM note_photos WHERE id=?");
    $st->execute([$photoId]);
    if ($row = $st->fetch()) {
        try { s3_client()->deleteObject(['Bucket'=>S3_BUCKET,'Key'=>$row['s3_key']]); } catch (Throwable $e) {}
        $pdo->prepare("DELETE FROM note_photos WHERE id=?")->execute([$photoId]);
    }
}

/** Save uploaded photo (field name -> e.g. 'photo', 'photo1' etc.). Returns [url,key,mime]. */
function notes_save_uploaded_photo(int $noteId, int $position, string $fieldName): array {
    if (!isset($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException("No file for $fieldName");
    }
    $err = (int)$_FILES[$fieldName]['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE=>'file exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE=>'file exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL=>'partial upload',
            UPLOAD_ERR_NO_TMP_DIR=>'missing tmp dir',
            UPLOAD_ERR_CANT_WRITE=>'disk write failed',
            UPLOAD_ERR_EXTENSION=>'blocked by extension',
        ];
        throw new RuntimeException("Upload error: " . ($map[$err] ?? "code $err"));
    }

    $tmp   = (string)$_FILES[$fieldName]['tmp_name'];
    $size  = (int)($_FILES[$fieldName]['size'] ?? 0);
    $oname = (string)($_FILES[$fieldName]['name'] ?? '');
    if ($size <= 0) throw new RuntimeException('Empty file');
    if ($size > NOTES_MAX_MB * 1024 * 1024) throw new RuntimeException('File too large (max '.NOTES_MAX_MB.'MB)');

    [$ext, $mime] = notes_resolve_ext_and_mime($tmp, $oname);
    if (!$ext) throw new RuntimeException("Unsupported type");

    $uuid = bin2hex(random_bytes(8));
    $key  = sprintf('notes/%d/%s-%d.%s', $noteId, $uuid, $position, $ext);

    $url = null;
    $s3Available = class_exists(\Aws\S3\S3Client::class) && S3_BUCKET !== '' && S3_ENDPOINT !== '';
    if ($s3Available) {
        try {
            s3_client()->putObject([
                'Bucket'      => S3_BUCKET,
                'Key'         => $key,
                'SourceFile'  => $tmp,
                'ContentType' => $mime,
            ]);
            $url = s3_object_url($key);
        } catch (Throwable $e) {
            $s3Available = false; // fallback to local
        }
    }
    if (!$s3Available) {
        $base = __DIR__ . '/../uploads';
        $dir  = $base . '/notes/' . $noteId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Failed to create uploads directory');
        }
        $dest = $dir . '/' . basename($key);
        if (!@move_uploaded_file($tmp, $dest)) {
            $bytes = @file_get_contents($tmp);
            if ($bytes === false || !@file_put_contents($dest, $bytes)) {
                throw new RuntimeException('Failed to write local file');
            }
        }
        $url = '/uploads/notes/' . $noteId . '/' . basename($dest);
    }

    notes_upsert_photo($noteId, $position, $key, $url);
    return ['url' => $url, 'key' => $key, 'mime' => $mime];
}

/* ---------- shares & authorization ---------- */
function notes_all_users(): array {
    $pdo = get_pdo('core'); // your CORE users
    try {
        $st = $pdo->query('SELECT id, email FROM users ORDER BY email');
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        // try app DB as fallback
        $pdo = get_pdo();
        $st = $pdo->query('SELECT id, email FROM users ORDER BY email');
        return $st->fetchAll() ?: [];
    }
}

function notes_get_share_user_ids(int $noteId): array {
    $pdo = get_pdo();
    $col = notes__shares_column($pdo);
    if (!$col) return [];
    $st = $pdo->prepare("SELECT $col AS user_id FROM notes_shares WHERE note_id = ?");
    $st->execute([$noteId]);
    return array_map('intval', array_column($st->fetchAll() ?: [], 'user_id'));
}

function notes_update_shares(int $noteId, array $userIds): void {
    $pdo = get_pdo();
    $col = notes__shares_column($pdo);
    if (!$col) {
        $col = notes__ensure_shares_schema($pdo);
    }
    if (!$col) {
        throw new RuntimeException('notes_shares table/column not present.');
    }
    if ($col === 'user_id') {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    } else {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds), fn($v) => $v !== '')));
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM notes_shares WHERE note_id = ?')->execute([$noteId]);
        if ($userIds) {
            $sql = "INSERT INTO notes_shares (note_id, $col) VALUES (?, ?)";
            $ins = $pdo->prepare($sql);
            foreach ($userIds as $uid) {
                $ins->execute([$noteId, $uid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function notes_fetch_users_from(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        $uid = (int)($row['id'] ?? 0);
        if ($uid <= 0) continue;
        $label = trim((string)($row['email'] ?? ''));
        $map[$uid] = $label !== '' ? $label : ('User #'.$uid);
    }
    return $map;
}

function notes_fetch_users_map(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];

    $map = [];
    $remaining = $ids;

    try {
        $core = get_pdo('core');
        $coreMap = notes_fetch_users_from($core, $remaining);
        $map = $coreMap;
        if ($coreMap) {
            $remaining = array_values(array_diff($remaining, array_keys($coreMap)));
        }
    } catch (Throwable $e) {
        // ignore; fall back to apps DB
    }

    if ($remaining) {
        try {
            $appsMap = notes_fetch_users_from(get_pdo(), $remaining);
            $map = $map + $appsMap;
            if ($appsMap) {
                $remaining = array_values(array_diff($remaining, array_keys($appsMap)));
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($remaining) {
        foreach ($remaining as $id) {
            $map[$id] = 'User #'.$id;
        }
    }

    return $map;
}

function notes_get_share_details(int $noteId): array {
    $ids = notes_get_share_user_ids($noteId);
    if (!$ids) return [];
    $labels = notes_fetch_users_map($ids);
    $out = [];
    foreach ($ids as $id) {
        $out[] = [
            'id'    => $id,
            'label' => $labels[$id] ?? ('User #'.$id),
        ];
    }
    return $out;
}

function notes_can_view(array $note): bool {
    $role = current_user_role_key();
    if ($role === 'root' || $role === 'admin') return true;

    $meId = (int)(current_user()['id'] ?? 0);
    if ($meId <= 0) return false;

    // Owner?
    if ((int)($note['user_id'] ?? 0) === $meId) return true;

    // Shared with me?
    if (!isset($note['id'])) return false;
    try {
        $pdo = get_pdo();
        $col = notes__shares_column($pdo);
        if (!$col) {
            return false;
        }
        $value = $col === 'user_id' ? $meId : (string)$meId;
        $st = $pdo->prepare("SELECT 1 FROM notes_shares WHERE note_id = ? AND $col = ? LIMIT 1");
        $st->execute([(int)$note['id'], $value]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}


function notes_can_edit(array $note): bool {
    if (!can('edit')) return false;
    $role = current_user_role_key();
    if ($role === 'root' || $role === 'admin') return true;
    $meId = (int)(current_user()['id'] ?? 0);
    return (int)($note['user_id'] ?? 0) === $meId;
}

function notes_can_share(array $note): bool {
    $role = current_user_role_key();
    if (in_array($role, ['root','admin'], true)) return true;
    $meId = (int)(current_user()['id'] ?? 0);
    return (int)($note['user_id'] ?? 0) === $meId;
}

/* ---------- comments ---------- */

function notes_comments_table_exists(?PDO $pdo = null): bool {
    $pdo = $pdo ?: get_pdo();
    return notes__table_exists($pdo, 'note_comments');
}

function notes_comment_fetch(int $commentId): ?array {
    $pdo = get_pdo();
    if (!notes_comments_table_exists($pdo)) return null;
    $st = $pdo->prepare('SELECT * FROM note_comments WHERE id = ?');
    $st->execute([$commentId]);
    $row = $st->fetch();
    return $row ?: null;
}

function notes_comment_insert(int $noteId, int $userId, string $body, ?int $parentId = null): int {
    $pdo = get_pdo();
    if (!notes_comments_table_exists($pdo)) {
        throw new RuntimeException('Comments table not available.');
    }

    $parentId = $parentId ? (int)$parentId : null;
    if ($parentId) {
        $parent = notes_comment_fetch($parentId);
        if (!$parent || (int)$parent['note_id'] !== $noteId) {
            throw new RuntimeException('Invalid parent comment.');
        }
    }

    $st = $pdo->prepare(
        'INSERT INTO note_comments (note_id, user_id, parent_id, body)
         VALUES (:note_id, :user_id, :parent_id, :body)'
    );
    $st->execute([
        ':note_id'  => $noteId,
        ':user_id'  => $userId,
        ':parent_id'=> $parentId,
        ':body'     => $body,
    ]);

    return (int)$pdo->lastInsertId();
}

function notes_comment_delete(int $commentId): void {
    $pdo = get_pdo();
    if (!notes_comments_table_exists($pdo)) return;
    $st = $pdo->prepare('DELETE FROM note_comments WHERE id = ?');
    $st->execute([$commentId]);
}

function notes_comment_can_delete(array $comment, array $note): bool {
    $role = current_user_role_key();
    if (in_array($role, ['root','admin'], true)) return true;
    $meId = (int)(current_user()['id'] ?? 0);
    if ($meId <= 0) return false;
    if ((int)$comment['user_id'] === $meId) return true;
    return (int)($note['user_id'] ?? 0) === $meId;
}

function notes_fetch_comments(int $noteId): array {
    $pdo = get_pdo();
    if (!notes_comments_table_exists($pdo)) return [];

    $st = $pdo->prepare('SELECT * FROM note_comments WHERE note_id = ? ORDER BY created_at ASC, id ASC');
    $st->execute([$noteId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    $userIds = [];
    foreach ($rows as $row) {
        $userIds[] = (int)($row['user_id'] ?? 0);
    }
    $userMap = notes_fetch_users_map($userIds);

    foreach ($rows as &$row) {
        $uid = (int)($row['user_id'] ?? 0);
        $row['author_label'] = $userMap[$uid] ?? ('User #'.$uid);
    }
    unset($row);

    return $rows;
}

function notes_fetch_comment_threads(int $noteId): array {
    $rows = notes_fetch_comments($noteId);
    if (!$rows) return [];

    $byId = [];
    foreach ($rows as $row) {
        $row['children'] = [];
        $byId[(int)$row['id']] = $row;
    }

    $tree = [];
    foreach ($byId as $id => &$row) {
        $parentId = (int)($row['parent_id'] ?? 0);
        if ($parentId && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = &$row;
        } else {
            $tree[] = &$row;
        }
    }
    unset($row);

    return $tree;
}

function notes_comment_count(int $noteId): int {
    $pdo = get_pdo();
    if (!notes_comments_table_exists($pdo)) return 0;
    $st = $pdo->prepare('SELECT COUNT(*) FROM note_comments WHERE note_id = ?');
    $st->execute([$noteId]);
    return (int)$st->fetchColumn();
}