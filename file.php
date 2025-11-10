<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_login();

$key = $_GET['key'] ?? '';
if ($key === '' || str_contains($key, '..')) {
    http_response_code(400);
    echo 'Bad key';
    exit;
}

try {
    $s3 = s3_client();

    // Optional: set Content-Type correctly
    $head = $s3->headObject(['Bucket' => S3_BUCKET, 'Key' => $key]);
    $obj  = $s3->getObject(['Bucket' => S3_BUCKET, 'Key' => $key]);

    // Clean any output buffers so headers + binary aren’t corrupted
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: ' . ($head['ContentType'] ?? 'application/octet-stream'));
    if (isset($head['ContentLength'])) {
        header('Content-Length: ' . $head['ContentLength']);
    }
    header('Cache-Control: public, max-age=604800, immutable');

    echo (string)$obj['Body'];
} catch (Throwable $e) {
    error_log('file.php error for key '.$key.': '.$e->getMessage());
    http_response_code(404);
    echo 'Not found';
}
