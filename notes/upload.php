<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

header('Content-Type: application/json');

function fail(int $code, string $msg) {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'Method not allowed');
}
if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
    fail(422, 'Invalid CSRF token');
}

$noteId   = isset($_POST['note_id']) ? (int)$_POST['note_id'] : 0;
$position = isset($_POST['position']) ? (int)$_POST['position'] : 0;

if ($noteId <= 0 || !in_array($position, [1,2,3], true)) {
    fail(422, 'Bad note_id or position');
}

$note = notes_fetch($noteId);
if (!$note || !notes_can_edit($note)) {
    fail(403, 'Forbidden');
}

if (!isset($_FILES['photo'])) {
    fail(422, 'No file field "photo"');
}
$err = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    $map = [
        UPLOAD_ERR_INI_SIZE=>'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE=>'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL=>'Partial upload',
        UPLOAD_ERR_NO_FILE=>'No file sent',
        UPLOAD_ERR_NO_TMP_DIR=>'Missing tmp dir',
        UPLOAD_ERR_CANT_WRITE=>'Disk write failed',
        UPLOAD_ERR_EXTENSION=>'PHP extension blocked upload',
    ];
    fail(422, $map[$err] ?? ('Upload error code '.$err));
}

try {
    $saved = notes_save_uploaded_photo($noteId, $position, 'photo');
    echo json_encode(['ok'=>true,'url'=>$saved['url'],'position'=>$position]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Upload failed: '.$e->getMessage()]);
}
