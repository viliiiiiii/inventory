<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_helpers.php';

$appsPdo = get_pdo();

$tokenValue = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$errors = [];
$success = null;
$movement = null;
$tokenRow = null;
$signatureState = ['source' => null, 'target' => null, 'extras' => []];
$nonSignatureFiles = [];
$sectorMap = [];

if ($tokenValue !== '') {
    $stmt = $appsPdo->prepare('SELECT t.*, m.* FROM inventory_public_tokens t JOIN inventory_movements m ON m.id = t.movement_id WHERE t.token = :token LIMIT 1');
    $stmt->execute([':token' => $tokenValue]);
    $row = $stmt->fetch();
    if ($row) {
        $tokenRow = $row;
        $movement = $row;
        if (strtotime((string)$row['expires_at']) < time()) {
            $errors[] = 'This signing link has expired. Please request a new QR code.';
        }
        try {
            $signatureState = inventory_fetch_movement_signatures($appsPdo, (int)$row['movement_id']);
            $fileStmt = $appsPdo->prepare('SELECT * FROM inventory_movement_files WHERE movement_id = :id AND kind <> "signature" ORDER BY uploaded_at DESC');
            $fileStmt->execute([':id' => (int)$row['movement_id']]);
            $nonSignatureFiles = $fileStmt->fetchAll();
        } catch (Throwable $e) {
        }
    } else {
        $errors[] = 'Invalid or unknown signing token.';
    }
} else {
    $errors[] = 'Missing signing token.';
}

try {
    $sectorStmt = get_pdo('core')->query('SELECT id,name FROM sectors');
    foreach ($sectorStmt->fetchAll() as $s) {
        $sectorMap[(int)$s['id']] = $s['name'];
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors && $movement) {
    $uploadFile = $_FILES['signed_file'] ?? null;
    $currentUser = current_user();
    $userId = is_array($currentUser) ? ($currentUser['id'] ?? null) : null;

    $roles = [
        'source' => [
            'label'          => 'Source party',
            'sector_field'   => 'source_sector_id',
            'sector_custom'  => 'source_sector_custom',
            'signer_field'   => 'source_signer_name',
            'signature_field'=> 'source_signature_data',
        ],
        'target' => [
            'label'          => 'Receiving party',
            'sector_field'   => 'target_sector_id',
            'sector_custom'  => 'target_sector_custom',
            'signer_field'   => 'target_signer_name',
            'signature_field'=> 'target_signature_data',
        ],
    ];

    $savedSomething = false;

    foreach ($roles as $roleKey => $config) {
        $signatureData = $_POST[$config['signature_field']] ?? '';
        if (!is_string($signatureData) || trim($signatureData) === '') {
            continue;
        }
        if (!str_starts_with($signatureData, 'data:image')) {
            $errors[] = 'Invalid signature data for ' . $config['label'] . '.';
            continue;
        }

        $sectorChoice = trim((string)($_POST[$config['sector_field']] ?? ''));
        $sectorName = '';
        $sectorId = null;

        if ($sectorChoice === '') {
            $errors[] = 'Select the sector for ' . strtolower($config['label']) . '.';
            continue;
        }
        if ($sectorChoice === 'custom') {
            $sectorName = trim((string)($_POST[$config['sector_custom']] ?? ''));
            if ($sectorName === '') {
                $errors[] = 'Provide the sector name for ' . strtolower($config['label']) . '.';
                continue;
            }
            $sectorName = inventory_str_truncate($sectorName, 80);
        } elseif ($sectorChoice === 'null') {
            $sectorName = 'Unassigned';
        } else {
            $sectorId = (int)$sectorChoice;
            $sectorName = $sectorMap[$sectorId] ?? '';
            if ($sectorName === '') {
                $errors[] = 'Unknown sector selected for ' . strtolower($config['label']) . '.';
                continue;
            }
            $sectorName = inventory_str_truncate($sectorName, 80);
        }

        $parts = explode(',', $signatureData, 2);
        if (count($parts) !== 2) {
            $errors[] = 'Malformed signature payload for ' . strtolower($config['label']) . '.';
            continue;
        }
        $mime = 'image/png';
        if (preg_match('#data:(.*?);base64#', $parts[0], $m)) {
            $mime = $m[1];
        }
        $binary = base64_decode($parts[1], true);
        if ($binary === false) {
            $errors[] = 'Could not decode the signature for ' . strtolower($config['label']) . '.';
            continue;
        }

        try {
            $filename = $roleKey . '-signature-' . (int)$tokenRow['movement_id'] . '-' . bin2hex(random_bytes(4)) . '.png';
        } catch (Throwable $e) {
            $filename = $roleKey . '-signature-' . (int)$tokenRow['movement_id'] . '.png';
        }

        try {
            $upload = inventory_s3_upload($binary, $mime, $filename, 'inventory/signatures');
            $metadata = [
                'role'         => $roleKey,
                'sector_id'    => $sectorId,
                'sector_name'  => $sectorName,
                'sector_choice'=> $sectorChoice,
                'signer'       => inventory_str_truncate(trim((string)($_POST[$config['signer_field']] ?? '')), 80),
                'signed_at'    => date('c'),
            ];
            $label = inventory_format_signature_label($metadata);
            inventory_store_movement_file(
                $appsPdo,
                (int)$tokenRow['movement_id'],
                $upload + ['mime' => $mime],
                $label,
                'signature',
                $userId
            );
            $savedSomething = true;
        } catch (Throwable $e) {
            $errors[] = 'Failed to store ' . strtolower($config['label']) . ' signature: ' . $e->getMessage();
        }
    }

    if ($uploadFile !== null && ($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $upload = inventory_s3_upload_file($uploadFile, 'inventory/signatures/');
            $label = trim((string)($_POST['upload_label'] ?? 'Signed paperwork upload'));
            if ($label === '') {
                $label = 'Signed paperwork upload';
            }
            $label = inventory_str_truncate($label, 120);
            inventory_store_movement_file($appsPdo, (int)$tokenRow['movement_id'], $upload, $label, 'signature', $userId);
            $savedSomething = true;
        } catch (Throwable $e) {
            $errors[] = 'Unable to save the uploaded file: ' . $e->getMessage();
        }
    }

    if (!$savedSomething && !$errors) {
        $errors[] = 'No new signatures were submitted.';
    }

    if (!$errors) {
        $signatureState = inventory_fetch_movement_signatures($appsPdo, (int)$tokenRow['movement_id']);
        try {
            $fileStmt = $appsPdo->prepare('SELECT * FROM inventory_movement_files WHERE movement_id = :id AND kind <> "signature" ORDER BY uploaded_at DESC');
            $fileStmt->execute([':id' => (int)$tokenRow['movement_id']]);
            $nonSignatureFiles = $fileStmt->fetchAll();
        } catch (Throwable $e) {
        }

        if ($signatureState['source'] && $signatureState['target']) {
            try {
                inventory_generate_signed_transfer_pdf($appsPdo, (int)$tokenRow['movement_id'], $signatureState);
                $stmt = $appsPdo->prepare('SELECT * FROM inventory_movements WHERE id = :id');
                $stmt->execute([':id' => (int)$tokenRow['movement_id']]);
                $movement = $stmt->fetch();
                $success = 'Transfer signed and archived successfully.';
            } catch (Throwable $e) {
                $errors[] = 'Signatures saved but the signed PDF could not be generated: ' . $e->getMessage();
            }
        } elseif ($savedSomething) {
            $success = 'Signature saved. Awaiting the other party.';
        }
    }
}

$itemInfo = null;
if ($movement) {
    $itemStmt = $appsPdo->prepare('SELECT name, sku FROM inventory_items WHERE id = :id');
    $itemStmt->execute([':id' => (int)$movement['item_id']]);
    $itemInfo = $itemStmt->fetch();
}

function sector_label(array $map, $id): string {
    return $id !== null && isset($map[(int)$id]) ? (string)$map[(int)$id] : '—';
}

$sourceSignature = $signatureState['source'];
$targetSignature = $signatureState['target'];
$sourceMeta = $sourceSignature['meta'] ?? inventory_decode_signature_label($sourceSignature['label'] ?? null) ?? [];
$targetMeta = $targetSignature['meta'] ?? inventory_decode_signature_label($targetSignature['label'] ?? null) ?? [];

$sourceSectorValue = $_POST['source_sector_id'] ?? '';
if ($sourceSectorValue === '' && isset($sourceMeta['sector_choice'])) {
    $sourceSectorValue = (string)$sourceMeta['sector_choice'];
}
if ($sourceSectorValue === '' && isset($sourceMeta['sector_id']) && $sourceMeta['sector_id'] !== null) {
    $sourceSectorValue = (string)$sourceMeta['sector_id'];
}
if ($sourceSectorValue === '' && $movement && !empty($movement['source_sector_id'])) {
    $sourceSectorValue = (string)$movement['source_sector_id'];
}
$sourceCustomValue = $_POST['source_sector_custom'] ?? ($sourceSectorValue === 'custom' ? ($sourceMeta['sector_name'] ?? '') : ($sourceMeta && empty($sourceMeta['sector_id']) ? ($sourceMeta['sector_name'] ?? '') : ''));
$sourceSignerValue = $_POST['source_signer_name'] ?? ($sourceMeta['signer'] ?? '');

$targetSectorValue = $_POST['target_sector_id'] ?? '';
if ($targetSectorValue === '' && isset($targetMeta['sector_choice'])) {
    $targetSectorValue = (string)$targetMeta['sector_choice'];
}
if ($targetSectorValue === '' && isset($targetMeta['sector_id']) && $targetMeta['sector_id'] !== null) {
    $targetSectorValue = (string)$targetMeta['sector_id'];
}
if ($targetSectorValue === '' && $movement && !empty($movement['target_sector_id'])) {
    $targetSectorValue = (string)$movement['target_sector_id'];
}
$targetCustomValue = $_POST['target_sector_custom'] ?? ($targetSectorValue === 'custom' ? ($targetMeta['sector_name'] ?? '') : ($targetMeta && empty($targetMeta['sector_id']) ? ($targetMeta['sector_name'] ?? '') : ''));
$targetSignerValue = $_POST['target_signer_name'] ?? ($targetMeta['signer'] ?? '');

$uploadLabelValue = $_POST['upload_label'] ?? '';

$sourceSignedAt = $sourceSignature['uploaded_at'] ?? null;
$targetSignedAt = $targetSignature['uploaded_at'] ?? null;

$sourceSignedDisplay = null;
if ($sourceSignedAt) {
    $ts = strtotime((string)$sourceSignedAt);
    if ($ts) {
        $sourceSignedDisplay = date('Y-m-d H:i', $ts);
    }
}
$targetSignedDisplay = null;
if ($targetSignedAt) {
    $ts = strtotime((string)$targetSignedAt);
    if ($ts) {
        $targetSignedDisplay = date('Y-m-d H:i', $ts);
    }
}

$transferStatus = $movement['transfer_status'] ?? null;
$transferPdfUrl = $movement['transfer_form_url'] ?? null;

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Inventory Transfer Signature</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      color-scheme: light;
      --bg: #0f172a;
      --card-bg: #ffffff;
      --muted: #64748b;
      --line: #e2e8f0;
      --accent: #2563eb;
    }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; margin:0; background:var(--bg); color:#0f172a; }
    .page { max-width:960px; margin:0 auto; padding:clamp(1.5rem, 3vw, 3rem) clamp(1rem, 3vw, 2.5rem); }
    .card { background:var(--card-bg); border-radius:28px; padding:clamp(1.5rem, 3vw, 2.75rem); box-shadow:0 25px 80px -35px rgba(15,23,42,0.55); display:flex; flex-direction:column; gap:1.5rem; }
    h1 { margin:0; font-size:clamp(1.6rem, 3vw, 2.1rem); letter-spacing:.04em; text-transform:uppercase; color:#0f172a; }
    .muted { color:var(--muted); font-size:.95rem; }
    .flash { padding:.85rem 1rem; border-radius:14px; font-weight:600; }
    .flash-error { background:#fee2e2; color:#991b1b; }
    .flash-success { background:#dcfce7; color:#166534; }
    .transfer-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; }
    .transfer-meta div { background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px; padding:1rem; display:flex; flex-direction:column; gap:.35rem; }
    .transfer-meta dt { margin:0; font-size:.75rem; text-transform:uppercase; letter-spacing:.12em; color:#94a3b8; }
    .transfer-meta dd { margin:0; font-size:1rem; color:#0f172a; font-weight:600; }
    form { display:flex; flex-direction:column; gap:1.5rem; }
    .section-title { font-size:1.1rem; margin:0; font-weight:700; color:#0f172a; }
    .field-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; }
    label.field { display:flex; flex-direction:column; gap:.35rem; font-size:.9rem; color:#0f172a; }
    select, input[type="text"], input[type="file"] { border:1px solid var(--line); border-radius:12px; padding:.65rem .75rem; font-size:.95rem; background:#fff; }
    input[type="file"] { padding:.5rem .75rem; }
    .sector-custom { display:none; }
    .sector-custom.is-visible { display:flex; }
    .signature-block { border:1px solid var(--line); border-radius:18px; padding:1.25rem; display:flex; flex-direction:column; gap:1rem; background:#f8fafc; }
    .signature-heading { display:flex; flex-direction:column; gap:.35rem; }
    .signature-heading strong { font-size:1rem; letter-spacing:.08em; text-transform:uppercase; }
    .signature-status { display:flex; flex-direction:column; gap:.6rem; background:#fff; border-radius:14px; padding:1rem; border:1px solid #dce4f4; }
    .signature-status .status-line { font-size:.9rem; color:#1f2937; display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; }
    .status-pill { display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .75rem; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:.8rem; font-weight:600; text-transform:uppercase; letter-spacing:.08em; }
    .signature-preview { max-width:320px; background:#f8fafc; border:1px dashed #cbd5f5; border-radius:12px; padding:.75rem; display:flex; justify-content:center; }
    .signature-preview img { max-width:100%; height:auto; }
    .signature-pad { border:2px dashed #94a3b8; border-radius:16px; background:#fff; position:relative; }
    .signature-pad.is-collapsed { display:none; }
    .signature-pad canvas { width:100%; height:220px; display:block; border-radius:inherit; touch-action:none; }
    .pad-actions { margin-top:.65rem; display:flex; gap:.75rem; flex-wrap:wrap; }
    button { cursor:pointer; border:none; border-radius:12px; padding:.7rem 1.4rem; font-size:.95rem; font-weight:600; display:inline-flex; align-items:center; gap:.5rem; }
    .btn-primary { background:var(--accent); color:#fff; }
    .btn-secondary { background:#e2e8f0; color:#1e293b; }
    .btn-link { background:none; color:var(--accent); padding:0; }
    .form-actions { display:flex; justify-content:flex-end; }
    .status-bar { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; }
    .pdf-link { display:inline-flex; align-items:center; gap:.4rem; padding:.4rem .8rem; border-radius:999px; background:#f1f5f9; text-decoration:none; color:#1d4ed8; font-weight:600; font-size:.85rem; }
    .attachment-list { display:grid; gap:.6rem; }
    .attachment-card { background:#f8fafc; border:1px solid #dce4f4; border-radius:12px; padding:.75rem 1rem; display:flex; justify-content:space-between; align-items:center; }
    .attachment-card a { text-decoration:none; color:#1d4ed8; font-weight:600; }
    footer { margin-top:2rem; text-align:center; color:#94a3b8; font-size:.8rem; }
    @media (max-width: 640px) {
      .card { border-radius:0; padding:1.5rem; box-shadow:none; }
      .page { padding:1rem 0; }
      .signature-pad canvas { height:180px; }
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="card">
      <div>
        <h1>Inventory Transfer Signature</h1>
        <p class="muted">Sign to acknowledge receipt or hand-off of the listed inventory items. Works great on phones and tablets.</p>
      </div>

      <?php foreach ($errors as $error): ?>
        <div class="flash flash-error"><?php echo sanitize((string)$error); ?></div>
      <?php endforeach; ?>
      <?php if ($success): ?>
        <div class="flash flash-success"><?php echo sanitize((string)$success); ?></div>
      <?php endif; ?>

      <?php if ($movement && !$errors): ?>
        <div class="status-bar">
          <span class="status-pill">Status: <?php echo sanitize((string)ucfirst((string)$transferStatus)); ?></span>
          <?php if (!empty($movement['transfer_form_url'])): ?>
            <a class="pdf-link" href="<?php echo sanitize((string)$movement['transfer_form_url']); ?>" target="_blank" rel="noopener">
              <span aria-hidden="true">📄</span> Transfer PDF
            </a>
          <?php endif; ?>
        </div>

        <dl class="transfer-meta">
          <div>
            <dt>Item</dt>
            <dd><?php echo sanitize((string)($itemInfo['name'] ?? 'Item #' . $movement['item_id'])); ?></dd>
          </div>
          <div>
            <dt>Quantity</dt>
            <dd><?php echo (int)$movement['amount']; ?> (<?php echo strtoupper((string)$movement['direction']); ?>)</dd>
          </div>
          <div>
            <dt>From</dt>
            <dd><?php echo sanitize(sector_label($sectorMap, $movement['source_sector_id'])); ?></dd>
          </div>
          <div>
            <dt>To</dt>
            <dd><?php echo sanitize(sector_label($sectorMap, $movement['target_sector_id'])); ?></dd>
          </div>
        </dl>

        <form method="post" enctype="multipart/form-data" autocomplete="off" id="sign-form">
          <section class="signature-block" data-party="source">
            <div class="signature-heading">
              <strong>Source party signature</strong>
              <span class="muted">Team handing over the items.</span>
            </div>
            <div class="field-grid">
              <label class="field">Signing sector
                <select name="source_sector_id" data-sector-select data-custom-input="source-custom">
                  <option value="">Select sector…</option>
                  <?php foreach ($sectorMap as $id => $name): ?>
                    <option value="<?php echo (int)$id; ?>" <?php echo ((string)$id === (string)$sourceSectorValue) ? 'selected' : ''; ?>><?php echo sanitize((string)$name); ?></option>
                  <?php endforeach; ?>
                  <option value="null" <?php echo $sourceSectorValue === 'null' ? 'selected' : ''; ?>>Unassigned</option>
                  <option value="custom" <?php echo $sourceSectorValue === 'custom' ? 'selected' : ''; ?>>Other / External</option>
                </select>
              </label>
              <label class="field sector-custom <?php echo $sourceSectorValue === 'custom' ? 'is-visible' : ''; ?>" id="source-custom">Custom sector name
                <input type="text" name="source_sector_custom" value="<?php echo sanitize((string)$sourceCustomValue); ?>">
              </label>
              <label class="field">Signer name
                <input type="text" name="source_signer_name" value="<?php echo sanitize((string)$sourceSignerValue); ?>" placeholder="Full name">
              </label>
            </div>
            <?php if ($sourceSignature): ?>
              <div class="signature-status">
                <div class="status-line">
                  <span aria-hidden="true">✔️</span>
                  Signed by <?php echo sanitize((string)($sourceMeta['signer'] ?? '')); ?>
                  <?php if ($sourceSignedDisplay): ?>
                    <span class="muted">on <?php echo sanitize((string)$sourceSignedDisplay); ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($sourceSignature['file_url']) && str_starts_with((string)($sourceSignature['mime'] ?? ''), 'image/')): ?>
                  <div class="signature-preview">
                    <img src="<?php echo sanitize((string)$sourceSignature['file_url']); ?>" alt="Source signature preview">
                  </div>
                <?php endif; ?>
                <button type="button" class="btn-link" data-toggle-pad="source">Capture a new signature</button>
              </div>
            <?php endif; ?>
            <div class="signature-pad<?php echo $sourceSignature ? ' is-collapsed' : ''; ?>" data-signature-pad data-role="source">
              <canvas></canvas>
              <div class="pad-actions">
                <button type="button" class="btn-secondary" data-clear>Clear</button>
              </div>
              <input type="hidden" name="source_signature_data">
            </div>
          </section>

          <section class="signature-block" data-party="target">
            <div class="signature-heading">
              <strong>Receiving party signature</strong>
              <span class="muted">Team accepting the items.</span>
            </div>
            <div class="field-grid">
              <label class="field">Signing sector
                <select name="target_sector_id" data-sector-select data-custom-input="target-custom">
                  <option value="">Select sector…</option>
                  <?php foreach ($sectorMap as $id => $name): ?>
                    <option value="<?php echo (int)$id; ?>" <?php echo ((string)$id === (string)$targetSectorValue) ? 'selected' : ''; ?>><?php echo sanitize((string)$name); ?></option>
                  <?php endforeach; ?>
                  <option value="null" <?php echo $targetSectorValue === 'null' ? 'selected' : ''; ?>>Unassigned</option>
                  <option value="custom" <?php echo $targetSectorValue === 'custom' ? 'selected' : ''; ?>>Other / External</option>
                </select>
              </label>
              <label class="field sector-custom <?php echo $targetSectorValue === 'custom' ? 'is-visible' : ''; ?>" id="target-custom">Custom sector name
                <input type="text" name="target_sector_custom" value="<?php echo sanitize((string)$targetCustomValue); ?>">
              </label>
              <label class="field">Signer name
                <input type="text" name="target_signer_name" value="<?php echo sanitize((string)$targetSignerValue); ?>" placeholder="Full name">
              </label>
            </div>
            <?php if ($targetSignature): ?>
              <div class="signature-status">
                <div class="status-line">
                  <span aria-hidden="true">✔️</span>
                  Signed by <?php echo sanitize((string)($targetMeta['signer'] ?? '')); ?>
                  <?php if ($targetSignedDisplay): ?>
                    <span class="muted">on <?php echo sanitize((string)$targetSignedDisplay); ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($targetSignature['file_url']) && str_starts_with((string)($targetSignature['mime'] ?? ''), 'image/')): ?>
                  <div class="signature-preview">
                    <img src="<?php echo sanitize((string)$targetSignature['file_url']); ?>" alt="Receiving signature preview">
                  </div>
                <?php endif; ?>
                <button type="button" class="btn-link" data-toggle-pad="target">Capture a new signature</button>
              </div>
            <?php endif; ?>
            <div class="signature-pad<?php echo $targetSignature ? ' is-collapsed' : ''; ?>" data-signature-pad data-role="target">
              <canvas></canvas>
              <div class="pad-actions">
                <button type="button" class="btn-secondary" data-clear>Clear</button>
              </div>
              <input type="hidden" name="target_signature_data">
            </div>
          </section>

          <section class="signature-block">
            <div class="signature-heading">
              <strong>Upload paperwork (optional)</strong>
              <span class="muted">Scan or photograph the signed document for the archive.</span>
            </div>
            <div class="field-grid">
              <label class="field">File
                <input type="file" name="signed_file" accept="image/*,application/pdf">
              </label>
              <label class="field">Label
                <input type="text" name="upload_label" value="<?php echo sanitize((string)$uploadLabelValue); ?>" placeholder="e.g. Signed page 1">
              </label>
            </div>
          </section>

          <?php if ($signatureState['extras'] || $nonSignatureFiles): ?>
            <section class="signature-block">
              <div class="signature-heading">
                <strong>Existing paper trail</strong>
                <span class="muted">Previously uploaded documents for this transfer.</span>
              </div>
              <div class="attachment-list">
                <?php foreach ($signatureState['extras'] as $extra): ?>
                  <div class="attachment-card">
                    <div>
                      <strong><?php echo sanitize((string)($extra['label'] ?? 'Attachment')); ?></strong>
                      <?php if (!empty($extra['uploaded_at'])): ?>
                        <div class="muted">Added <?php echo sanitize(date('Y-m-d H:i', strtotime((string)$extra['uploaded_at']))); ?></div>
                      <?php endif; ?>
                    </div>
                    <a href="<?php echo sanitize((string)$extra['file_url']); ?>" target="_blank" rel="noopener">Open</a>
                  </div>
                <?php endforeach; ?>
                <?php foreach ($nonSignatureFiles as $extra): ?>
                  <div class="attachment-card">
                    <div>
                      <strong><?php echo sanitize((string)($extra['label'] ?? 'Attachment')); ?></strong>
                      <?php if (!empty($extra['uploaded_at'])): ?>
                        <div class="muted">Added <?php echo sanitize(date('Y-m-d H:i', strtotime((string)$extra['uploaded_at']))); ?></div>
                      <?php endif; ?>
                    </div>
                    <a href="<?php echo sanitize((string)$extra['file_url']); ?>" target="_blank" rel="noopener">Open</a>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>

          <input type="hidden" name="token" value="<?php echo sanitize($tokenValue); ?>">
          <div class="form-actions">
            <button type="submit" class="btn-primary">Submit updates</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <footer>Powered by the Punchlist inventory module.</footer>
  </div>

  <script>
    (function(){
      const pads = document.querySelectorAll('[data-signature-pad]');
      const setupPad = (container) => {
        const canvas = container.querySelector('canvas');
        const hidden = container.querySelector('input[type="hidden"]');
        const clearBtn = container.querySelector('[data-clear]');
        if (!canvas || !hidden) return;
        const ctx = canvas.getContext('2d');
        let drawing = false;
        let lastPos = null;
        let isDirty = false;

        const resize = () => {
          const ratio = window.devicePixelRatio || 1;
          const displayWidth = canvas.clientWidth;
          const displayHeight = canvas.clientHeight;
          const cache = isDirty ? canvas.toDataURL() : null;
          canvas.width = displayWidth * ratio;
          canvas.height = displayHeight * ratio;
          ctx.setTransform(1, 0, 0, 1, 0, 0);
          ctx.scale(ratio, ratio);
          ctx.clearRect(0, 0, displayWidth, displayHeight);
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, displayWidth, displayHeight);
          if (cache) {
            const img = new Image();
            img.onload = () => ctx.drawImage(img, 0, 0, displayWidth, displayHeight);
            img.src = cache;
          }
        };

        const getPos = (evt) => {
          const rect = canvas.getBoundingClientRect();
          if (evt.touches && evt.touches.length) {
            return { x: evt.touches[0].clientX - rect.left, y: evt.touches[0].clientY - rect.top };
          }
          return { x: evt.clientX - rect.left, y: evt.clientY - rect.top };
        };

        const draw = (pos) => {
          ctx.lineJoin = 'round';
          ctx.lineCap = 'round';
          ctx.strokeStyle = '#0f172a';
          ctx.lineWidth = 2.4;
          if (!lastPos) {
            lastPos = pos;
          }
          ctx.beginPath();
          ctx.moveTo(lastPos.x, lastPos.y);
          ctx.lineTo(pos.x, pos.y);
          ctx.stroke();
          lastPos = pos;
          isDirty = true;
        };

        const start = (evt) => {
          drawing = true;
          lastPos = getPos(evt);
        };
        const move = (evt) => {
          if (!drawing) return;
          evt.preventDefault();
          draw(getPos(evt));
        };
        const stop = () => {
          drawing = false;
          lastPos = null;
        };

        container.addEventListener('mousedown', (evt) => { if (evt.target === canvas) start(evt); });
        container.addEventListener('mousemove', move);
        container.addEventListener('mouseup', stop);
        container.addEventListener('mouseleave', stop);
        container.addEventListener('touchstart', (evt) => { if (evt.target === canvas) start(evt); }, { passive: false });
        container.addEventListener('touchmove', move, { passive: false });
        container.addEventListener('touchend', stop);

        if (clearBtn) {
          clearBtn.addEventListener('click', () => {
            const ratio = window.devicePixelRatio || 1;
            const displayWidth = canvas.clientWidth;
            const displayHeight = canvas.clientHeight;
            ctx.setTransform(1,0,0,1,0,0);
            ctx.scale(ratio, ratio);
            ctx.clearRect(0,0,displayWidth,displayHeight);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0,0,displayWidth,displayHeight);
            isDirty = false;
            hidden.value = '';
          });
        }

        resize();
        window.addEventListener('resize', resize);

        container.addEventListener('toggle-pad', () => {
          container.classList.remove('is-collapsed');
          resize();
        });

        container.dataset.serialize = 'true';
        container.__serialize = () => {
          if (!isDirty) {
            hidden.value = '';
            return;
          }
          hidden.value = canvas.toDataURL('image/png');
        };
      };

      pads.forEach(setupPad);

      const form = document.getElementById('sign-form');
      if (form) {
        form.addEventListener('submit', () => {
          pads.forEach(container => {
            if (typeof container.__serialize === 'function') {
              container.__serialize();
            }
          });
        });
      }

      document.querySelectorAll('[data-toggle-pad]').forEach(btn => {
        btn.addEventListener('click', () => {
          const role = btn.getAttribute('data-toggle-pad');
          const pad = document.querySelector('[data-signature-pad][data-role="' + role + '"]');
          if (pad) {
            pad.classList.remove('is-collapsed');
            pad.dispatchEvent(new Event('toggle-pad'));
            btn.remove();
          }
        });
      });

      document.querySelectorAll('[data-sector-select]').forEach(select => {
        const targetId = select.getAttribute('data-custom-input');
        const target = targetId ? document.getElementById(targetId) : null;
        const toggleCustom = () => {
          if (!target) return;
          if (select.value === 'custom') {
            target.classList.add('is-visible');
          } else {
            target.classList.remove('is-visible');
          }
        };
        select.addEventListener('change', toggleCustom);
        toggleCustom();
      });
    })();
  </script>
</body>
</html>
