<?php
// /export_room_pdf_py.php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_login();

/* ---------------------------------------------
   Settings (you can tweak via query-string)
   --------------------------------------------- */
$ttlDays = max(1, min(365, (int)($_GET['ttl'] ?? 30)));
$qrSize  = max(120, min(300, (int)($_GET['qr']  ?? 160)));

/* ---------------------------------------------
   Token tables (ROOM scope) – same as your PHP
   --------------------------------------------- */
function ensure_public_room_token_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_room_tokens (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          room_id BIGINT UNSIGNED NOT NULL,
          token VARBINARY(32) NOT NULL,
          expires_at DATETIME NOT NULL,
          revoked TINYINT(1) NOT NULL DEFAULT 0,
          use_count INT UNSIGNED NOT NULL DEFAULT 0,
          last_used_at DATETIME NULL,
          UNIQUE KEY uniq_token (token),
          INDEX idx_room_exp (room_id, expires_at),
          INDEX idx_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public_room_hits (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          token_id BIGINT UNSIGNED NOT NULL,
          room_id BIGINT UNSIGNED NOT NULL,
          ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          ip VARBINARY(16) NULL,
          ua VARCHAR(255) NULL,
          INDEX idx_token (token_id),
          INDEX idx_room (room_id),
          CONSTRAINT fk_room_hits_token FOREIGN KEY (token_id)
            REFERENCES public_room_tokens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function random_token(): string { return b64url(random_bytes(16)); }

function fetch_valid_room_tokens(PDO $pdo, array $roomIds): array {
    if (!$roomIds) return [];
    $in  = implode(',', array_fill(0, count($roomIds), '?'));
    $sql = "SELECT t1.* FROM public_room_tokens t1
            JOIN (
              SELECT room_id, MAX(id) AS max_id
              FROM public_room_tokens
              WHERE revoked = 0 AND expires_at > NOW() AND room_id IN ($in)
              GROUP BY room_id
            ) t2 ON t1.id = t2.max_id";
    $st = $pdo->prepare($sql);
    $st->execute($roomIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) { $out[(int)$r['room_id']] = $r; }
    return $out;
}

function insert_room_token(PDO $pdo, int $roomId, int $ttlDays): array {
    $token  = random_token();
    $expiry = (new DateTimeImmutable('now'))->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');
    $st = $pdo->prepare("INSERT INTO public_room_tokens (room_id, token, expires_at) VALUES (:room,:tok,:exp)");
    $st->execute([':room'=>$roomId, ':tok'=>$token, ':exp'=>$expiry]);
    $id = (int)$pdo->lastInsertId();
    return ['id'=>$id,'room_id'=>$roomId,'token'=>$token,'expires_at'=>$expiry,'revoked'=>0,'use_count'=>0,'last_used_at'=>null];
}

/* ---------------------------------------------
   Build dataset (selected vs filters; allow ?room_id=)
   --------------------------------------------- */
$selectedIds = [];
if (!empty($_REQUEST['selected'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', (string)$_REQUEST['selected'])));
}

if ($selectedIds) {
    $tasks   = fetch_tasks_by_ids($selectedIds);
    $filters = [];
    $summary = 'Selected tasks: ' . implode(', ', $selectedIds);
} else {
    $filters = get_filter_values();

    // Force single-room export if provided ?room_id=123
    if (!empty($_GET['room_id'])) {
        $filters['room_id'] = (int)$_GET['room_id'];
    }

    $tasks   = export_tasks($filters);   // your existing export query (no photos)
    $summary = filter_summary($filters); // your existing summary
}

/* ---------------------------------------------
   Per-room public links (one QR per room)
   --------------------------------------------- */
$pdo = get_pdo();
ensure_public_room_token_tables($pdo);

$roomIds = [];
foreach ($tasks as $t) {
    if (!empty($t['room_id'])) $roomIds[(int)$t['room_id']] = true;
}
$roomIds = array_keys($roomIds);

$existing  = $roomIds ? fetch_valid_room_tokens($pdo, $roomIds) : [];
$baseUrl   = (function (): string {
    if (defined('APP_BASE_URL') && APP_BASE_URL) return rtrim(APP_BASE_URL, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $scheme . '://' . $host;
})();
$publicPath = '/public_room_photos.php';

$roomLinks = []; // room_id => URL
foreach ($roomIds as $rid) {
    $tokRow = $existing[$rid] ?? insert_room_token($pdo, $rid, $ttlDays);
    $token  = is_string($tokRow['token']) ? $tokRow['token'] : (string)$tokRow['token'];
    $roomLinks[$rid] = $baseUrl . $publicPath . '?t=' . rawurlencode($token);
}

/* ---------------------------------------------
   Normalize tasks for Python template
   --------------------------------------------- */
$payloadTasks = [];
foreach ($tasks as $t) {
    $payloadTasks[] = [
        'id'             => (int)$t['id'],
        'building_name'  => (string)($t['building_name'] ?? ''),
        'room_id'        => (int)($t['room_id'] ?? 0),
        'room_number'    => (string)($t['room_number'] ?? ''),
        'room_label'     => (string)($t['room_label'] ?? ''),
        'title'          => (string)($t['title'] ?? ''),
        'priority_label' => (string)priority_label($t['priority'] ?? ''),
        'status_label'   => (string)status_label($t['status'] ?? ''),
        'assigned_to'    => (string)($t['assigned_to'] ?? ''),
        'due_date'       => (string)($t['due_date'] ?? ''),
        'created_at'     => (string)($t['created_at'] ?? ''),
        'updated_at'     => (string)($t['updated_at'] ?? ''),
    ];
}

/* ---------------------------------------------
   Call Python WeasyPrint service and stream PDF
   --------------------------------------------- */
$pythonUrl = 'http://127.0.0.1:5001/export/table';

$payload = [
    'meta'       => ['summary' => $summary],
    'options'    => ['qr_size' => $qrSize],
    'tasks'      => $payloadTasks,
    'room_links' => $roomLinks, // keys are numeric room_id -> URL; server also accepts string keys
];

$ch = curl_init($pythonUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
]);
$body   = curl_exec($ch);
$errno  = curl_errno($ch);
$error  = curl_error($ch);
$http   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($errno || $http !== 200 || stripos($ctype, 'application/pdf') === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDF service error.\n";
    echo "HTTP: $http\n";
    if ($errno) echo "cURL error: $error\n";
    if ($body)  echo "\n--- Response body ---\n$body\n";
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="tasks-by-room.pdf"');
header('Cache-Control: no-store');
echo $body;
