<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_perm('edit');

$title = 'New Task';

/* ---------- helpers: inline photo save with S3-or-local fallback ---------- */

// Map common camera types incl. HEIC; allow finfo “octet-stream” and resolve by extension
function ext_for_mime(string $mime): ?string {
    static $map = [
        'image/jpeg'          => 'jpg',
        'image/png'           => 'png',
        'image/webp'          => 'webp',
        'image/heic'          => 'heic',
        'image/heif'          => 'heic',
        'image/heic-sequence' => 'heic',
        'image/heif-sequence' => 'heic',
        'application/octet-stream' => null, // will fallback by filename
    ];
    return $map[$mime] ?? null;
}

/** Resolve (ext, mime) from tmp file + original name, with fallback to filename extension */
function resolve_ext_and_mime(string $tmpPath, string $originalName): array {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? (string)finfo_file($finfo, $tmpPath) : 'application/octet-stream';
    if ($finfo) { finfo_close($finfo); }

    $ext = ext_for_mime($mime);

    if ($ext === null) {
        $fnExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($fnExt, ['jpg','jpeg','png','webp','heic','heif'], true)) {
            $ext = $fnExt === 'jpeg' ? 'jpg' : ($fnExt === 'heif' ? 'heic' : $fnExt);
            if ($mime === 'application/octet-stream') {
                if     ($ext === 'jpg')  $mime = 'image/jpeg';
                elseif ($ext === 'png')  $mime = 'image/png';
                elseif ($ext === 'webp') $mime = 'image/webp';
                elseif ($ext === 'heic') $mime = 'image/heic';
            }
        }
    }
    return [$ext, $mime];
}

/** Save uploaded photo for a given slot (1..3). Return ['url'=>string] or throw on errors. */
function save_inline_photo_if_present(int $taskId, int $position, string $field) {
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // nothing uploaded for this slot
    }

    $err = (int)$_FILES[$field]['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE=>'file exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE=>'file exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL=>'partial upload',
            UPLOAD_ERR_NO_TMP_DIR=>'missing tmp dir',
            UPLOAD_ERR_CANT_WRITE=>'disk write failed',
            UPLOAD_ERR_EXTENSION=>'blocked by extension',
        ];
        throw new RuntimeException("Upload error for $field: " . ($map[$err] ?? "code $err"));
    }

    $tmp   = (string)($_FILES[$field]['tmp_name'] ?? '');
    $size  = (int)($_FILES[$field]['size'] ?? 0);
    $oname = (string)($_FILES[$field]['name'] ?? '');

    if ($size <= 0)             throw new RuntimeException("Empty file for $field");
    if ($size > 70*1024*1024)   throw new RuntimeException("File too large (>70MB) for $field");

    // Determine ext + mime (supports HEIC + extension fallback)
    [$ext, $mime] = resolve_ext_and_mime($tmp, $oname);
    if (!$ext) throw new RuntimeException("Unsupported type for $field");

    // Build storage key
    $uuid = bin2hex(random_bytes(8));
    $key  = sprintf('tasks/%d/%s-%d.%s', $taskId, $uuid, $position, $ext);

    $url = null;

    // Prefer S3 if SDK available & config looks valid; otherwise fall back to local
    $s3Available = class_exists(\Aws\S3\S3Client::class) && S3_BUCKET !== '' && S3_ENDPOINT !== '';

    if ($s3Available) {
        try {
            // Stream directly from tmp file (no big memory copy)
            s3_client()->putObject([
                'Bucket'      => S3_BUCKET,
                'Key'         => $key,
                'SourceFile'  => $tmp,
                'ContentType' => $mime,
            ]);
            $url = s3_object_url($key);
        } catch (Throwable $e) {
            // Fall back to local storage if S3 fails
            $s3Available = false;
        }
    }

    if (!$s3Available) {
        // Local path: ./uploads/tasks/<taskId>/...
        $base = __DIR__ . '/uploads';
        $dir  = $base . '/tasks/' . $taskId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Failed to create uploads directory');
        }
        $dest = $dir . '/' . basename($key);
        // Move the uploaded file; if it fails, copy as fallback
        if (!@move_uploaded_file($tmp, $dest)) {
            $bytes = @file_get_contents($tmp);
            if ($bytes === false || !@file_put_contents($dest, $bytes)) {
                throw new RuntimeException('Failed to write local file');
            }
        }
        // Public URL (served by Nginx/Apache as static)
        $url = '/uploads/tasks/' . $taskId . '/' . basename($dest);
    }

    // Upsert DB row pointing to the chosen storage
    upsert_photo($taskId, $position, $key, $url);

    return ['url' => $url];
}

/** Resolve a room_id from typed room number within a building. */
function resolve_room_id_for_building(int $buildingId, string $roomNumber): ?int {
    $roomNumber = trim($roomNumber);
    if ($buildingId <= 0 || $roomNumber === '') return null;

    $pdo = get_pdo();
    // room_number is typically stored as text (e.g., "101", "B-12")
    $st = $pdo->prepare('SELECT id FROM rooms WHERE building_id = :b AND room_number = :n LIMIT 1');
    $st->execute([':b' => $buildingId, ':n' => $roomNumber]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/* ---------- handle form ---------- */

$errors = [];
$warns  = [];

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        // Collect minimal fields (status/due_date/assigned_to removed from UI; default server-side)
        $buildingId  = (int)($_POST['building_id'] ?? 0);
        $roomNumber  = trim((string)($_POST['room_number'] ?? ''));
        $title       = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        // Resolve room_id from (building_id, room_number)
        $roomId = resolve_room_id_for_building($buildingId, $roomNumber);
        if ($roomId === null) {
            $errors[] = 'Room number not found in the selected building.';
        }

        // Build payload expected by your existing insert_task()
        $data = [
            'building_id' => $buildingId,
            'room_id'     => (int)($roomId ?? 0),
            'title'       => $title,
            'description' => $description,
            // Defaults for removed fields (keep backend happy)
            'priority'    => (string)($_POST['priority'] ?? ''), // still shown in UI
            'assigned_to' => '',          // removed from UI
            'status'      => 'open',      // removed from UI
            'due_date'    => '',          // removed from UI
            'created_by'  => current_user()['id'] ?? null,
        ];

        // Validate basics (uses your existing helper)
        $fieldErrors = [];
        if (!validate_task_payload($data, $fieldErrors)) {
            $errors = array_merge($errors, array_values($fieldErrors));
        }

        if (!$errors) {
            try {
                // 1) Create the task
                $taskId = insert_task($data);
                log_event('task.create', 'task', $taskId);

                try {
                    $creatorId  = isset($data['created_by']) ? (int)$data['created_by'] : null;
                    $assigneeId = resolve_notification_user_id($data['assigned_to'] ?? null);
                    if ($creatorId || $assigneeId) {
                        task_subscribe_participants($taskId, $creatorId, $assigneeId);
                    }
                } catch (Throwable $notifyErr) {
                    error_log('task_new notification bootstrap failed: ' . $notifyErr->getMessage());
                }

                // 2) Try to save each photo (best-effort; don’t fail the whole request)
                for ($i = 1; $i <= 3; $i++) {
                    try {
                        save_inline_photo_if_present($taskId, $i, "photo$i");
                    } catch (Throwable $pe) {
                        $warns[] = "Photo $i: " . $pe->getMessage();
                    }
                }

                // 3) Redirect with flash (include warnings if any)
                $msg = 'Task created.';
                if ($warns) $msg .= ' Some photos failed: ' . implode(' | ', $warns);
                redirect_with_message('task_edit.php?id=' . $taskId, $msg, $warns ? 'warning' : 'success');
            } catch (Throwable $e) {
                $errors[] = 'Failed to create task. ' . $e->getMessage();
            }
        }
    }
}

$buildings = fetch_buildings();

include __DIR__ . '/includes/header.php';
?>

<section class="card">
    <h1>Create Task</h1>
    <?php if ($errors): ?>
        <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="grid two">
        <label>Building
            <select
              name="building_id"
              id="building_id"
              required
              data-room-source
              data-room-input="room_number"
              data-room-datalist="room-suggestions">
                <option value="">Select building…</option>
                <?php foreach ($buildings as $b): ?>
                    <option value="<?php echo (int)$b['id']; ?>"><?php echo sanitize($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Room Number
            <input
              type="text"
              name="room_number"
              inputmode="numeric"
              pattern="[0-9A-Za-z\- ]+"
              placeholder="e.g. 101"
              list="room-suggestions"
              required
            >
            <datalist id="room-suggestions"></datalist>
            <small class="muted">Suggestions populate after you pick a building.</small>
        </label>

        <label>Title
            <input type="text" name="title" required>
        </label>

        <label>Priority
            <select name="priority">
                <?php foreach (get_priorities() as $p): ?>
                    <option value="<?php echo sanitize($p); ?>"><?php echo sanitize(priority_label($p)); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field-span-2">Description
            <textarea name="description" rows="4" placeholder="What needs doing?"></textarea>
        </label>

        <!-- Three photo slots (camera friendly on mobile) -->
        <div class="field-span-2">
            <div class="photo-upload-inline">
                <label>Photo 1
                    <input type="file" name="photo1" accept="image/*,image/heic,image/heif" capture="environment" class="file-compact">
                </label>
                <label>Photo 2
                    <input type="file" name="photo2" accept="image/*,image/heic,image/heif" capture="environment" class="file-compact">
                </label>
                <label>Photo 3
                    <input type="file" name="photo3" accept="image/*,image/heic,image/heif" capture="environment" class="file-compact">
                </label>
            </div>
            <p class="muted small">JPG/PNG/WebP/HEIC, up to 70&nbsp;MB each.</p>
        </div>

        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">

        <div class="form-actions-compact field-span-2">
            <button class="btn primary" type="submit">Create Task</button>
            <a class="btn secondary" href="tasks.php">Cancel</a>
        </div>
    </form>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>