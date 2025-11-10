<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_login();

// Optional dev diagnostics: uncomment for troubleshooting only
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

$appsPdo = get_pdo();        // APPS (punchlist) DB
$corePdo = get_pdo('core');  // CORE (users/roles/sectors/activity) DB — may be same as APPS if not split

$canManage    = can('inventory_manage');
$isRoot       = current_user_role_key() === 'root';
$userSectorId = current_user_sector_id();

$errors = [];

// --- POST actions ---
if (is_post()) {
    try {
        if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
            $errors[] = 'Invalid CSRF token.';
        } elseif (!$canManage) {
            $errors[] = 'Insufficient permissions.';
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'create_item') {
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $quantity = max(0, (int)($_POST['quantity'] ?? 0));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorInput = $_POST['sector_id'] ?? '';
                $sectorId = $isRoot ? (($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput) : $userSectorId;

                if ($name === '') {
                    $errors[] = 'Name is required.';
                }
                if (!$isRoot && $sectorId === null) {
                    $errors[] = 'Your sector must be assigned before creating items.';
                }

                if (!$errors) {
                    $stmt = $appsPdo->prepare('
                        INSERT INTO inventory_items (sku, name, sector_id, quantity, location)
                        VALUES (:sku, :name, :sector_id, :quantity, :location)
                    ');
                    $stmt->execute([
                        ':sku'       => $sku !== '' ? $sku : null,
                        ':name'      => $name,
                        ':sector_id' => $sectorId,
                        ':quantity'  => $quantity,
                        ':location'  => $location !== '' ? $location : null,
                    ]);
                    $itemId = (int)$appsPdo->lastInsertId();

                    if ($quantity > 0) {
                        $movStmt = $appsPdo->prepare('
                            INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id)
                            VALUES (:item_id, :direction, :amount, :reason, :user_id)
                        ');
                        $movStmt->execute([
                            ':item_id'  => $itemId,
                            ':direction'=> 'in',
                            ':amount'   => $quantity,
                            ':reason'   => 'Initial quantity',
                            ':user_id'  => current_user()['id'] ?? null,
                        ]);
                    }
                    log_event('inventory.add', 'inventory_item', $itemId, ['quantity' => $quantity, 'sector_id' => $sectorId]);
                    redirect_with_message('inventory.php', 'Item added.');
                }

            } elseif ($action === 'update_item') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorInput = $_POST['sector_id'] ?? '';

                $itemStmt = $appsPdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $errors[] = 'Item not found.';
                } else {
                    $sectorId = $isRoot ? (($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput) : $userSectorId;
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
                        $updStmt = $appsPdo->prepare('
                            UPDATE inventory_items
                            SET name=:name, sku=:sku, location=:location, sector_id=:sector_id
                            WHERE id=:id
                        ');
                        $updStmt->execute([
                            ':name'      => $name,
                            ':sku'       => $sku !== '' ? $sku : null,
                            ':location'  => $location !== '' ? $location : null,
                            ':sector_id' => $sectorId,
                            ':id'        => $itemId,
                        ]);
                        redirect_with_message('inventory.php', 'Item updated.');
                    }
                }

            } elseif ($action === 'move_stock') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $direction= $_POST['direction'] === 'out' ? 'out' : 'in';
                $amount   = max(1, (int)($_POST['amount'] ?? 0));
                $reason   = trim((string)($_POST['reason'] ?? ''));

                $itemStmt = $appsPdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $errors[] = 'Item not found.';
                } elseif (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                    $errors[] = 'Cannot move stock for other sectors.';
                } else {
                    $delta = $direction === 'in' ? $amount : -$amount;
                    $newQuantity = (int)$item['quantity'] + $delta;
                    if ($newQuantity < 0) {
                        $errors[] = 'Not enough stock to move.';
                    } else {
                        $appsPdo->beginTransaction();
                        try {
                            $appsPdo->prepare('UPDATE inventory_items SET quantity = quantity + :delta WHERE id = :id')
                                    ->execute([':delta' => $delta, ':id' => $itemId]);

                            $appsPdo->prepare('
                                INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id)
                                VALUES (:item_id, :direction, :amount, :reason, :user_id)
                            ')->execute([
                                ':item_id'  => $itemId,
                                ':direction'=> $direction,
                                ':amount'   => $amount,
                                ':reason'   => $reason !== '' ? $reason : null,
                                ':user_id'  => current_user()['id'] ?? null,
                            ]);

                            $appsPdo->commit();
                            log_event('inventory.move', 'inventory_item', $itemId, ['direction' => $direction, 'amount' => $amount]);
                            redirect_with_message('inventory.php', 'Stock updated.');
                        } catch (Throwable $e) {
                            $appsPdo->rollBack();
                            $errors[] = 'Unable to record movement.';
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Server error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// --- Fetch sectors (CORE) ---
$sectorOptions = [];
try {
    $sectorOptions = $corePdo->query('SELECT id, name FROM sectors ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Sectors table missing in CORE DB (or query failed).';
}

// --- Sector filter logic ---
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

// --- Fetch items & recent movements (APPS) ---
$items = [];
$movementsByItem = [];

try {
    $itemStmt = $appsPdo->prepare("SELECT * FROM inventory_items $whereSql ORDER BY name");
    $itemStmt->execute($params);
    $items = $itemStmt->fetchAll();

    if ($items) {
        $movementStmt = $appsPdo->prepare('SELECT * FROM inventory_movements WHERE item_id = ? ORDER BY ts DESC LIMIT 5');
        foreach ($items as $item) {
            $movementStmt->execute([$item['id']]);
            $movementsByItem[$item['id']] = $movementStmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Inventory tables missing in APPS DB (or query failed).';
}

// --- Helper to resolve sector name ---
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
    <h1>Inventory</h1>
    <div class="actions">
      <span class="badge">Items: <?php echo number_format(count($items)); ?></span>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <!-- Filter toolbar: mobile stacked, desktop compact via .filters -->
  <form method="get" class="filters" autocomplete="off">
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

<?php if ($canManage): ?>
<section class="card">
  <div class="card-header">
    <h2>Add Item</h2>
  </div>

  <!-- Compact multi-field form -->
  <form method="post" class="filters" autocomplete="off">
    <label>Name
      <input type="text" name="name" required placeholder="e.g. Light bulb E27">
    </label>

    <label>SKU
      <input type="text" name="sku" placeholder="Optional SKU">
    </label>

    <label>Initial Quantity
      <input type="number" name="quantity" min="0" value="0">
    </label>

    <label>Location
      <input type="text" name="location" placeholder="Aisle / Shelf">
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

    <div class="filter-actions">
      <input type="hidden" name="action" value="create_item">
      <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
      <button class="btn primary" type="submit">Add</button>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-header">
    <h2>Items</h2>
  </div>

  <!-- table--cards switches rows into cards on mobile -->
  <table class="table table--cards compact-rows">
    <thead>
      <tr>
        <th>Name</th>
        <th>SKU</th>
        <th>Sector</th>
        <th>Quantity</th>
        <th>Location</th>
        <th class="text-right">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td data-label="Name"><?php echo sanitize((string)$item['name']); ?></td>

        <td data-label="SKU">
          <?php echo !empty($item['sku']) ? sanitize((string)$item['sku']) : '<em class="muted">—</em>'; ?>
        </td>

        <td data-label="Sector">
          <?php
            $sn = sector_name_by_id((array)$sectorOptions, $item['sector_id']);
            echo $sn !== '' ? sanitize($sn) : '<span class="badge">Unassigned</span>';
          ?>
        </td>

        <td data-label="Quantity"><strong><?php echo (int)$item['quantity']; ?></strong></td>

        <td data-label="Location">
          <?php echo !empty($item['location']) ? sanitize((string)$item['location']) : '<em class="muted">—</em>'; ?>
        </td>

        <td data-label="Actions" class="text-right">
          <details class="item-actions">
            <summary class="btn small">Manage</summary>
            <div class="item-actions__box">
              <?php if ($canManage && ($isRoot || (int)$item['sector_id'] === (int)$userSectorId)): ?>
                <!-- Update item -->
                <form method="post" class="filters" style="margin-top:.5rem;">
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
                  <div class="filter-actions">
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                    <button class="btn small" type="submit">Save</button>
                  </div>
                </form>

                <!-- Move stock -->
                <form method="post" class="filters" style="margin-top:.5rem;">
                  <label>Direction
                    <select name="direction">
                      <option value="in">In</option>
                      <option value="out">Out</option>
                    </select>
                  </label>
                  <label>Amount
                    <input type="number" name="amount" min="1" value="1" required>
                  </label>
                  <label>Reason
                    <input type="text" name="reason" placeholder="Optional reason">
                  </label>
                  <div class="filter-actions">
                    <input type="hidden" name="action" value="move_stock">
                    <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                    <button class="btn small primary" type="submit">Record</button>
                  </div>
                </form>
              <?php else: ?>
                <p class="muted small" style="margin:.5rem 0 0;">No management rights for this item.</p>
              <?php endif; ?>

              <!-- Recent movements -->
              <h3 class="movements-title">Recent Movements</h3>
              <ul class="movements">
                <?php foreach ($movementsByItem[$item['id']] ?? [] as $move): ?>
                  <li>
                    <span class="chip <?php echo $move['direction'] === 'out' ? 'chip-out':'chip-in'; ?>">
                      <?php echo sanitize(strtoupper((string)$move['direction'])); ?>
                    </span>
                    <strong><?php echo (int)$move['amount']; ?></strong>
                    <span class="muted small">
                      &middot; <?php echo sanitize((string)$move['ts']); ?>
                      <?php if (!empty($move['reason'])): ?> &middot; <?php echo sanitize((string)$move['reason']); ?><?php endif; ?>
                    </span>
                  </li>
                <?php endforeach; ?>
                <?php if (empty($movementsByItem[$item['id']])): ?>
                  <li class="muted small">No movements yet.</li>
                <?php endif; ?>
              </ul>
            </div>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<!-- Small page-specific polish; move to app.css if you like -->
<style>
.item-actions summary.btn.small { cursor: pointer; }
.item-actions[open] summary.btn.small { opacity: .85; }

.item-actions__box{
  margin-top:.4rem;
  padding:.6rem .7rem;
  border:1px solid var(--line,#e7ecf3);
  background:#fff;
  border-radius:12px;
  box-shadow: 0 1px 0 rgba(0,0,0,.02);
}

/* Movements list */
.movements-title{
  margin:.6rem 0 .25rem;
  font-size:.9rem;
  font-weight:700;
}
.movements{
  list-style:none; padding:0; margin:.2rem 0 0;
  display:flex; flex-direction:column; gap:.35rem;
}
.movements li{
  display:flex; align-items:center; gap:.5rem; line-height:1.2;
}
.chip{
  display:inline-block; padding:.15rem .5rem; border-radius:999px; font-size:.75rem; font-weight:700;
  background:#eef2ff; color:#111827;
}
.chip-in{ background:#eaf7ef; }
.chip-out{ background:#fff1f2; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
