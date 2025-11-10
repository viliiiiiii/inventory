<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function inventory_str_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function inventory_str_truncate(string $value, int $length): string
{
    if ($length <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length);
    }

    if (strlen($value) <= $length) {
        return $value;
    }

    return substr($value, 0, $length);
}

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
    if (is_string($label) && inventory_str_length($label) > 120) {
        $label = inventory_str_truncate($label, 120);
    }

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

/**
 * Compact signature metadata into a label string that fits the database column.
 */
function inventory_format_signature_label(array $metadata): string
{
    $role = (string)($metadata['role'] ?? '');
    $sectorName = trim((string)($metadata['sector_name'] ?? ''));
    $signer = trim((string)($metadata['signer'] ?? ''));
    $sectorChoice = (string)($metadata['sector_choice'] ?? '');
    $signedAt = (string)($metadata['signed_at'] ?? date('c'));

    if ($sectorName !== '') {
        $sectorName = inventory_str_truncate($sectorName, 48);
    }
    if ($signer !== '') {
        $signer = inventory_str_truncate($signer, 48);
    }
    if ($sectorChoice !== '') {
        $sectorChoice = inventory_str_truncate($sectorChoice, 24);
    }

    $payload = [
        'r'   => $role,
        'sid' => array_key_exists('sector_id', $metadata) ? $metadata['sector_id'] : null,
    ];

    if ($sectorChoice !== '') {
        $payload['sc'] = $sectorChoice;
    }
    if ($sectorName !== '') {
        $payload['sn'] = $sectorName;
    }
    if ($signer !== '') {
        $payload['sg'] = $signer;
    }
    if ($signedAt !== '') {
        $payload['ts'] = substr($signedAt, 0, 25);
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        $encoded = '';
    }

    if (inventory_str_length($encoded) > 118) {
        if (isset($payload['sn'])) {
            $payload['sn'] = inventory_str_truncate((string)$payload['sn'], 32);
        }
        if (isset($payload['sg'])) {
            $payload['sg'] = inventory_str_truncate((string)$payload['sg'], 32);
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    if ($encoded === '' || inventory_str_length($encoded) > 118) {
        $roleLabel = $role === 'target' ? 'Receiving' : 'Source';
        $fallback = $signer !== '' ? $signer : ($sectorName !== '' ? $sectorName : 'Signature');
        $encoded = 'Signature - ' . $roleLabel . ' - ' . inventory_str_truncate($fallback, 80);
    }

    if (inventory_str_length($encoded) > 120) {
        $encoded = inventory_str_truncate($encoded, 120);
    }

    return $encoded;
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

/**
 * Attempt to decode a structured signature label payload.
 *
 * @return array|null
 */
function inventory_decode_signature_label(?string $label): ?array
{
    if (!is_string($label) || trim($label) === '') {
        return null;
    }

    $decoded = json_decode($label, true);
    if (is_array($decoded)) {
        if (isset($decoded['r'])) {
            $role = (string)$decoded['r'];
            if ($role === 'receiving') {
                $role = 'target';
            }

            $sectorId = null;
            if (array_key_exists('sid', $decoded) && $decoded['sid'] !== null && $decoded['sid'] !== '') {
                $sectorId = (int)$decoded['sid'];
            }

            return [
                'role'         => $role,
                'sector_id'    => $sectorId,
                'sector_choice'=> $decoded['sc'] ?? null,
                'sector_name'  => $decoded['sn'] ?? '',
                'signer'       => $decoded['sg'] ?? '',
                'signed_at'    => $decoded['ts'] ?? null,
            ];
        }

        if (isset($decoded['role'])) {
            return $decoded;
        }
    }

    if (preg_match('/^(?:Digital signature|Uploaded copy|Signature) - (Source|Receiving) - (.+)$/', $label, $m)) {
        return [
            'role'        => strtolower($m[1]) === 'receiving' ? 'target' : 'source',
            'sector_name' => '',
            'signer'      => trim($m[2]),
        ];
    }

    return null;
}

/**
 * Fetch latest signature entries for a movement grouped by party role.
 *
 * @return array{source: array|null, target: array|null, extras: array<int,array>}
 */
function inventory_fetch_movement_signatures(PDO $pdo, int $movementId): array
{
    $stmt = $pdo->prepare('SELECT * FROM inventory_movement_files WHERE movement_id = :id AND kind = "signature" ORDER BY uploaded_at DESC, id DESC');
    $stmt->execute([':id' => $movementId]);
    $rows = $stmt->fetchAll();

    $map = [
        'source' => null,
        'target' => null,
        'extras' => [],
    ];

    foreach ($rows as $row) {
        $meta = inventory_decode_signature_label($row['label'] ?? null);
        if ($meta && isset($meta['role'])) {
            $role = $meta['role'];
            if ($role === 'receiving') {
                $role = 'target';
            }
            if ($role === 'target' || $role === 'source') {
                if (!isset($map[$role]) || !$map[$role]) {
                    $row['meta'] = $meta;
                    $map[$role] = $row;
                    continue;
                }
            }
        }
        $map['extras'][] = $row;
    }

    return $map;
}

/**
 * Download a stored S3/MinIO object body.
 */
function inventory_s3_fetch_object(string $key): string
{
    if (!class_exists(Aws\S3\S3Client::class)) {
        throw new RuntimeException('S3 client is not available.');
    }
    $client = s3_client();
    $result = $client->getObject([
        'Bucket' => S3_BUCKET,
        'Key'    => $key,
    ]);
    if (!isset($result['Body'])) {
        throw new RuntimeException('Object body missing for ' . $key);
    }

    return (string)$result['Body'];
}

/**
 * Regenerate a transfer PDF including captured signatures and mark the movement signed.
 */
function inventory_generate_signed_transfer_pdf(PDO $pdo, int $movementId, array $signatureMap): ?array
{
    if (!class_exists(Dompdf::class)) {
        throw new RuntimeException('Dompdf library not available.');
    }

    if (empty($signatureMap['source']) || empty($signatureMap['target'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM inventory_movements WHERE id = :id');
    $stmt->execute([':id' => $movementId]);
    $movement = $stmt->fetch();
    if (!$movement) {
        throw new RuntimeException('Movement not found.');
    }

    $groupKey = $movement['transfer_form_key'] ?? null;

    if ($groupKey) {
        $groupStmt = $pdo->prepare('SELECT m.*, i.name AS item_name, i.sku AS item_sku FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id WHERE m.transfer_form_key = :key ORDER BY m.ts');
        $groupStmt->execute([':key' => $groupKey]);
        $groupMovements = $groupStmt->fetchAll();
    } else {
        $groupStmt = $pdo->prepare('SELECT m.*, i.name AS item_name, i.sku AS item_sku FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id WHERE m.id = :id');
        $groupStmt->execute([':id' => $movementId]);
        $groupMovements = $groupStmt->fetchAll();
    }

    if (!$groupMovements) {
        throw new RuntimeException('Movements missing for PDF generation.');
    }

    $sectors = [];
    try {
        $corePdo = get_pdo('core');
        $sectors = (array)$corePdo->query('SELECT id,name FROM sectors')->fetchAll();
    } catch (Throwable $e) {
    }

    $lineItems = [];
    foreach ($groupMovements as $row) {
        $lineItems[] = [
            'name'      => $row['item_name'] ?? '',
            'sku'       => $row['item_sku'] ?? '',
            'amount'    => $row['amount'],
            'direction' => $row['direction'],
            'reason'    => $row['reason'] ?? ($row['notes'] ?? ''),
        ];
    }

    $primary = $groupMovements[0];

    $sourceSig = $signatureMap['source'];
    $targetSig = $signatureMap['target'];
    $sourceMeta = $sourceSig['meta'] ?? inventory_decode_signature_label($sourceSig['label'] ?? null);
    $targetMeta = $targetSig['meta'] ?? inventory_decode_signature_label($targetSig['label'] ?? null);

    $sourceImage = inventory_s3_fetch_object($sourceSig['file_key']);
    $targetImage = inventory_s3_fetch_object($targetSig['file_key']);

    $sourceImgData = 'data:' . (($sourceSig['mime'] ?? 'image/png') ?: 'image/png') . ';base64,' . base64_encode($sourceImage);
    $targetImgData = 'data:' . (($targetSig['mime'] ?? 'image/png') ?: 'image/png') . ';base64,' . base64_encode($targetImage);

    $sectorsAssoc = [];
    foreach ($sectors as $sectorRow) {
        $sectorsAssoc[(int)$sectorRow['id']] = (string)$sectorRow['name'];
    }

    $sourceSectorName = '';
    $targetSectorName = '';
    if (!empty($primary['source_sector_id']) && isset($sectorsAssoc[(int)$primary['source_sector_id']])) {
        $sourceSectorName = $sectorsAssoc[(int)$primary['source_sector_id']];
    }
    if (!empty($primary['target_sector_id']) && isset($sectorsAssoc[(int)$primary['target_sector_id']])) {
        $targetSectorName = $sectorsAssoc[(int)$primary['target_sector_id']];
    }

    if ($sourceMeta && !empty($sourceMeta['sector_name'])) {
        $sourceSectorName = (string)$sourceMeta['sector_name'];
    }
    if ($targetMeta && !empty($targetMeta['sector_name'])) {
        $targetSectorName = (string)$targetMeta['sector_name'];
    }

    $sourceSigner = $sourceMeta['signer'] ?? '';
    $targetSigner = $targetMeta['signer'] ?? '';

    $html = '<html><head><meta charset="utf-8"><style>' .
        'body{font-family:"DejaVu Sans",sans-serif;color:#1f2937;margin:32px;font-size:12px;}' .
        '.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;}' .
        '.title{font-size:22px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#111827;}' .
        '.meta{font-size:12px;line-height:1.5;color:#374151;}' .
        'table{width:100%;border-collapse:collapse;margin-top:16px;}' .
        'th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;}' .
        'th{background:#111827;color:#f9fafb;font-size:12px;letter-spacing:.05em;text-transform:uppercase;}' .
        '.sig-row{display:flex;gap:32px;margin-top:36px;}' .
        '.sig-box{flex:1;border-top:2px solid #1f2937;padding-top:8px;min-height:120px;display:flex;flex-direction:column;gap:10px;}' .
        '.sig-label{font-weight:600;text-transform:uppercase;font-size:11px;color:#1f2937;letter-spacing:.08em;}' .
        '.sig-img{max-width:100%;height:80px;object-fit:contain;}' .
        '.sig-name{font-size:11px;color:#4b5563;}' .
        '.notes{margin-top:24px;font-size:12px;color:#4b5563;line-height:1.6;background:#f8fafc;padding:14px;border-radius:12px;border:1px solid #e2e8f0;}' .
        '</style></head><body>';

    $html .= '<div class="header">'
        . '<div>'
        . '<div class="title">Inventory Transfer Form</div>'
        . '<div class="meta">Transfer ID <strong>#' . (int)$primary['id'] . '</strong><br>'
        . 'Signed on ' . htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div>'
        . '</div>';

    if ($sourceSectorName !== '' || $targetSectorName !== '') {
        $html .= '<div class="meta">';
        if ($sourceSectorName !== '') {
            $html .= '<div>From <strong>' . htmlspecialchars($sourceSectorName, ENT_QUOTES, 'UTF-8') . '</strong></div>';
        }
        if ($targetSectorName !== '') {
            $html .= '<div>To <strong>' . htmlspecialchars($targetSectorName, ENT_QUOTES, 'UTF-8') . '</strong></div>';
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
            . '<td>' . htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars((string)$item['sku'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . (int)$item['amount'] . '</td>'
            . '<td>' . htmlspecialchars(strtoupper((string)$item['direction']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars((string)$item['reason'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<div class="sig-row">';
    $html .= '<div class="sig-box">'
        . '<div class="sig-label">Source Signature' . ($sourceSectorName !== '' ? ' — ' . htmlspecialchars($sourceSectorName, ENT_QUOTES, 'UTF-8') : '') . '</div>'
        . '<img class="sig-img" src="' . $sourceImgData . '" alt="Source signature">';
    if ($sourceSigner !== '') {
        $html .= '<div class="sig-name">Signed by ' . htmlspecialchars($sourceSigner, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $html .= '</div>';

    $html .= '<div class="sig-box">'
        . '<div class="sig-label">Receiving Signature' . ($targetSectorName !== '' ? ' — ' . htmlspecialchars($targetSectorName, ENT_QUOTES, 'UTF-8') : '') . '</div>'
        . '<img class="sig-img" src="' . $targetImgData . '" alt="Receiving signature">';
    if ($targetSigner !== '') {
        $html .= '<div class="sig-name">Signed by ' . htmlspecialchars($targetSigner, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="notes">Digitally signed via Punchlist inventory workflow. Both parties acknowledge the transfer of the listed items.</div>';

    $html .= '</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdf = $dompdf->output();

    $upload = inventory_s3_upload($pdf, 'application/pdf', 'transfer-signed-' . (int)$primary['id'] . '.pdf', 'inventory/signed');

    $ids = array_map(static fn($row) => (int)$row['id'], $groupMovements);
    if ($ids) {
        $idPlaceholders = [];
        $params = [':key' => $upload['key'], ':url' => $upload['url']];
        foreach ($ids as $index => $id) {
            $param = ':id' . $index;
            $idPlaceholders[] = $param;
            $params[$param] = $id;
        }
        $sql = 'UPDATE inventory_movements SET transfer_form_key = :key, transfer_form_url = :url, transfer_status = \'signed\' WHERE id IN (' . implode(',', $idPlaceholders) . ')';
        $stmtUpdate = $pdo->prepare($sql);
        $stmtUpdate->execute($params);
    }

    return $upload;
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
