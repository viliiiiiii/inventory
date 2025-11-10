<?php
require_once __DIR__ . '/helpers.php';
require_login();

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// ======== Fetch data ========
$selectedIds = [];
if (!empty($_REQUEST['selected'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', (string)$_REQUEST['selected'])));
}

if ($selectedIds) {
    $tasks = fetch_tasks_by_ids($selectedIds);
} else {
    $filters = get_filter_values();
    $tasks = export_tasks($filters);
}

// ======== Build spreadsheet ========
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Tasks');

// Header row
$headers = [
    'ID', 'Building', 'Room', 'Title', 'Description',
    'Priority', 'Status', 'Assigned To',
    'Due Date', 'Created At', 'Updated At'
];
$sheet->fromArray($headers, null, 'A1');

// Header style
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2563EB']], // blue
];
$sheet->getStyle('A1:K1')->applyFromArray($headerStyle);

// Data rows
$row = 2;
foreach ($tasks as $task) {
    $sheet->fromArray([
        $task['id'],
        $task['building_name'],
        $task['room_number'] . ($task['room_label'] ? ' - ' . $task['room_label'] : ''),
        $task['title'],
        $task['description'],
        $task['priority'],
        $task['status'],
        $task['assigned_to'],
        $task['due_date'],
        $task['created_at'],
        $task['updated_at'],
    ], null, 'A' . $row);
    $row++;
}

// Borders for all cells
$sheet->getStyle('A1:K' . ($row - 1))->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCCCCC']
        ]
    ]
]);

// Auto-size columns
foreach (range('A', 'K') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ======== Output to browser ========
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="tasks.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
