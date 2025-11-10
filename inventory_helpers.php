<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Upload arbitrary binary/text data to the configured S3/MinIO bucket.
 *
 * @return array{key:string,url:string}
 */
function inventory_s3_upload(string $contents, string $mime, string $filename, string $prefix = 'inventory/'): array
{
    if (!class_exists(Aws\S3\S3Client::class)) {
        throw new RuntimeException('S3 client is not available. Run composer install.');
    }
    $client = s3_client();
    $safePrefix = trim($prefix, '/');
    $safePrefix = $safePrefix !== '' ? $safePrefix . '/' : '';
    $key = $safePrefix . date('Y/m/d/') . bin2hex(random_bytes(8)) . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);

    $client->putObject([
        'Bucket'      => S3_BUCKET,
        'Key'         => $key,
        'Body'        => $contents,
        'ContentType' => $mime,
        'ACL'         => 'private',
    ]);

    return [
        'key' => $key,
        'url' => s3_object_url($key),
    ];
}

/**
 * Upload a file from the $_FILES matrix.
 *
 * @return array{key:string,url:string,mime:string,size:int}
 */
function inventory_s3_upload_file(array $file, string $prefix = 'inventory/uploads/'): array
{
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed with error code ' . (int)($file['error'] ?? 0));
    }
    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Temporary upload missing.');
    }
    $contents = file_get_contents($tmpName);
    if ($contents === false) {
        throw new RuntimeException('Unable to read uploaded file.');
    }
    $mime = (string)($file['type'] ?? 'application/octet-stream');
    $filename = (string)($file['name'] ?? 'upload');

    $upload = inventory_s3_upload($contents, $mime, $filename, $prefix);
    $upload['mime'] = $mime;
    $upload['size'] = (int)($file['size'] ?? strlen($contents));
    return $upload;
}

function inventory_adjust_stock(PDO $pdo, int $itemId, ?int $sectorId, int $delta): void
{
    if ($sectorId === null) {
        return;
    }
    $stmt = $pdo->prepare('SELECT quantity FROM inventory_stock WHERE item_id = ? AND sector_id = ?');
    $stmt->execute([$itemId, $sectorId]);
    $row = $stmt->fetch();
    if ($row) {
        $newQty = max(0, (int)$row['quantity'] + $delta);
        $pdo->prepare('UPDATE inventory_stock SET quantity = :q WHERE item_id = :i AND sector_id = :s')
            ->execute([':q' => $newQty, ':i' => $itemId, ':s' => $sectorId]);
    } else {
        $pdo->prepare('INSERT INTO inventory_stock (item_id, sector_id, quantity) VALUES (:i,:s,:q)')
            ->execute([':i' => $itemId, ':s' => $sectorId, ':q' => max(0, $delta)]);
    }
}

function inventory_sector_name(array $sectors, $id): string
{
    foreach ($sectors as $s) {
        if ((string)($s['id'] ?? '') === (string)$id) {
            return (string)($s['name'] ?? '');
        }
    }
    return '';
}

/**
 * Ensure a public signing token exists for a movement and return it with absolute URL.
 *
 * @return array{token:string,url:string,expires_at:string}
 */
function inventory_ensure_public_token(PDO $pdo, int $movementId, int $ttlDays = 14): array
{
    $stmt = $pdo->prepare('SELECT token, expires_at FROM inventory_public_tokens WHERE movement_id = :id AND expires_at >= NOW() ORDER BY expires_at DESC LIMIT 1');
    $stmt->execute([':id' => $movementId]);
    $row = $stmt->fetch();
    if ($row) {
        $token = (string)$row['token'];
        $expires = (string)$row['expires_at'];
    } else {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = (new DateTimeImmutable('+' . $ttlDays . ' days'))->format('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO inventory_public_tokens (movement_id, token, expires_at) VALUES (:id,:token,:expires)')
            ->execute([':id' => $movementId, ':token' => $token, ':expires' => $expires]);
    }
    $url = rtrim(BASE_URL, '/') . '/inventory_sign.php?token=' . rawurlencode($token);
    return ['token' => $token, 'url' => $url, 'expires_at' => $expires];
}

function inventory_qr_data_uri(string $url, int $size = 180): ?string
{
    if ($url === '') {
        return null;
    }
    if (function_exists('QRcode')) {
        ob_start();
        $level = defined('QR_ECLEVEL_L') ? QR_ECLEVEL_L : 'L';
        $scale = max(1, (int)round($size / 37));
        QRcode::png($url, null, $level, $scale);
        $data = ob_get_clean();
        if ($data === false) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode($data);
    }
    $endpoint = 'https://quickchart.io/qr';
    $query = http_build_query(['text' => $url, 'size' => $size . 'x' . $size, 'light' => 'ffffff']);
    return $endpoint . '?' . $query;
}

/**
 * Generate a PDF transfer form, upload to S3 and update the movement rows.
 *
 * @param array<int, array<string,mixed>> $movements Newly created movements.
 * @param array<int, mixed> $lineItems  Describes each item moved.
 */
function inventory_generate_transfer_pdf(PDO $pdo, array $movements, array $lineItems, array $sectors, array $initiator): array
{
    if (!class_exists(Dompdf::class)) {
        throw new RuntimeException('Dompdf library not available.');
    }
    if (!$movements) {
        throw new InvalidArgumentException('No movements provided for PDF generation.');
    }
    $token = null;
    foreach ($movements as $idx => $movement) {
        $tokenRow = inventory_ensure_public_token($pdo, (int)$movement['id']);
        if ($idx === 0) {
            $token = $tokenRow;
        }
    }
    $primary = $movements[0];
    if ($token === null) {
        $token = inventory_ensure_public_token($pdo, (int)$primary['id']);
    }
    $qr = inventory_qr_data_uri($token['url'], 240);

    $initiatorName = trim((string)($initiator['name'] ?? ($initiator['full_name'] ?? '')));
    if ($initiatorName === '') {
        $initiatorName = trim((string)($initiator['email'] ?? ''));
    }
    if ($initiatorName === '') {
        $initiatorName = 'Inventory User';
    }

    $sourceSector = '';
    $targetSector = '';
    if (!empty($primary['source_sector_id'])) {
        $sourceSector = inventory_sector_name($sectors, $primary['source_sector_id']);
    }
    if (!empty($primary['target_sector_id'])) {
        $targetSector = inventory_sector_name($sectors, $primary['target_sector_id']);
    }

    $html = '<html><head><meta charset="utf-8"><style>' .
        'body{font-family:"DejaVu Sans",sans-serif;color:#1f2937;margin:32px;font-size:12px;}' .
        '.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;}' .
        '.title{font-size:22px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#111827;}' .
        '.meta{font-size:12px;line-height:1.5;color:#374151;}' .
        'table{width:100%;border-collapse:collapse;margin-top:16px;}' .
        'th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;}' .
        'th{background:#111827;color:#f9fafb;font-size:12px;letter-spacing:.05em;text-transform:uppercase;}' .
        '.sig-row{display:flex;gap:32px;margin-top:36px;}' .
        '.sig-box{flex:1;border-top:2px solid #1f2937;padding-top:8px;min-height:80px;}' .
        '.sig-label{font-weight:600;text-transform:uppercase;font-size:11px;color:#1f2937;letter-spacing:.08em;}' .
        '.qr{margin-top:24px;text-align:right;}' .
        '.qr img{width:160px;height:160px;}' .
        '.badge{display:inline-block;border-radius:999px;padding:2px 10px;background:#eef2ff;color:#1e3a8a;font-weight:600;margin-left:6px;font-size:11px;}' .
        '.notes{margin-top:24px;font-size:12px;color:#4b5563;line-height:1.6;background:#f8fafc;padding:14px;border-radius:12px;border:1px solid #e2e8f0;}' .
        '</style></head><body>';
    $html .= '<div class="header">'
        . '<div>'
        . '<div class="title">Inventory Transfer Form</div>'
        . '<div class="meta">Transfer ID <strong>#' . (int)$primary['id'] . '</strong><br>'
        . 'Date ' . htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') . '<br>'
        . 'Initiated by ' . htmlspecialchars($initiatorName, ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div>';
    if ($qr) {
        $html .= '<div class="qr">';
        if (str_starts_with($qr, 'data:')) {
            $html .= '<img src="' . $qr . '" alt="QR">';
        } else {
            $html .= '<img src="' . htmlspecialchars($qr, ENT_QUOTES, 'UTF-8') . '" alt="QR">';
        }
        $html .= '<div style="font-size:10px;color:#6b7280;margin-top:6px;">Scan to sign digitally</div>';
        $html .= '</div>';
    }
    $html .= '</div>';

    if ($sourceSector !== '' || $targetSector !== '') {
        $html .= '<div class="meta">';
        if ($sourceSector !== '') {
            $html .= '<div>From <strong>' . htmlspecialchars($sourceSector, ENT_QUOTES, 'UTF-8') . '</strong></div>';
        }
        if ($targetSector !== '') {
            $html .= '<div>To <strong>' . htmlspecialchars($targetSector, ENT_QUOTES, 'UTF-8') . '</strong></div>';
        }
        $html .= '</div>';
    }

    $html .= '<table><thead><tr>'
        . '<th style="width:40%;">Item</th>'
        . '<th>SKU</th>'
        . '<th>Quantity</th>'
        . '<th>Direction</th>'
        . '<th>Notes</th>'
        . '</tr></thead><tbody>';

    foreach ($lineItems as $item) {
        $html .= '<tr>'
            . '<td>' . htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars((string)($item['sku'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . (int)($item['amount'] ?? 0) . '</td>'
            . '<td>' . htmlspecialchars(strtoupper((string)($item['direction'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars((string)($item['reason'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<div class="sig-row">'
        . '<div class="sig-box"><div class="sig-label">Source Signature</div></div>'
        . '<div class="sig-box"><div class="sig-label">Receiving Signature</div></div>'
        . '</div>';

    $html .= '<div class="notes">'
        . 'This document confirms the movement of the above-listed inventory items. '
        . 'Both parties must sign the transfer to acknowledge responsibility. '
        . 'Digital signatures collected through the QR code are automatically archived.'
        . '</div>';

    $html .= '</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdf = $dompdf->output();

    $upload = inventory_s3_upload($pdf, 'application/pdf', 'transfer-' . (int)$primary['id'] . '.pdf', 'inventory/transfers');

    $stmt = $pdo->prepare('UPDATE inventory_movements SET transfer_form_key = :k, transfer_form_url = :u WHERE id = :id');
    foreach ($movements as $movement) {
        $stmt->execute([':k' => $upload['key'], ':u' => $upload['url'], ':id' => (int)$movement['id']]);
    }

    return $upload + ['token' => $token['token'], 'token_url' => $token['url']];
}

function inventory_store_movement_file(PDO $pdo, int $movementId, array $upload, ?string $label, string $kind, ?int $userId): void
{
    $pdo->prepare('INSERT INTO inventory_movement_files (movement_id, file_key, file_url, mime, label, kind, uploaded_by) VALUES (:mid,:key,:url,:mime,:label,:kind,:uid)')
        ->execute([
            ':mid'   => $movementId,
            ':key'   => $upload['key'],
            ':url'   => $upload['url'],
            ':mime'  => $upload['mime'] ?? null,
            ':label' => $label,
            ':kind'  => $kind,
            ':uid'   => $userId,
        ]);
}

function inventory_fetch_movements(PDO $pdo, array $itemIds): array
{
    if (!$itemIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM inventory_movements WHERE item_id IN ($placeholders) ORDER BY ts DESC");
    $stmt->execute(array_values($itemIds));
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['item_id']][] = $row;
    }
    return $grouped;
}

function inventory_fetch_movement_files(PDO $pdo, array $movementIds): array
{
    if (!$movementIds) {
        return [];
    }
    $movementIds = array_values(array_unique(array_map('intval', $movementIds)));
    $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM inventory_movement_files WHERE movement_id IN ($placeholders) ORDER BY uploaded_at");
    $stmt->execute($movementIds);
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['movement_id']][] = $row;
    }
    return $grouped;
}

function inventory_fetch_public_tokens(PDO $pdo, array $movementIds): array
{
    if (!$movementIds) {
        return [];
    }
    $movementIds = array_values(array_unique(array_map('intval', $movementIds)));
    $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM inventory_public_tokens WHERE movement_id IN ($placeholders)");
    $stmt->execute($movementIds);
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['movement_id']][] = $row;
    }
    return $grouped;
}
