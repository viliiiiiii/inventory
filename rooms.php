<?php
require_once __DIR__ . '/helpers.php';
require_login();
if (!can('view')) {
    http_response_code(403);
    exit('Forbidden');
}

if (isset($_GET['action']) && $_GET['action'] === 'by_building') {
    $buildingId = (int)($_GET['id'] ?? 0);
    $rooms = fetch_rooms_by_building($buildingId);
    $formatted = array_map(fn($room) => [
        'id' => $room['id'],
        'label' => $room['room_number'] . ($room['label'] ? ' - ' . $room['label'] : ''),
        'room_number' => $room['room_number'], // <-- added explicit room_number for JS validation
    ], $rooms);
    json_response($formatted);
}

$pdo = get_pdo();
$errors = [];

if (is_post()) {
    if (!can('edit')) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors['csrf'] = 'Invalid CSRF token.';
    } elseif (isset($_POST['add_building'])) {
        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            $errors['building'] = 'Name required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO buildings (name) VALUES (?)');
            $stmt->execute([$name]);
            redirect_with_message('rooms.php', 'Building added.');
        }
    } elseif (isset($_POST['delete_building'])) {
        $buildingId = (int)$_POST['delete_building'];
        $stmt = $pdo->prepare('DELETE FROM buildings WHERE id = ?');
        $stmt->execute([$buildingId]);
        redirect_with_message('rooms.php', 'Building removed.');
    } elseif (isset($_POST['add_room'])) {
        $buildingId = (int)($_POST['building_id'] ?? 0);
        $roomNumber = trim($_POST['room_number'] ?? '');
        $label = trim($_POST['label'] ?? '') ?: null;
        if (!$buildingId || !$roomNumber) {
            $errors['room'] = 'Building and room number required.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO rooms (building_id, room_number, label) VALUES (?, ?, ?)');
                $stmt->execute([$buildingId, $roomNumber, $label]);
                redirect_with_message('rooms.php', 'Room added.');
            } catch (PDOException $e) {
                $errors['room'] = 'Room already exists for building.';
            }
        }
    } elseif (isset($_POST['delete_room'])) {
        $roomId = (int)$_POST['delete_room'];
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->execute([$roomId]);
        redirect_with_message('rooms.php', 'Room removed.');
    }
}

$buildings = $pdo->query('SELECT * FROM buildings ORDER BY name')->fetchAll();
$roomsByBuilding = [];
if ($buildings) {
    $buildingIds = array_column($buildings, 'id');
    if ($buildingIds) {
        $placeholders = implode(',', array_fill(0, count($buildingIds), '?'));
        $stmt = $pdo->prepare("SELECT r.*, b.name AS building_name FROM rooms r JOIN buildings b ON b.id = r.building_id WHERE r.building_id IN ($placeholders) ORDER BY b.name, r.room_number");
        $stmt->execute($buildingIds);
        while ($row = $stmt->fetch()) {
            $roomsByBuilding[$row['building_id']][] = $row;
        }
    }
}

$title = 'Buildings & Rooms';
include __DIR__ . '/includes/header.php';
?>
<section class="card">
    <h1>Buildings</h1>
    <form method="post" class="grid two">
        <label>New Building
            <input type="text" name="name" required placeholder="Building name">
            <?php if (!empty($errors['building'])): ?><span class="error"><?php echo sanitize($errors['building']); ?></span><?php endif; ?>
        </label>
        <div class="card-footer">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
            <button class="btn primary" type="submit" name="add_building" value="1">Add Building</button>
        </div>
    </form>
</section>

<section class="card">
    <h2>Rooms</h2>
    <form method="post" class="grid two">
        <label>Building
            <select name="building_id" required>
                <option value="">Select building</option>
                <?php foreach ($buildings as $building): ?>
                    <option value="<?php echo $building['id']; ?>"><?php echo sanitize($building['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Room Number
            <input type="text" name="room_number" required>
        </label>
        <label>Label (optional)
            <input type="text" name="label">
        </label>
        <div class="card-footer">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
            <button class="btn primary" type="submit" name="add_room" value="1">Add Room</button>
        </div>
        <?php if (!empty($errors['room'])): ?><span class="error"><?php echo sanitize($errors['room']); ?></span><?php endif; ?>
    </form>
</section>

<section class="card">
    <h2>Buildings &amp; Rooms</h2>
    <?php foreach ($buildings as $building): ?>
        <!-- removed "open" so it starts collapsed -->
        <details class="card sub-card building-collapsible">
            <summary class="card-header building-summary">
                <h3><?php echo sanitize($building['name']); ?></h3>
                <div class="actions">
                    <form method="post" onsubmit="return confirm('Delete building and its rooms?');">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                        <button class="btn danger" type="submit" name="delete_building" value="<?php echo $building['id']; ?>">Delete Building</button>
                    </form>
                </div>
            </summary>

            <?php if (!empty($roomsByBuilding[$building['id']])): ?>
                <div class="building-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Label</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roomsByBuilding[$building['id']] as $room): ?>
                                <tr>
                                    <td><?php echo sanitize($room['room_number']); ?></td>
                                    <td><?php echo sanitize($room['label'] ?? ''); ?></td>
                                    <td>
                                        <a class="btn small" href="tasks.php?building_id=<?php echo $building['id']; ?>&room_id=<?php echo $room['id']; ?>">View Tasks</a>
                                        <a class="btn small" href="export_room_pdf.php?room_id=<?php echo $room['id']; ?>" target="_blank">Export PDF</a>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete room?');">
                                            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                                            <button class="btn small danger" type="submit" name="delete_room" value="<?php echo $room['id']; ?>">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="building-content">
                    <p class="muted">No rooms yet.</p>
                </div>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>
</section>

<script>
document.addEventListener('click', function (e) {
  const summary = e.target.closest('summary.building-summary');
  if (!summary) return;
  if (e.target.closest('.actions')) {
    e.preventDefault();
    e.stopPropagation();
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
