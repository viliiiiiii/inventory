<?php
// task_quick_update.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_login();
if (!can('edit')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $csrfName  = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : '_csrf';
    $csrfToken = $_POST[$csrfName] ?? null;
    if (!verify_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid task id']);
        exit;
    }

    $pdo = get_pdo();

    $beforeStmt = $pdo->prepare('SELECT id, title, assigned_to, status, priority, due_date, created_by FROM tasks WHERE id = ?');
    $beforeStmt->execute([$id]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Task not found']);
        exit;
    }

    // Gather fields to update (partial updates allowed)
    $set     = [];
    $bind    = [':id' => $id];
    $changedFields = [];

    // Assigned to (allow null/empty clears the field)
    if (array_key_exists('assigned_to', $_POST)) {
        $assigned = trim((string)($_POST['assigned_to'] ?? ''));
        $beforeAssigned = trim((string)($before['assigned_to'] ?? ''));
        if ($assigned === '') {
            $set[] = 'assigned_to = NULL';
        } else {
            $set[] = 'assigned_to = :assigned_to';
            $bind[':assigned_to'] = $assigned;
        }
        if ($assigned !== $beforeAssigned) {
            $changedFields[] = 'assigned_to';
        }
    }

    // Status
    if (array_key_exists('status', $_POST)) {
        $status = (string)($_POST['status'] ?? '');
        $allowedStatuses = get_statuses(); // e.g. ['open','in_progress','done',...]
        if (!in_array($status, $allowedStatuses, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid status']);
            exit;
        }
        $set[] = 'status = :status';
        $bind[':status'] = $status;
        if ($status !== (string)($before['status'] ?? '')) {
            $changedFields[] = 'status';
        }
    }

    // Priority
    if (array_key_exists('priority', $_POST)) {
        $priority = (string)($_POST['priority'] ?? '');
        $allowedPriorities = get_priorities(); // e.g. ['low','mid','high',...]
        if (!in_array($priority, $allowedPriorities, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid priority']);
            exit;
        }
        $set[] = 'priority = :priority';
        $bind[':priority'] = $priority;
        if ($priority !== (string)($before['priority'] ?? '')) {
            $changedFields[] = 'priority';
        }
    }

    if (empty($set)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Nothing to update']);
        exit;
    }

    // Perform update
    // If your table does not have updated_at, remove that part.
    $sql = 'UPDATE tasks SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($bind);

    // Fetch fresh values for response
    $st2 = $pdo->prepare('SELECT id, title, assigned_to, status, priority, due_date, created_by FROM tasks WHERE id = ?');
    $st2->execute([$id]);
    $row = $st2->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Extremely unlikely after the existence check, but just in case
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to fetch updated task']);
        exit;
    }

    // Build response payload with labels & CSS classes your UI expects
    $statusKey    = (string)($row['status'] ?? '');
    $priorityKey  = (string)($row['priority'] ?? '');
    $assignedTo   = (string)($row['assigned_to'] ?? '');

    $resp = [
        'ok' => true,
        'task' => [
            'id'              => (int)$row['id'],
            'assigned_to'     => $assignedTo,
            'status_key'      => $statusKey,
            'status_label'    => status_label($statusKey),
            'status_class'    => status_class($statusKey),      // e.g. "badge status-in_progress"
            'priority_key'    => $priorityKey,
            'priority_label'  => priority_label($priorityKey),
            'priority_class'  => priority_class($priorityKey),   // e.g. "badge priority-high"
        ],
    ];

    // Activity log (include which fields were updated)
    try {
        log_event('task.quick_update', 'task', $id, ['changed' => $changedFields]);
    } catch (Throwable $e) {
        // non-fatal if logging fails
    }

    if ($changedFields) {
        try {
            task_notify_changes($id, $before, $row, array_values(array_unique($changedFields)), ['source' => 'quick']);
        } catch (Throwable $notifyErr) {
            error_log('task_quick_update notify failed: ' . $notifyErr->getMessage());
        }
    }

    echo json_encode($resp);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}