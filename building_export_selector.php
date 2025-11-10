<?php
require_once __DIR__ . '/helpers.php';
require_login();

$pdo = get_pdo();

// Fetch all buildings with task counts
$buildingsStmt = $pdo->prepare('
    SELECT 
        b.id, 
        b.name,
        COUNT(DISTINCT t.id) as task_count,
        COUNT(DISTINCT t.room_id) as room_count,
        SUM(CASE WHEN t.status = "open" THEN 1 ELSE 0 END) as open_tasks,
        SUM(CASE WHEN t.status = "in_progress" THEN 1 ELSE 0 END) as in_progress_tasks,
        SUM(CASE WHEN t.status = "done" THEN 1 ELSE 0 END) as done_tasks
    FROM buildings b
    LEFT JOIN rooms r ON r.building_id = b.id
    LEFT JOIN tasks t ON t.room_id = r.id
    GROUP BY b.id, b.name
    ORDER BY b.name ASC
');
$buildingsStmt->execute();
$buildings = $buildingsStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Export Building Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 1rem;
            color: #1f2937;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            font-size: 2rem;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            color: #6b7280;
            font-size: 1rem;
        }
        
        .buildings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .building-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }
        
        .building-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
            border-color: #667eea;
        }
        
        .building-card.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, #f0f4ff 0%, #e0e7ff 100%);
        }
        
        .building-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .building-name .icon {
            font-size: 1.5rem;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .stat {
            background: #f9fafb;
            padding: 0.75rem;
            border-radius: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .status-bars {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .status-bar {
            flex: 1;
            height: 4px;
            border-radius: 2px;
            background: #e5e7eb;
        }
        
        .status-bar.open { background: #3b82f6; }
        .status-bar.in-progress { background: #f59e0b; }
        .status-bar.done { background: #10b981; }
        
        .export-panel {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 1.5rem;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
            transform: translateY(100%);
            transition: transform 0.3s ease;
            z-index: 1000;
        }
        
        .export-panel.visible {
            transform: translateY(0);
        }
        
        .export-panel-content {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        
        .export-info {
            flex: 1;
            min-width: 200px;
        }
        
        .export-info strong {
            display: block;
            font-size: 1.125rem;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }
        
        .export-info span {
            color: #6b7280;
            font-size: 0.875rem;
        }
        
        .export-options {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .option-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .option-group label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .option-group select,
        .option-group input {
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            background: white;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: scale(1.05);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #1f2937;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .empty-state {
            background: white;
            border-radius: 1rem;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state h2 {
            font-size: 1.5rem;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: #6b7280;
        }
        
        @media (max-width: 768px) {
            .buildings-grid {
                grid-template-columns: 1fr;
            }
            
            .export-panel-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .export-options {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📄 Export Building Report</h1>
            <p>Select a building to generate a comprehensive PDF report with room-by-room task breakdown and QR codes for field access.</p>
        </div>
        
        <?php if (empty($buildings)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🏢</div>
                <h2>No Buildings Found</h2>
                <p>Create a building first to generate export reports.</p>
            </div>
        <?php else: ?>
            <div class="buildings-grid">
                <?php foreach ($buildings as $building): 
                    $taskCount = (int)($building['task_count'] ?? 0);
                    $roomCount = (int)($building['room_count'] ?? 0);
                    $openTasks = (int)($building['open_tasks'] ?? 0);
                    $inProgressTasks = (int)($building['in_progress_tasks'] ?? 0);
                    $doneTasks = (int)($building['done_tasks'] ?? 0);
                    $totalForBar = $openTasks + $inProgressTasks + $doneTasks;
                ?>
                    <div class="building-card" 
                         data-building-id="<?php echo (int)$building['id']; ?>"
                         data-building-name="<?php echo htmlspecialchars($building['name'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-task-count="<?php echo $taskCount; ?>">
                        <div class="building-name">
                            <span class="icon">🏢</span>
                            <?php echo htmlspecialchars($building['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        
                        <div class="stats">
                            <div class="stat">
                                <div class="stat-label">Rooms</div>
                                <div class="stat-value"><?php echo $roomCount; ?></div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">Tasks</div>
                                <div class="stat-value"><?php echo $taskCount; ?></div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">Open</div>
                                <div class="stat-value" style="color: #3b82f6;"><?php echo $openTasks; ?></div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">Done</div>
                                <div class="stat-value" style="color: #10b981;"><?php echo $doneTasks; ?></div>
                            </div>
                        </div>
                        
                        <?php if ($totalForBar > 0): ?>
                            <div class="status-bars">
                                <?php if ($openTasks > 0): ?>
                                    <div class="status-bar open" style="flex: <?php echo $openTasks; ?>;"></div>
                                <?php endif; ?>
                                <?php if ($inProgressTasks > 0): ?>
                                    <div class="status-bar in-progress" style="flex: <?php echo $inProgressTasks; ?>;"></div>
                                <?php endif; ?>
                                <?php if ($doneTasks > 0): ?>
                                    <div class="status-bar done" style="flex: <?php echo $doneTasks; ?>;"></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="export-panel" id="exportPanel">
        <div class="export-panel-content">
            <div class="export-info">
                <strong id="selectedBuildingName">No building selected</strong>
                <span id="selectedBuildingInfo">Click a building card to select it for export</span>
            </div>
            
            <div class="export-options">
                <div class="option-group">
                    <label for="ttlDays">QR Code Validity</label>
                    <select id="ttlDays" name="ttl">
                        <option value="7">7 days</option>
                        <option value="14">14 days</option>
                        <option value="30" selected>30 days</option>
                        <option value="60">60 days</option>
                        <option value="90">90 days</option>
                        <option value="180">180 days</option>
                        <option value="365">1 year</option>
                    </select>
                </div>
                
                <div class="option-group">
                    <label for="qrSize">QR Code Size</label>
                    <select id="qrSize" name="qr">
                        <option value="120">Small (120px)</option>
                        <option value="150">Medium (150px)</option>
                        <option value="180" selected>Large (180px)</option>
                        <option value="220">X-Large (220px)</option>
                        <option value="280">XXL (280px)</option>
                    </select>
                </div>
                
                <button class="btn btn-secondary" onclick="clearSelection()">Clear</button>
                <button class="btn btn-primary" id="exportBtn" onclick="exportBuilding()" disabled>
                    Generate PDF Report
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let selectedBuildingId = null;
        let selectedBuildingName = '';
        let selectedTaskCount = 0;
        
        // Handle building card clicks
        document.querySelectorAll('.building-card').forEach(card => {
            card.addEventListener('click', function() {
                // Remove previous selection
                document.querySelectorAll('.building-card').forEach(c => c.classList.remove('selected'));
                
                // Select this card
                this.classList.add('selected');
                
                // Store selection data
                selectedBuildingId = parseInt(this.dataset.buildingId);
                selectedBuildingName = this.dataset.buildingName;
                selectedTaskCount = parseInt(this.dataset.taskCount);
                
                // Update panel
                updateExportPanel();
            });
        });
        
        function updateExportPanel() {
            const panel = document.getElementById('exportPanel');
            const nameEl = document.getElementById('selectedBuildingName');
            const infoEl = document.getElementById('selectedBuildingInfo');
            const exportBtn = document.getElementById('exportBtn');
            
            if (selectedBuildingId) {
                panel.classList.add('visible');
                nameEl.textContent = selectedBuildingName;
                infoEl.textContent = `${selectedTaskCount} task${selectedTaskCount === 1 ? '' : 's'} · Ready to export`;
                exportBtn.disabled = false;
            } else {
                panel.classList.remove('visible');
                exportBtn.disabled = true;
            }
        }
        
        function clearSelection() {
            selectedBuildingId = null;
            selectedBuildingName = '';
            selectedTaskCount = 0;
            
            document.querySelectorAll('.building-card').forEach(c => c.classList.remove('selected'));
            document.getElementById('exportPanel').classList.remove('visible');
        }
        
        function exportBuilding() {
            if (!selectedBuildingId) {
                alert('Please select a building first.');
                return;
            }
            
            // Get the selected values from the dropdowns
            const ttl = document.getElementById('ttlDays').value;
            const qr = document.getElementById('qrSize').value;
            
            // Construct export URL with the parameters
            const url = `export_building_pdf.php?building_id=${encodeURIComponent(selectedBuildingId)}&ttl=${encodeURIComponent(ttl)}&qr=${encodeURIComponent(qr)}`;
            
            // Open in new tab
            window.open(url, '_blank');
        }
        
        // Keyboard accessibility
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                clearSelection();
            }
            if (e.key === 'Enter' && selectedBuildingId) {
                exportBuilding();
            }
        });
    </script>
</body>
</html>