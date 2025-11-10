<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_with_message('index.php', 'Invalid request method.', 'error');
}

if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
    redirect_with_message('index.php', 'Invalid CSRF token.', 'error');
}

$noteId  = (int)($_POST['note_id'] ?? 0);
$photoId = (int)($_POST['photo_id'] ?? 0);

$note = notes_fetch($noteId);
if (!$note || !notes_can_edit($note)) {
    redirect_with_message('index.php', 'Forbidden.', 'error');
}

try {
    notes_remove_photo_by_id($photoId);
    log_event('note.photo.remove', 'note', $noteId, ['photo_id'=>$photoId]);
    redirect_with_message('edit.php?id='.$noteId, 'Photo removed.', 'success');
} catch (Throwable $e) {
    redirect_with_message('edit.php?id='.$noteId, 'Failed to remove photo.', 'error');
}
