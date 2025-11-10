<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_helpers.php';
require_login();

$appsPdo = get_pdo();        // APPS (punchlist) DB
$corePdo = get_pdo('core');  // CORE (users/roles/sectors/activity) DB — may be same as APPS if not split

$canManage    = can('inventory_manage');
$isRoot       = current_user_role_key() === 'root';
$userSectorId = current_user_sector_id();

$errors = [];

function inventory_fetch_item(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    return $item ?: null;
}

if (is_post()) {
    try {
        if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
            $errors[] = 'Invalid CSRF token.';
        } elseif (!$canManage) {
            $errors[] = 'Insufficient permissions.';
        } else {
            $action = $_POST['action'] ?? '';
            $currentUser = current_user() ?? [];
            $currentUserId = (int)($currentUser['id'] ?? 0) ?: null;

            if ($action === 'create_item') {
                $name       = trim((string)($_POST['name'] ?? ''));
                $sku        = trim((string)($_POST['sku'] ?? ''));
                $quantity   = max(0, (int)($_POST['quantity'] ?? 0));
                $location   = trim((string)($_POST['location'] ?? ''));
                $sectorIn   = $_POST['sector_id'] ?? '';
                $sectorId   = $isRoot ? (($sectorIn === '' || $sectorIn === 'null') ? null : (int)$sectorIn) : $userSectorId;

                if ($name === '') {
                    $errors[] = 'Name is required.';
                }
                if (!$isRoot && $sectorId === null) {
                    $errors[] = 'Your sector must be assigned before creating items.';
                }

                if (!$errors) {
                    $appsPdo->beginTransaction();
                    try {
                        $stmt = $appsPdo->prepare('INSERT INTO inventory_items (sku, name, sector_id, quantity, location) VALUES (:sku,:name,:sector,:quantity,:location)');
                        $stmt->execute([
                            ':sku'      => $sku !== '' ? $sku : null,
                            ':name'     => $name,
                            ':sector'   => $sectorId,
                            ':quantity' => $quantity,
                            ':location' => $location !== '' ? $location : null,
                        ]);
                        $itemId = (int)$appsPdo->lastInsertId();
                        if ($sectorId !== null) {
                            inventory_adjust_stock($appsPdo, $itemId, $sectorId, $quantity);
                        }
                        if ($quantity > 0) {
                            $appsPdo->prepare('INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id, source_sector_id, target_sector_id, source_location, target_location, requires_signature, transfer_status) VALUES (:item,:dir,:amount,:reason,:user,:src,:tgt,:src_loc,:tgt_loc,:req,:status)')
                                ->execute([
                                    ':item'    => $itemId,
                                    ':dir'     => 'in',
                                    ':amount'  => $quantity,
                                    ':reason'  => 'Initial quantity',
                                    ':user'    => $currentUserId,
                                    ':src'     => null,
                                    ':tgt'     => $sectorId,
                                    ':src_loc' => null,
                                    ':tgt_loc' => $location !== '' ? $location : null,
                                    ':req'     => 0,
                                    ':status'  => 'signed',
                                ]);
                        }
                        $appsPdo->commit();
                        log_event('inventory.add', 'inventory_item', $itemId, ['quantity' => $quantity, 'sector_id' => $sectorId]);
                        redirect_with_message('inventory.php', 'Item added.');
                    } catch (Throwable $e) {
                        $appsPdo->rollBack();
                        throw $e;
                    }
                }
            } elseif ($action === 'bulk_create_items') {
                $bulk = $_POST['bulk'] ?? [];
                $names     = $bulk['name'] ?? [];
                $skus      = $bulk['sku'] ?? [];
                $quantities= $bulk['quantity'] ?? [];
                $locations = $bulk['location'] ?? [];
                $sectors   = $bulk['sector_id'] ?? [];

                $rows = [];
                foreach ((array)$names as $idx => $nameVal) {
                    $nameVal = trim((string)$nameVal);
                    if ($nameVal === '') {
                        continue;
                    }
                    $rows[] = [
                        'name'     => $nameVal,
                        'sku'      => trim((string)($skus[$idx] ?? '')),
                        'quantity' => max(0, (int)($quantities[$idx] ?? 0)),
                        'location' => trim((string)($locations[$idx] ?? '')),
                        'sector'   => $isRoot ? (($sectors[$idx] ?? '') === 'null' || ($sectors[$idx] ?? '') === '' ? null : (int)$sectors[$idx]) : $userSectorId,
                    ];
                }

                if (!$rows) {
                    $errors[] = 'Provide at least one valid item row.';
                } elseif (!$isRoot && $userSectorId === null) {
                    $errors[] = 'Your sector must be assigned before creating items.';
                }

                if (!$errors) {
                    $appsPdo->beginTransaction();
                    try {
                        foreach ($rows as $row) {
                            $stmt = $appsPdo->prepare('INSERT INTO inventory_items (sku, name, sector_id, quantity, location) VALUES (:sku,:name,:sector,:qty,:location)');
                            $stmt->execute([
                                ':sku'      => $row['sku'] !== '' ? $row['sku'] : null,
                                ':name'     => $row['name'],
                                ':sector'   => $row['sector'],
                                ':qty'      => $row['quantity'],
                                ':location' => $row['location'] !== '' ? $row['location'] : null,
                            ]);
                            $itemId = (int)$appsPdo->lastInsertId();
                            if ($row['sector'] !== null) {
                                inventory_adjust_stock($appsPdo, $itemId, $row['sector'], $row['quantity']);
                            }
                            if ($row['quantity'] > 0) {
                                $appsPdo->prepare('INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id, source_sector_id, target_sector_id, source_location, target_location, requires_signature, transfer_status) VALUES (:item,:dir,:amount,:reason,:user,:src,:tgt,:src_loc,:tgt_loc,:req,:status)')
                                    ->execute([
                                        ':item'    => $itemId,
                                        ':dir'     => 'in',
                                        ':amount'  => $row['quantity'],
                                        ':reason'  => 'Initial quantity',
                                        ':user'    => $currentUserId,
                                        ':src'     => null,
                                        ':tgt'     => $row['sector'],
                                        ':src_loc' => null,
                                        ':tgt_loc' => $row['location'] !== '' ? $row['location'] : null,
                                        ':req'     => 0,
                                        ':status'  => 'signed',
                                    ]);
                            }
                        }
                        $appsPdo->commit();
                        redirect_with_message('inventory.php', 'Items added.');
                    } catch (Throwable $e) {
                        $appsPdo->rollBack();
                        throw $e;
                    }
                }
            } elseif ($action === 'update_item') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorIn = $_POST['sector_id'] ?? '';

                $item = inventory_fetch_item($appsPdo, $itemId);
                if (!$item) {
                    $errors[] = 'Item not found.';
                } else {
                    $sectorId = $isRoot ? (($sectorIn === '' || $sectorIn === 'null') ? null : (int)$sectorIn) : $userSectorId;
                    if (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                        $errors[] = 'Cannot edit items from other sectors.';
                    }
                    if ($name === '') {
                        $errors[] = 'Name is required.';
                    }
                    if (!$isRoot && $sectorId === null) {
                        $errors[] = 'Your sector must be assigned before editing items.';
                    }
                    if (!$errors) {
                        $appsPdo->prepare('UPDATE inventory_items SET name=:name, sku=:sku, location=:location, sector_id=:sector WHERE id=:id')
                            ->execute([
                                ':name'     => $name,
                                ':sku'      => $sku !== '' ? $sku : null,
                                ':location' => $location !== '' ? $location : null,
                                ':sector'   => $sectorId,
                                ':id'       => $itemId,
                            ]);
                        redirect_with_message('inventory.php', 'Item updated.');
                    }
                }
            } elseif ($action === 'move_stock' || $action === 'bulk_move_stock') {
                $movementsForPdf = [];
                $lineItems = [];
                $itemsToProcess = [];

                if ($action === 'move_stock') {
                    $itemsToProcess[] = [
                        'item_id'           => (int)($_POST['item_id'] ?? 0),
                        'direction'         => ($_POST['direction'] ?? '') === 'out' ? 'out' : 'in',
                        'amount'            => max(1, (int)($_POST['amount'] ?? 0)),
                        'reason'            => trim((string)($_POST['reason'] ?? '')),
                        'target_sector_id'  => isset($_POST['target_sector_id']) && $_POST['target_sector_id'] !== '' && $_POST['target_sector_id'] !== 'null'
                            ? (int)$_POST['target_sector_id'] : null,
                        'requires_signature'=> isset($_POST['requires_signature']) ? (bool)$_POST['requires_signature'] : false,
                        'target_location'   => trim((string)($_POST['target_location'] ?? '')),
                        'notes'             => trim((string)($_POST['notes'] ?? '')),
                    ];
                } else {
                    $bulk = $_POST['move'] ?? [];
                    $itemIds  = $bulk['item_id'] ?? [];
                    $dirs     = $bulk['direction'] ?? [];
                    $amounts  = $bulk['amount'] ?? [];
                    $reasons  = $bulk['reason'] ?? [];
                    $targets  = $bulk['target_sector_id'] ?? [];
                    $reqs     = $bulk['requires_signature'] ?? [];
                    $locations= $bulk['target_location'] ?? [];
                    $notesArr = $bulk['notes'] ?? [];
                    foreach ((array)$itemIds as $idx => $itemIdVal) {
                        $iid = (int)$itemIdVal;
                        if ($iid <= 0) {
                            continue;
                        }
                        $amt = max(1, (int)($amounts[$idx] ?? 0));
                        $dir = ($dirs[$idx] ?? '') === 'out' ? 'out' : 'in';
                        $itemsToProcess[] = [
                            'item_id'           => $iid,
                            'direction'         => $dir,
                            'amount'            => $amt,
                            'reason'            => trim((string)($reasons[$idx] ?? '')),
                            'target_sector_id'  => isset($targets[$idx]) && $targets[$idx] !== '' && $targets[$idx] !== 'null'
                                ? (int)$targets[$idx] : null,
                            'requires_signature'=> isset($reqs[$idx]) && $reqs[$idx] ? true : false,
                            'target_location'   => trim((string)($locations[$idx] ?? '')),
                            'notes'             => trim((string)($notesArr[$idx] ?? '')),
                        ];
                    }
                }

                if (!$itemsToProcess) {
                    $errors[] = 'Provide at least one movement row.';
                }

                if (!$errors) {
                    $appsPdo->beginTransaction();
                    try {
                        foreach ($itemsToProcess as $movementRow) {
                            $item = inventory_fetch_item($appsPdo, (int)$movementRow['item_id']);
                            if (!$item) {
                                throw new RuntimeException('Item not found for movement.');
                            }
                            if (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                                throw new RuntimeException('Cannot move stock for other sectors.');
                            }

                            $direction = $movementRow['direction'];
                            $amount    = $movementRow['amount'];
                            $reason    = $movementRow['reason'];
                            $targetSectorId = $movementRow['target_sector_id'];
                            $targetLocation = $movementRow['target_location'];
                            $notes    = $movementRow['notes'];

                            $sourceSectorId = $item['sector_id'] !== null ? (int)$item['sector_id'] : null;
                            $requiresSignature = $movementRow['requires_signature'] || ($targetSectorId !== null && $targetSectorId !== $sourceSectorId);
                            $delta = $direction === 'in' ? $amount : -$amount;
                            $newQuantity = (int)$item['quantity'] + $delta;
                            if ($newQuantity < 0) {
                                throw new RuntimeException('Not enough stock to move "' . ($item['name'] ?? '') . '".');
                            }

                            $appsPdo->prepare('UPDATE inventory_items SET quantity = quantity + :delta WHERE id = :id')
                                ->execute([':delta' => $delta, ':id' => (int)$item['id']]);

                            if ($direction === 'out') {
                                if ($sourceSectorId !== null) {
                                    inventory_adjust_stock($appsPdo, (int)$item['id'], $sourceSectorId, -$amount);
                                }
                                if ($targetSectorId !== null) {
                                    inventory_adjust_stock($appsPdo, (int)$item['id'], $targetSectorId, $amount);
                                }
                            } else {
                                $destSector = $targetSectorId ?? $sourceSectorId;
                                if ($destSector !== null) {
                                    inventory_adjust_stock($appsPdo, (int)$item['id'], $destSector, $amount);
                                }
                            }

                            $appsPdo->prepare('INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id, source_sector_id, target_sector_id, source_location, target_location, requires_signature, transfer_status, notes) VALUES (:item,:dir,:amount,:reason,:user,:src,:tgt,:src_loc,:tgt_loc,:req,:status,:notes)')
                                ->execute([
                                    ':item'    => (int)$item['id'],
                                    ':dir'     => $direction,
                                    ':amount'  => $amount,
                                    ':reason'  => $reason !== '' ? $reason : null,
                                    ':user'    => $currentUserId,
                                    ':src'     => $sourceSectorId,
                                    ':tgt'     => $targetSectorId,
                                    ':src_loc' => $item['location'] ?? null,
                                    ':tgt_loc' => $targetLocation !== '' ? $targetLocation : null,
                                    ':req'     => $requiresSignature ? 1 : 0,
                                    ':status'  => $requiresSignature ? 'pending' : 'signed',
                                    ':notes'   => $notes !== '' ? $notes : null,
                                ]);

                            $movementId = (int)$appsPdo->lastInsertId();
                            $item['quantity'] = $newQuantity;

                            if ($requiresSignature) {
                                $movementsForPdf[] = [
                                    'id'               => $movementId,
                                    'item_id'          => (int)$item['id'],
                                    'direction'        => $direction,
                                    'amount'           => $amount,
                                    'reason'           => $reason,
                                    'source_sector_id' => $sourceSectorId,
                                    'target_sector_id' => $targetSectorId,
                                ];
                                $lineItems[] = [
                                    'name'      => $item['name'],
                                    'sku'       => $item['sku'] ?? '',
                                    'amount'    => $amount,
                                    'direction' => $direction,
                                    'reason'    => $reason !== '' ? $reason : $notes,
                                ];
                            }
                        }
                        $appsPdo->commit();
                    } catch (Throwable $e) {
                        $appsPdo->rollBack();
                        $errors[] = $e->getMessage();
                    }

                    if (!$errors && $movementsForPdf) {
                        try {
                            $sectorRows = (array)$corePdo->query('SELECT id,name FROM sectors')->fetchAll();
                            inventory_generate_transfer_pdf($appsPdo, $movementsForPdf, $lineItems, $sectorRows, $currentUser);
                        } catch (Throwable $e) {
                            $errors[] = 'Movements recorded but PDF could not be generated: ' . $e->getMessage();
                        }
                    }

                    if (!$errors) {
                        redirect_with_message('inventory.php', 'Movement recorded.');
                    }
                }
            } elseif ($action === 'upload_movement_file') {
                $movementId = (int)($_POST['movement_id'] ?? 0);
                $kind       = in_array($_POST['kind'] ?? 'signature', ['signature','photo','other'], true) ? $_POST['kind'] : 'signature';
                $label      = trim((string)($_POST['label'] ?? ''));

                $movement = null;
                if ($movementId > 0) {
                    $stmt = $appsPdo->prepare('SELECT * FROM inventory_movements WHERE id = ?');
                    $stmt->execute([$movementId]);
                    $movement = $stmt->fetch();
                }
                if (!$movement) {
                    $errors[] = 'Movement not found.';
                }

                if (!$errors) {
                    try {
                        $upload = inventory_s3_upload_file($_FILES['movement_file'] ?? []);
                        inventory_store_movement_file($appsPdo, $movementId, $upload, $label !== '' ? $label : null, $kind, $currentUserId);
                        if ($kind === 'signature') {
                            $appsPdo->prepare('UPDATE inventory_movements SET transfer_status = :status WHERE id = :id')
                                ->execute([':status' => 'signed', ':id' => $movementId]);
                        }
                        redirect_with_message('inventory.php', 'File uploaded.');
                    } catch (Throwable $e) {
                        $errors[] = 'Upload failed: ' . $e->getMessage();
                    }
                }
            } elseif ($action === 'mark_movement_signed') {
                $movementId = (int)($_POST['movement_id'] ?? 0);
                $appsPdo->prepare('UPDATE inventory_movements SET transfer_status = :status WHERE id = :id')
                    ->execute([':status' => 'signed', ':id' => $movementId]);
                redirect_with_message('inventory.php', 'Movement marked as signed.');
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Server error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

$sectorOptions = [];
try {
    $sectorOptions = $corePdo->query('SELECT id, name FROM sectors ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Sectors table missing in CORE DB (or query failed).';
}

if ($isRoot) {
    $sectorFilter = $_GET['sector'] ?? '';
} elseif ($userSectorId !== null) {
    $sectorFilter = (string)$userSectorId;
} else {
    $sectorFilter = 'null';
}

$where = [];
$params= [];
if ($sectorFilter !== '' && $sectorFilter !== 'all') {
    if ($sectorFilter === 'null') {
        $where[] = 'sector_id IS NULL';
    } else {
        $where[] = 'sector_id = :sector';
        $params[':sector'] = (int)$sectorFilter;
    }
}
if (!$isRoot && $userSectorId !== null) {
    $where[] = 'sector_id = :my_sector';
    $params[':my_sector'] = (int)$userSectorId;
}
if (!$isRoot && $userSectorId === null) {
    $where[] = 'sector_id IS NULL';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$items = [];
$movementsByItem = [];
$movementFiles = [];
$movementTokens = [];

try {
    $itemStmt = $appsPdo->prepare("SELECT * FROM inventory_items $whereSql ORDER BY name");
    $itemStmt->execute($params);
    $items = $itemStmt->fetchAll();

    $itemIds = array_map(static fn($row) => (int)$row['id'], $items);
    $movementsByItem = inventory_fetch_movements($appsPdo, $itemIds);

    $movementIds = [];
    foreach ($movementsByItem as $itemMovements) {
        foreach ($itemMovements as $mov) {
            $movementIds[] = (int)$mov['id'];
        }
    }
    $movementFiles  = inventory_fetch_movement_files($appsPdo, $movementIds);
    $movementTokens = inventory_fetch_public_tokens($appsPdo, $movementIds);
} catch (Throwable $e) {
    $errors[] = 'Inventory tables missing in APPS DB (or query failed).';
}

function sector_name_by_id(array $sectors, $id): string {
    foreach ($sectors as $s) {
        if ((string)$s['id'] === (string)$id) return (string)$s['name'];
    }
    return '';
}

$title = 'Inventory';
include __DIR__ . '/includes/header.php';
?>
<section class="card">
  <div class="card-header">
    <div>
      <h1>Inventory</h1>
      <p class="muted">Manage items, bulk operations and movement paper trail.</p>
    </div>
    <div class="actions-stack">
      <span class="badge">Items: <?php echo number_format(count($items)); ?></span>
      <?php if ($canManage): ?>
        <button class="btn primary" data-modal-open="modal-add">Add Item</button>
        <button class="btn" data-modal-open="modal-bulk-add">Bulk Add</button>
        <button class="btn" data-modal-open="modal-bulk-move">Bulk Movement</button>
      <?php endif; ?>
    </div>
  </div>

  <?php flash_message(); ?>
  <?php if ($errors): ?>
    <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <form method="get" class="filters filters--toolbar" autocomplete="off">
    <label>Sector
      <select name="sector" <?php echo $isRoot ? '' : 'disabled'; ?>>
        <option value="all" <?php echo ($sectorFilter === '' || $sectorFilter === 'all') ? 'selected' : ''; ?>>All</option>
        <option value="null" <?php echo $sectorFilter === 'null' ? 'selected' : ''; ?>>Unassigned</option>
        <?php foreach ((array)$sectorOptions as $sector): ?>
          <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$sector['id'] === (string)$sectorFilter) ? 'selected' : ''; ?>>
            <?php echo sanitize((string)$sector['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="filter-actions">
      <?php if ($isRoot): ?>
        <button class="btn primary" type="submit">Filter</button>
        <a class="btn secondary" href="inventory.php">Reset</a>
      <?php else: ?>
        <span class="muted small">Filtering limited to your sector.</span>
      <?php endif; ?>
    </div>
  </form>
</section>

<section class="card card--inventory">
  <div class="card-header">
    <h2>Items</h2>
  </div>

  <div class="inventory-grid">
    <?php foreach ($items as $item): ?>
      <article class="inventory-item">
        <header class="inventory-item__header">
          <div>
            <h3><?php echo sanitize((string)$item['name']); ?></h3>
            <p class="muted">SKU: <?php echo !empty($item['sku']) ? sanitize((string)$item['sku']) : '<em>—</em>'; ?></p>
          </div>
          <div class="inventory-item__qty">
            <span class="qty-label">Qty</span>
            <span class="qty-value"><?php echo (int)$item['quantity']; ?></span>
          </div>
        </header>
        <dl class="inventory-item__meta">
          <div>
            <dt>Sector</dt>
            <dd>
              <?php
                $sn = sector_name_by_id((array)$sectorOptions, $item['sector_id']);
                echo $sn !== '' ? sanitize($sn) : '<span class="badge badge-muted">Unassigned</span>';
              ?>
            </dd>
          </div>
          <div>
            <dt>Location</dt>
            <dd><?php echo !empty($item['location']) ? sanitize((string)$item['location']) : '<em class="muted">—</em>'; ?></dd>
          </div>
        </dl>

        <?php if ($canManage && ($isRoot || (int)$item['sector_id'] === (int)$userSectorId || $isRoot)): ?>
          <div class="inventory-item__actions">
            <button class="btn small secondary" data-modal-open="modal-edit-<?php echo (int)$item['id']; ?>">Edit</button>
            <button class="btn small" data-modal-open="modal-move-<?php echo (int)$item['id']; ?>">Move</button>
          </div>
        <?php endif; ?>

        <div class="inventory-movements">
          <h4>Movements</h4>
          <ul>
            <?php foreach ($movementsByItem[(int)$item['id']] ?? [] as $movement): ?>
              <?php
                $movementId = (int)$movement['id'];
                $direction  = $movement['direction'] === 'out' ? 'out' : 'in';
                $chipClass  = $direction === 'out' ? 'chip-out' : 'chip-in';
                $sectorFrom = $movement['source_sector_id'] !== null ? inventory_sector_name((array)$sectorOptions, $movement['source_sector_id']) : '';
                $sectorTo   = $movement['target_sector_id'] !== null ? inventory_sector_name((array)$sectorOptions, $movement['target_sector_id']) : '';
                $attachments = $movementFiles[$movementId] ?? [];
                $tokens = $movementTokens[$movementId] ?? [];
              ?>
              <li>
                <div class="movement-main">
                  <span class="chip <?php echo $chipClass; ?>"><?php echo strtoupper($direction); ?></span>
                  <strong><?php echo (int)$movement['amount']; ?></strong>
                  <span class="muted small">&middot; <?php echo sanitize((string)$movement['ts']); ?></span>
                </div>
                <div class="movement-meta">
                  <?php if (!empty($movement['reason'])): ?>
                    <span class="tag">Reason: <?php echo sanitize((string)$movement['reason']); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($movement['notes'])): ?>
                    <span class="tag">Notes: <?php echo sanitize((string)$movement['notes']); ?></span>
                  <?php endif; ?>
                  <?php if ($sectorFrom !== '' || $sectorTo !== ''): ?>
                    <span class="tag">Route: <?php echo $sectorFrom !== '' ? sanitize($sectorFrom) : '—'; ?> → <?php echo $sectorTo !== '' ? sanitize($sectorTo) : '—'; ?></span>
                  <?php endif; ?>
                  <?php if (!empty($movement['transfer_form_url'])): ?>
                    <a class="tag tag-link" href="<?php echo sanitize((string)$movement['transfer_form_url']); ?>" target="_blank" rel="noopener">
                      📄 Transfer PDF
                    </a>
                  <?php endif; ?>
                  <?php if ($tokens): ?>
                    <?php $tokenRow = end($tokens); ?>
                    <span class="tag">QR Token expires <?php echo sanitize((string)$tokenRow['expires_at']); ?></span>
                  <?php endif; ?>
                  <span class="tag status-<?php echo sanitize((string)$movement['transfer_status']); ?>">Status: <?php echo ucfirst((string)$movement['transfer_status']); ?></span>
                </div>
                <?php if ($attachments): ?>
                  <div class="movement-files">
                    <?php foreach ($attachments as $file): ?>
                      <a class="file-pill" href="<?php echo sanitize((string)$file['file_url']); ?>" target="_blank" rel="noopener">
                        📎 <?php echo sanitize((string)($file['label'] ?? basename((string)$file['file_key']))); ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if ($canManage): ?>
                  <button class="btn tiny" data-modal-open="modal-upload-<?php echo $movementId; ?>">Upload Paper Trail</button>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
            <?php if (empty($movementsByItem[(int)$item['id']])): ?>
              <li class="muted small">No movements yet.</li>
            <?php endif; ?>
          </ul>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (empty($items)): ?>
      <div class="muted">No items found for the selected filter.</div>
    <?php endif; ?>
  </div>
</section>
<?php if ($canManage): ?>
  <div class="modal" id="modal-add" hidden>
    <div class="modal__dialog">
      <header class="modal__header">
        <h3>Add Item</h3>
        <button class="modal__close" data-modal-close>&times;</button>
      </header>
      <form method="post" class="modal__body" autocomplete="off">
        <label>Name
          <input type="text" name="name" required>
        </label>
        <label>SKU
          <input type="text" name="sku">
        </label>
        <label>Initial Quantity
          <input type="number" name="quantity" min="0" value="0">
        </label>
        <label>Location
          <input type="text" name="location" placeholder="Shelf, cabinet...">
        </label>
        <?php if ($isRoot): ?>
          <label>Sector
            <select name="sector_id">
              <option value="null">Unassigned</option>
              <?php foreach ((array)$sectorOptions as $sector): ?>
                <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize((string)$sector['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <footer class="modal__footer">
          <input type="hidden" name="action" value="create_item">
          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
          <button class="btn primary" type="submit">Save</button>
        </footer>
      </form>
    </div>
  </div>

  <div class="modal" id="modal-bulk-add" hidden>
    <div class="modal__dialog modal__dialog--wide">
      <header class="modal__header">
        <h3>Bulk Add Items</h3>
        <button class="modal__close" data-modal-close>&times;</button>
      </header>
      <form method="post" class="modal__body" autocomplete="off">
        <div class="bulk-grid" data-bulk-container>
          <div class="bulk-row" data-template>
            <label>Name
              <input type="text" name="bulk[name][]" required>
            </label>
            <label>SKU
              <input type="text" name="bulk[sku][]">
            </label>
            <label>Quantity
              <input type="number" name="bulk[quantity][]" min="0" value="0">
            </label>
            <label>Location
              <input type="text" name="bulk[location][]">
            </label>
            <?php if ($isRoot): ?>
              <label>Sector
                <select name="bulk[sector_id][]">
                  <option value="null">Unassigned</option>
                  <?php foreach ((array)$sectorOptions as $sector): ?>
                    <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize((string)$sector['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php else: ?>
              <input type="hidden" name="bulk[sector_id][]" value="<?php echo (int)$userSectorId; ?>">
            <?php endif; ?>
          </div>
        </div>
        <button class="btn secondary" type="button" data-add-row>+ Add another row</button>
        <footer class="modal__footer">
          <input type="hidden" name="action" value="bulk_create_items">
          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
          <button class="btn primary" type="submit">Import</button>
        </footer>
      </form>
    </div>
  </div>

  <div class="modal" id="modal-bulk-move" hidden>
    <div class="modal__dialog modal__dialog--wide">
      <header class="modal__header">
        <h3>Bulk Movement</h3>
        <button class="modal__close" data-modal-close>&times;</button>
      </header>
      <form method="post" class="modal__body" autocomplete="off">
        <div class="bulk-grid" data-bulk-move-container>
          <div class="bulk-row" data-template>
            <label>Item
              <select name="move[item_id][]">
                <option value="">Select item…</option>
                <?php foreach ($items as $row): ?>
                  <option value="<?php echo (int)$row['id']; ?>"><?php echo sanitize((string)$row['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Direction
              <select name="move[direction][]">
                <option value="in">In</option>
                <option value="out">Out</option>
              </select>
            </label>
            <label>Amount
              <input type="number" name="move[amount][]" min="1" value="1">
            </label>
            <label>Target Sector
              <select name="move[target_sector_id][]">
                <option value="">None</option>
                <option value="null">Unassigned</option>
                <?php foreach ((array)$sectorOptions as $sector): ?>
                  <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize((string)$sector['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Reason
              <input type="text" name="move[reason][]">
            </label>
            <label>Notes
              <input type="text" name="move[notes][]" placeholder="Optional details">
            </label>
            <label>Target Location
              <input type="text" name="move[target_location][]" placeholder="Shelf, room…">
            </label>
            <label class="checkbox">
              <input type="checkbox" name="move[requires_signature][]" value="1">
              Require signatures
            </label>
          </div>
        </div>
        <button class="btn secondary" type="button" data-add-move-row>+ Add another row</button>
        <footer class="modal__footer">
          <input type="hidden" name="action" value="bulk_move_stock">
          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
          <button class="btn primary" type="submit">Record Movements</button>
        </footer>
      </form>
    </div>
  </div>

  <?php foreach ($items as $item): ?>
    <div class="modal" id="modal-edit-<?php echo (int)$item['id']; ?>" hidden>
      <div class="modal__dialog">
        <header class="modal__header">
          <h3>Edit Item</h3>
          <button class="modal__close" data-modal-close>&times;</button>
        </header>
        <form method="post" class="modal__body" autocomplete="off">
          <label>Name
            <input type="text" name="name" value="<?php echo sanitize((string)$item['name']); ?>" required>
          </label>
          <label>SKU
            <input type="text" name="sku" value="<?php echo sanitize((string)($item['sku'] ?? '')); ?>">
          </label>
          <label>Location
            <input type="text" name="location" value="<?php echo sanitize((string)($item['location'] ?? '')); ?>">
          </label>
          <?php if ($isRoot): ?>
            <label>Sector
              <select name="sector_id">
                <option value="null" <?php echo $item['sector_id'] === null ? 'selected':''; ?>>Unassigned</option>
                <?php foreach ((array)$sectorOptions as $sector): ?>
                  <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$item['sector_id'] === (string)$sector['id']) ? 'selected' : ''; ?>>
                    <?php echo sanitize((string)$sector['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
          <footer class="modal__footer">
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
            <button class="btn primary" type="submit">Save</button>
          </footer>
        </form>
      </div>
    </div>

    <div class="modal" id="modal-move-<?php echo (int)$item['id']; ?>" hidden>
      <div class="modal__dialog">
        <header class="modal__header">
          <h3>Move <?php echo sanitize((string)$item['name']); ?></h3>
          <button class="modal__close" data-modal-close>&times;</button>
        </header>
        <form method="post" class="modal__body" autocomplete="off">
          <label>Direction
            <select name="direction">
              <option value="in">In</option>
              <option value="out">Out</option>
            </select>
          </label>
          <label>Amount
            <input type="number" name="amount" min="1" value="1">
          </label>
          <label>Reason
            <input type="text" name="reason" placeholder="Reason for movement">
          </label>
          <label>Notes
            <input type="text" name="notes" placeholder="Optional notes">
          </label>
          <label>Target Sector
            <select name="target_sector_id">
              <option value="">None</option>
              <option value="null">Unassigned</option>
              <?php foreach ((array)$sectorOptions as $sector): ?>
                <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize((string)$sector['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Target Location
            <input type="text" name="target_location" placeholder="Shelf, room…">
          </label>
          <label class="checkbox">
            <input type="checkbox" name="requires_signature" value="1" checked>
            Require signatures &amp; PDF trail
          </label>
          <footer class="modal__footer">
            <input type="hidden" name="action" value="move_stock">
            <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
            <button class="btn primary" type="submit">Record Movement</button>
          </footer>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <?php foreach ($movementsByItem as $itemId => $movementList): ?>
    <?php foreach ($movementList as $movement): ?>
      <?php $movementId = (int)$movement['id']; ?>
      <div class="modal" id="modal-upload-<?php echo $movementId; ?>" hidden>
        <div class="modal__dialog">
          <header class="modal__header">
            <h3>Upload Paper Trail</h3>
            <button class="modal__close" data-modal-close>&times;</button>
          </header>
          <form method="post" class="modal__body" enctype="multipart/form-data" autocomplete="off">
            <label>File
              <input type="file" name="movement_file" accept="image/*,application/pdf" required>
            </label>
            <label>Label
              <input type="text" name="label" placeholder="e.g. Signed form page 1">
            </label>
            <label>Type
              <select name="kind">
                <option value="signature">Signature</option>
                <option value="photo">Photo</option>
                <option value="other">Other</option>
              </select>
            </label>
            <footer class="modal__footer">
              <input type="hidden" name="action" value="upload_movement_file">
              <input type="hidden" name="movement_id" value="<?php echo $movementId; ?>">
              <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
              <button class="btn primary" type="submit">Upload</button>
            </footer>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php endif; ?>
<style>
.card--inventory { margin-top: 1.5rem; }
.actions-stack { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
.inventory-grid { display:grid; gap:1rem; grid-template-columns: repeat(auto-fit,minmax(280px,1fr)); }
.inventory-item { border:1px solid var(--line,#e5e7eb); border-radius:16px; padding:1rem; background:#fff; display:flex; flex-direction:column; gap:1rem; box-shadow:0 20px 40px -24px rgba(15,23,42,0.25); }
.inventory-item__header { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; }
.inventory-item__header h3 { margin:0; font-size:1.15rem; }
.inventory-item__qty { text-align:right; }
.qty-label { font-size:.65rem; text-transform:uppercase; letter-spacing:.1em; color:#64748b; display:block; }
.qty-value { font-size:1.8rem; font-weight:700; color:#111827; }
.inventory-item__meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:.75rem; margin:0; }
.inventory-item__meta dt { font-size:.7rem; text-transform:uppercase; color:#94a3b8; letter-spacing:.1em; margin-bottom:.25rem; }
.inventory-item__meta dd { margin:0; font-size:.9rem; color:#1f2937; }
.inventory-item__actions { display:flex; gap:.5rem; }
.inventory-movements h4 { margin:0 0 .5rem; font-size:.95rem; text-transform:uppercase; letter-spacing:.08em; color:#0f172a; }
.inventory-movements ul { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:.75rem; }
.inventory-movements li { border:1px solid #e2e8f0; border-radius:12px; padding:.75rem; background:#f8fafc; display:flex; flex-direction:column; gap:.5rem; }
.movement-main { display:flex; gap:.6rem; align-items:center; }
.movement-meta { display:flex; flex-wrap:wrap; gap:.4rem; font-size:.75rem; color:#475569; }
.movement-files { display:flex; flex-wrap:wrap; gap:.4rem; }
.tag { background:#e2e8f0; border-radius:999px; padding:.2rem .6rem; }
.tag-link { text-decoration:none; color:#1d4ed8; background:#e0e7ff; }
.file-pill { display:inline-flex; align-items:center; gap:.3rem; padding:.3rem .6rem; background:#fff; border-radius:999px; border:1px solid #cbd5f5; text-decoration:none; font-size:.75rem; color:#1d4ed8; }
.file-pill:hover { background:#e0e7ff; }
.chip { display:inline-block; padding:.15rem .55rem; border-radius:999px; font-size:.75rem; font-weight:700; }
.chip-in { background:#dcfce7; color:#166534; }
.chip-out { background:#fee2e2; color:#b91c1c; }
.badge-muted { background:#e2e8f0; color:#475569; padding:.2rem .6rem; border-radius:999px; }
.status-pending { background:#fef08a; color:#854d0e; }
.status-signed { background:#bbf7d0; color:#166534; }
.modal { position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(15,23,42,0.48); z-index:1000; padding:1.5rem; }
.modal[hidden] { display:none; }
.modal__dialog { background:#fff; border-radius:16px; max-width:520px; width:100%; box-shadow:0 30px 70px -40px rgba(15,23,42,0.7); overflow:hidden; display:flex; flex-direction:column; }
.modal__dialog--wide { max-width:880px; }
.modal__header { padding:1rem 1.25rem; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; }
.modal__header h3 { margin:0; font-size:1.1rem; }
.modal__close { background:none; border:none; font-size:1.5rem; cursor:pointer; color:#334155; }
.modal__body { padding:1.25rem; display:grid; gap:1rem; }
.modal__body label { display:flex; flex-direction:column; gap:.35rem; font-size:.85rem; color:#1f2937; }
.modal__body input, .modal__body select, .modal__body textarea { border:1px solid #cbd5e1; border-radius:10px; padding:.6rem .75rem; font-size:.9rem; }
.modal__footer { padding:1rem 1.25rem; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:.75rem; }
.bulk-grid { display:flex; flex-direction:column; gap:1rem; }
.bulk-row { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); padding:1rem; border:1px dashed #cbd5f5; border-radius:12px; background:#f8fafc; }
.checkbox { display:flex; gap:.5rem; align-items:center; }
.filters--toolbar { margin-top:1rem; }
.btn.tiny { align-self:flex-start; }
@media (max-width: 640px) {
  .modal { padding:.5rem; }
  .modal__dialog { max-height:95vh; overflow:auto; }
}
</style>

<script>
(function(){
  const modalButtons = document.querySelectorAll('[data-modal-open]');
  modalButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-modal-open');
      const modal = document.getElementById(id);
      if (modal) {
        modal.hidden = false;
      }
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      const modal = btn.closest('.modal');
      if (modal) modal.hidden = true;
    });
  });
  document.addEventListener('click', evt => {
    if (evt.target.classList && evt.target.classList.contains('modal')) {
      evt.target.hidden = true;
    }
  });

  const cloneRow = (container, template) => {
    const clone = template.cloneNode(true);
    clone.querySelectorAll('input').forEach(input => {
      if (input.type === 'text') input.value = '';
      if (input.type === 'number') input.value = input.getAttribute('min') || 0;
      if (input.type === 'checkbox') input.checked = false;
    });
    clone.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
    container.appendChild(clone);
  };

  const bulkContainer = document.querySelector('[data-bulk-container]');
  const bulkTemplate = bulkContainer ? bulkContainer.querySelector('[data-template]') : null;
  document.querySelectorAll('[data-add-row]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (bulkContainer && bulkTemplate) {
        cloneRow(bulkContainer, bulkTemplate);
      }
    });
  });

  const moveContainer = document.querySelector('[data-bulk-move-container]');
  const moveTemplate = moveContainer ? moveContainer.querySelector('[data-template]') : null;
  document.querySelectorAll('[data-add-move-row]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (moveContainer && moveTemplate) {
        cloneRow(moveContainer, moveTemplate);
      }
    });
  });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
