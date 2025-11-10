<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_login();

header('Content-Type: application/json');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'Method not allowed');
}
if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
    fail(422, 'Invalid CSRF token');
}
if (!can('edit')) {
    fail(403, 'Forbidden');
}

$taskId   = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$position = isset($_POST['position']) ? (int)$_POST['position'] : 0;

if ($taskId <= 0 || !in_array($position, [1,2,3], true)) {
    fail(422, 'Bad task_id or position');
}

// (Optional but recommended) ensure task exists
if (!function_exists('fetch_task') || !fetch_task($taskId)) {
    // comment the next line if you don't want this check
    // fail(422, 'Task does not exist');
}

if (!isset($_FILES['photo'])) {
    fail(422, 'No file field "photo"');
}
$err = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'Partial upload',
        UPLOAD_ERR_NO_FILE    => 'No file sent',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing tmp dir',
        UPLOAD_ERR_CANT_WRITE => 'Disk write failed',
        UPLOAD_ERR_EXTENSION  => 'PHP extension blocked upload',
    ];
    fail(422, $map[$err] ?? ('Upload error code ' . $err));
}

$tmp  = $_FILES['photo']['tmp_name'] ?? '';
$size = (int)($_FILES['photo']['size'] ?? 0);
$name = (string)($_FILES['photo']['name'] ?? '');

if ($size <= 0)           fail(422, 'Empty file');
if ($size > 70*1024*1024) fail(422, 'File too large (max 70MB)');

$allowed = [
    'image/jpeg'          => 'jpg',
    'image/png'           => 'png',
    'image/webp'          => 'webp',
    // HEIC / HEIF variants commonly seen from iOS
    'image/heic'          => 'heic',
    'image/heif'          => 'heic',
    'image/heic-sequence' => 'heic',
    'image/heif-sequence' => 'heic',
    // Some devices (or misconfigured servers) report this; we’ll fallback to extension check
    'application/octet-stream' => null, // handled below
];

// Detect mime via finfo
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = (string)finfo_file($finfo, $tmp);
finfo_close($finfo);

// Resolve extension
$ext = $allowed[$mime] ?? null;

// Fallback if MIME is octet-stream or unknown: use filename extension
if ($ext === null) {
    $fnExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($fnExt, ['jpg','jpeg','png','webp','heic','heif'], true)) {
        $ext = ($fnExt === 'jpeg') ? 'jpg' : ($fnExt === 'heif' ? 'heic' : $fnExt);
        // set a sensible MIME if finfo failed
        if ($mime === 'application/octet-stream') {
            if ($ext === 'jpg') {
                $mime = 'image/jpeg';
            } elseif ($ext === 'png') {
                $mime = 'image/png';
            } elseif ($ext === 'webp') {
                $mime = 'image/webp';
            } elseif ($ext === 'heic') {
                $mime = 'image/heic';
            } else {
                $mime = 'application/octet-stream';
            }
        }
    }
}

if (!$ext) {
    fail(422, 'Unsupported file type: ' . $mime);
}

$uuid = bin2hex(random_bytes(8));
$key  = sprintf('tasks/%d/%s-%d.%s', $taskId, $uuid, $position, $ext);

try {
    // Prefer streaming from tmp file instead of loading into memory
    $s3 = s3_client();
    $args = [
        'Bucket'      => S3_BUCKET,
        'Key'         => $key,
        'SourceFile'  => $tmp,
        'ContentType' => $mime,
        // If your bucket is private and s3_object_url() makes signed URLs, keep ACL omitted.
        // If you need public-read objects, uncomment the next line:
        // 'ACL'      => 'public-read',
    ];
    $s3->putObject($args);

    $url = s3_object_url($key);
    upsert_photo($taskId, $position, $key, $url);

    log_event('photo.upload', 'photo', $taskId, ['key' => $key, 'position' => $position, 'mime' => $mime]);
    echo json_encode(['ok'=>true,'url'=>$url,'position'=>$position]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Upload failed: '.$e->getMessage()]);
}
