<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_helpers.php';

$appsPdo = get_pdo();

$tokenValue = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$errors = [];
$success = null;
$movement = null;
$tokenRow = null;

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
    } else {
        $errors[] = 'Invalid or unknown signing token.';
    }
} else {
    $errors[] = 'Missing signing token.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors && $movement) {
    $signerName = trim((string)($_POST['signer_name'] ?? ''));
    $signatureData = $_POST['signature_data'] ?? '';
    $uploadFile = $_FILES['signed_file'] ?? null;

    if ($signatureData === '' && ($uploadFile === null || ($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        $errors[] = 'Provide a drawn signature or upload a signed copy.';
    }

$currentUser = current_user();
$userId = is_array($currentUser) ? ($currentUser['id'] ?? null) : null;

    if (!$errors) {
        try {
            if ($signatureData !== '' && str_starts_with($signatureData, 'data:image')) {
                $parts = explode(',', $signatureData, 2);
                if (count($parts) === 2) {
                    $mime = 'image/png';
                    if (preg_match('#data:(.*?);base64#', $parts[0], $m)) {
                        $mime = $m[1];
                    }
                    $binary = base64_decode($parts[1], true);
                    if ($binary === false) {
                        throw new RuntimeException('Could not decode signature.');
                    }
                    $upload = inventory_s3_upload($binary, $mime, 'signature-' . $tokenRow['movement_id'] . '.png', 'inventory/signatures');
                    inventory_store_movement_file($appsPdo, (int)$tokenRow['movement_id'], $upload + ['mime' => $mime], $signerName !== '' ? 'Digital signature - ' . $signerName : 'Digital signature', 'signature', $userId);
                }
            }
            if ($uploadFile !== null && ($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = inventory_s3_upload_file($uploadFile, 'inventory/signatures/');
                inventory_store_movement_file($appsPdo, (int)$tokenRow['movement_id'], $upload, $signerName !== '' ? 'Uploaded copy - ' . $signerName : 'Uploaded copy', 'signature', $userId);
            }
            $appsPdo->prepare('UPDATE inventory_movements SET transfer_status = :status WHERE id = :id')
                ->execute([':status' => 'signed', ':id' => (int)$tokenRow['movement_id']]);
            $success = 'Thank you! Your signature has been recorded.';
        } catch (Throwable $e) {
            $errors[] = 'Unable to save your signature: ' . $e->getMessage();
        }
    }
}

$itemInfo = null;
if ($movement) {
    $itemStmt = $appsPdo->prepare('SELECT name, sku FROM inventory_items WHERE id = :id');
    $itemStmt->execute([':id' => (int)$movement['item_id']]);
    $itemInfo = $itemStmt->fetch();
}

$sectorMap = [];
try {
    $sectorStmt = get_pdo('core')->query('SELECT id,name FROM sectors');
    foreach ($sectorStmt->fetchAll() as $s) {
        $sectorMap[(int)$s['id']] = $s['name'];
    }
} catch (Throwable $e) {
}

function sector_label(array $map, $id): string {
    return $id !== null && isset($map[(int)$id]) ? (string)$map[(int)$id] : '—';
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Inventory Transfer Signature</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; margin:0; background:#0f172a; color:#0f172a; }
    .wrap { max-width:720px; margin:0 auto; padding:2.5rem 1.5rem 3rem; }
    .card { background:#fff; border-radius:24px; padding:2rem; box-shadow:0 25px 60px -35px rgba(15,23,42,0.55); }
    h1 { margin:0 0 .75rem; font-size:1.6rem; letter-spacing:.05em; text-transform:uppercase; color:#0f172a; }
    .muted { color:#64748b; font-size:.95rem; }
    .flash { padding:.85rem 1rem; border-radius:12px; margin-bottom:1rem; font-weight:600; }
    .flash-error { background:#fee2e2; color:#991b1b; }
    .flash-success { background:#dcfce7; color:#166534; }
    .transfer-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; margin:1.5rem 0; }
    .transfer-meta div { background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:.85rem 1rem; }
    .transfer-meta dt { margin:0 0 .25rem; font-size:.75rem; text-transform:uppercase; letter-spacing:.1em; color:#94a3b8; }
    .transfer-meta dd { margin:0; font-size:1rem; color:#0f172a; }
    form { display:grid; gap:1rem; }
    label { display:flex; flex-direction:column; gap:.4rem; font-size:.9rem; color:#0f172a; }
    input[type="text"], input[type="file"] { border:1px solid #cbd5f5; border-radius:12px; padding:.65rem .75rem; font-size:1rem; }
    canvas { border:2px dashed #94a3b8; border-radius:16px; background:#f8fafc; width:100%; height:220px; touch-action:none; }
    .actions { display:flex; gap:1rem; flex-wrap:wrap; }
    button { cursor:pointer; border:none; border-radius:12px; padding:.75rem 1.5rem; font-size:1rem; font-weight:600; }
    .btn-primary { background:#2563eb; color:#fff; }
    .btn-secondary { background:#e2e8f0; color:#1e293b; }
    footer { margin-top:2rem; text-align:center; color:#94a3b8; font-size:.8rem; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Inventory Transfer Signature</h1>
      <p class="muted">Sign to acknowledge receipt/hand-off of inventory items.</p>

      <?php foreach ($errors as $error): ?>
        <div class="flash flash-error"><?php echo sanitize((string)$error); ?></div>
      <?php endforeach; ?>
      <?php if ($success): ?>
        <div class="flash flash-success"><?php echo sanitize((string)$success); ?></div>
      <?php endif; ?>

      <?php if ($movement && !$errors): ?>
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
          <label>Your name
            <input type="text" name="signer_name" placeholder="Full name" value="<?php echo isset($_POST['signer_name']) ? sanitize((string)$_POST['signer_name']) : ''; ?>">
          </label>
          <label>Draw signature
            <canvas id="signature-pad"></canvas>
            <div class="actions">
              <button type="button" class="btn-secondary" id="clear-signature">Clear</button>
            </div>
          </label>
          <label>Upload signed copy (optional)
            <input type="file" name="signed_file" accept="image/*,application/pdf">
          </label>
          <input type="hidden" name="token" value="<?php echo sanitize($tokenValue); ?>">
          <input type="hidden" name="signature_data" id="signature-data">
          <div class="actions">
            <button type="submit" class="btn-primary">Submit Signature</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <footer>Powered by the Punchlist inventory module.</footer>
  </div>

  <script>
    (function(){
      const canvas = document.getElementById('signature-pad');
      if (!canvas) return;
      const ctx = canvas.getContext('2d');
      let drawing = false;
      let lastPos = null;

      const resize = () => {
        const data = canvas.toDataURL();
        canvas.width = canvas.clientWidth;
        canvas.height = canvas.clientHeight;
        if (data && data !== 'data:,') {
          const image = new Image();
          image.onload = () => ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
          image.src = data;
        } else {
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0,0,canvas.width,canvas.height);
        }
      };
      window.addEventListener('resize', resize);
      resize();

      const getPos = (evt) => {
        if (evt.touches && evt.touches.length) {
          const rect = canvas.getBoundingClientRect();
          return { x: evt.touches[0].clientX - rect.left, y: evt.touches[0].clientY - rect.top };
        }
        const rect = canvas.getBoundingClientRect();
        return { x: evt.clientX - rect.left, y: evt.clientY - rect.top };
      };

      const draw = (pos) => {
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#0f172a';
        ctx.lineWidth = 2.5;
        if (!lastPos) {
          lastPos = pos;
        }
        ctx.beginPath();
        ctx.moveTo(lastPos.x, lastPos.y);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        lastPos = pos;
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

      canvas.addEventListener('mousedown', start);
      canvas.addEventListener('mousemove', move);
      canvas.addEventListener('mouseup', stop);
      canvas.addEventListener('mouseout', stop);
      canvas.addEventListener('touchstart', start, { passive: false });
      canvas.addEventListener('touchmove', move, { passive: false });
      canvas.addEventListener('touchend', stop);

      document.getElementById('clear-signature').addEventListener('click', () => {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0,0,canvas.width,canvas.height);
      });

      const form = document.getElementById('sign-form');
      form.addEventListener('submit', () => {
        const dataInput = document.getElementById('signature-data');
        dataInput.value = canvas.toDataURL('image/png');
      });
    })();
  </script>
</body>
</html>
