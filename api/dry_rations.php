<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

// Ensure table exists with status column
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dry_rations (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        ration_date DATE NOT NULL,
        ration_type VARCHAR(20) NOT NULL DEFAULT 'field',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        cancelled_at DATETIME DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_emp_date (employee_id, ration_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}
// Add status column if upgrading from older schema
try { $pdo->exec("ALTER TABLE dry_rations ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE dry_rations ADD COLUMN cancelled_at DATETIME DEFAULT NULL"); } catch (PDOException $e) {}

$method = $_SERVER['REQUEST_METHOD'];

// GET — list for employee in date range (all statuses)
if ($method === 'GET') {
    $empId = (int)($_GET['employee_id'] ?? 0);
    $from  = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to    = $_GET['to']   ?? date('Y-m-d', strtotime('+90 days'));
    if (!$empId) { echo json_encode(['ok'=>false,'error'=>'No employee_id']); exit; }

    $stmt = $pdo->prepare("SELECT id, ration_date, ration_type, status FROM dry_rations WHERE employee_id=? AND ration_date BETWEEN ? AND ? ORDER BY ration_date");
    $stmt->execute([$empId, $from, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activeCount = count(array_filter($rows, fn($r) => $r['status'] === 'active'));
    echo json_encode(['ok'=>true, 'items'=>$rows, 'count'=>$activeCount, 'total'=>count($rows)]);
    exit;
}

// POST — add entries for a date range
if ($method === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $empId    = (int)($data['employee_id'] ?? 0);
    $dateFrom = preg_replace('/[^0-9\-]/', '', $data['ration_date_from'] ?? ($data['ration_date'] ?? ''));
    $dateTo   = preg_replace('/[^0-9\-]/', '', $data['ration_date_to']   ?? $dateFrom);
    $type     = ($data['ration_type'] ?? 'field') === 'dry_ration' ? 'dry_ration' : 'field';
    $statsFrom = $data['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $statsTo   = $data['to']   ?? date('Y-m-d', strtotime('+30 days'));

    if (!$empId || !$dateFrom) { echo json_encode(['ok'=>false,'error'=>'Нет данных']); exit; }
    if ($dateTo < $dateFrom)   { echo json_encode(['ok'=>false,'error'=>'Дата «по» раньше «с»']); exit; }

    $dates = [];
    $cur = new DateTime($dateFrom);
    $end = new DateTime($dateTo);
    while ($cur <= $end) { $dates[] = $cur->format('Y-m-d'); $cur->modify('+1 day'); }
    if (count($dates) > 30) { echo json_encode(['ok'=>false,'error'=>'Диапазон не может превышать 30 дней']); exit; }

    // Count active records in stats period
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM dry_rations WHERE employee_id=? AND ration_date BETWEEN ? AND ? AND status='active'");
    $cntStmt->execute([$empId, $statsFrom, $statsTo]);
    $existing = (int)$cntStmt->fetchColumn();
    $newInPeriod = count(array_filter($dates, fn($d) => $d >= $statsFrom && $d <= $statsTo));

    if ($existing + $newInPeriod > 4) {
        echo json_encode(['ok'=>false,'error'=>'Превышен лимит 4 дней за период (уже: '.$existing.', добавляется: '.$newInPeriod.')']); exit;
    }

    $inserted = 0; $skipped = 0;
    $stmt = $pdo->prepare("INSERT IGNORE INTO dry_rations (employee_id, ration_date, ration_type, status, created_by) VALUES (?,?,?,'active',?)");
    foreach ($dates as $d) {
        $stmt->execute([$empId, $d, $type, $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) $inserted++; else $skipped++;
    }
    echo json_encode(['ok'=>true, 'inserted'=>$inserted, 'skipped'=>$skipped]);
    exit;
}

// DELETE — physically delete only active future records; past/cancelled cannot be deleted
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'No id']); exit; }
    // Only allow deletion of active records
    $pdo->prepare("DELETE FROM dry_rations WHERE id=? AND status='active'")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
