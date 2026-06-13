<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); die(json_encode(['error'=>'Unauthorized'])); }

$id   = (int)($_GET['id'] ?? 0);
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to   = $_GET['to']   ?? date('Y-m-d');

if (!$id) { echo json_encode(['error'=>'No id']); exit; }

$emp = getEmployeeById($pdo, $id);
if (!$emp) { echo json_encode(['error'=>'Not found']); exit; }

$stmt = $pdo->prepare("
    SELECT meal_type, COUNT(*) as cnt
    FROM meal_logs
    WHERE employee_id = ? AND DATE(scanned_at) BETWEEN ? AND ?
    GROUP BY meal_type
    ORDER BY MIN(scanned_at)
");
$stmt->execute([$id, $from, $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0;
$byType = [];
foreach ($rows as $r) {
    $byType[$r['meal_type']] = (int)$r['cnt'];
    $total += (int)$r['cnt'];
}

// Count unique days served (any number of meals in a day = 1 day)
$stmtDays = $pdo->prepare("
    SELECT COUNT(DISTINCT DATE(scanned_at)) as days
    FROM meal_logs
    WHERE employee_id = ? AND DATE(scanned_at) BETWEEN ? AND ?
");
$stmtDays->execute([$id, $from, $to]);
$days = (int)$stmtDays->fetchColumn();

// Dry rations count in period
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
    $stmtRat = $pdo->prepare("SELECT COUNT(*) FROM dry_rations WHERE employee_id=? AND ration_date BETWEEN ? AND ?");
    $stmtRat->execute([$id, $from, $to]);
    $rationDays = (int)$stmtRat->fetchColumn();
} catch (PDOException $e) {
    $rationDays = 0;
}

header('Content-Type: application/json');
echo json_encode([
    'ok'          => true,
    'name'        => $emp['full_name'],
    'total'       => $total,
    'days'        => $days,
    'by_type'     => $byType,
    'ration_days' => $rationDays,
]);
