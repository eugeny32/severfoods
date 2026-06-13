<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dry_rations (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        ration_date DATE NOT NULL,
        ration_type VARCHAR(20) NOT NULL DEFAULT 'dry_ration',
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_emp_date (employee_id, ration_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$method = $_SERVER['REQUEST_METHOD'];

// GET — list for employee in date range
if ($method === 'GET') {
    $empId = (int)($_GET['employee_id'] ?? 0);
    $from  = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to    = $_GET['to']   ?? date('Y-m-d');
    if (!$empId) { echo json_encode(['ok'=>false,'error'=>'No employee_id']); exit; }

    $stmt = $pdo->prepare("SELECT id, ration_date, ration_type FROM dry_rations WHERE employee_id=? AND ration_date BETWEEN ? AND ? ORDER BY ration_date");
    $stmt->execute([$empId, $from, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'items'=>$rows, 'count'=>count($rows)]);
    exit;
}

// POST — add entry
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $empId = (int)($data['employee_id'] ?? 0);
    $date  = preg_replace('/[^0-9\-]/', '', $data['ration_date'] ?? date('Y-m-d'));
    $type  = in_array($data['ration_type'] ?? '', ['dry_ration','field']) ? $data['ration_type'] : 'dry_ration';
    $from  = $data['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to    = $data['to']   ?? date('Y-m-d');

    if (!$empId || !$date) { echo json_encode(['ok'=>false,'error'=>'Нет данных']); exit; }

    // Check 5-day limit in selected period
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM dry_rations WHERE employee_id=? AND ration_date BETWEEN ? AND ?");
    $cntStmt->execute([$empId, $from, $to]);
    $cnt = (int)$cntStmt->fetchColumn();
    if ($cnt >= 5) {
        echo json_encode(['ok'=>false,'error'=>'Превышен лимит 5 дней за период']); exit;
    }

    try {
        $pdo->prepare("INSERT INTO dry_rations (employee_id, ration_date, ration_type, created_by) VALUES (?,?,?,?)")
            ->execute([$empId, $date, $type, $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['ok'=>true, 'id'=>$newId]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Запись на эту дату уже существует']);
    }
    exit;
}

// DELETE — remove entry
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'No id']); exit; }
    $pdo->prepare("DELETE FROM dry_rations WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
