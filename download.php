<?php
require_once __DIR__ . '/helpers.php';
require_login();
$key = $_GET['key'] ?? '';
if ($key === '') {
    http_response_code(400);
    exit('Missing key');
}
log_event('download', 'photo', null, ['key' => $key]);
header('Location: ' . s3_object_url($key));
exit;
