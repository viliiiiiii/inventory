<?php
require_once __DIR__ . '/helpers.php';

$pdo = get_pdo();

$pdo->exec('DELETE FROM task_photos');
$pdo->exec('DELETE FROM tasks');
$pdo->exec('DELETE FROM rooms');
$pdo->exec('DELETE FROM buildings');
$pdo->exec('DELETE FROM users');

$pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, "admin")')
    ->execute(['admin@example.com', password_hash('admin123', PASSWORD_DEFAULT)]);

echo "Seeded admin user admin@example.com / admin123\n";

$buildings = [
    'North Tower',
    'South Wing',
];

foreach ($buildings as $name) {
    $pdo->prepare('INSERT INTO buildings (name) VALUES (?)')->execute([$name]);
}

$buildingRows = $pdo->query('SELECT * FROM buildings')->fetchAll();

$roomData = [
    [$buildingRows[0]['id'], '101', 'Lobby'],
    [$buildingRows[0]['id'], '102', 'Conference'],
    [$buildingRows[1]['id'], '201', 'Mechanical'],
    [$buildingRows[1]['id'], '202', 'Electrical'],
];

foreach ($roomData as $room) {
    $pdo->prepare('INSERT INTO rooms (building_id, room_number, label) VALUES (?, ?, ?)')->execute($room);
}

$rooms = $pdo->query('SELECT * FROM rooms')->fetchAll();

$tasks = [
    [
        'building_id' => $rooms[0]['building_id'],
        'room_id' => $rooms[0]['id'],
        'title' => 'Fix ceiling tiles',
        'description' => 'Several tiles cracked near entrance.',
        'priority' => 'mid/high',
        'assigned_to' => 'Alex',
        'status' => 'open',
        'due_date' => date('Y-m-d', strtotime('+5 days')),
    ],
    [
        'building_id' => $rooms[1]['building_id'],
        'room_id' => $rooms[1]['id'],
        'title' => 'Replace projector',
        'description' => 'Old projector flickering; replace with new model.',
        'priority' => 'mid',
        'assigned_to' => 'Jordan',
        'status' => 'in_progress',
        'due_date' => date('Y-m-d', strtotime('+10 days')),
    ],
    [
        'building_id' => $rooms[2]['building_id'],
        'room_id' => $rooms[2]['id'],
        'title' => 'Clean equipment',
        'description' => 'Deep clean mechanical equipment and remove debris.',
        'priority' => 'low',
        'assigned_to' => '',
        'status' => 'done',
        'due_date' => date('Y-m-d', strtotime('-2 days')),
    ],
];

foreach ($tasks as $task) {
    $stmt = $pdo->prepare('INSERT INTO tasks (building_id, room_id, title, description, priority, assigned_to, status, due_date, created_by) VALUES (:building_id, :room_id, :title, :description, :priority, :assigned_to, :status, :due_date, :created_by)');
    $stmt->execute($task + ['created_by' => 1]);
}

echo "Seeded sample buildings, rooms, and tasks.\n";
