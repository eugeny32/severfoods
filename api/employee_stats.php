<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); die(json_encode(['error'=>'Unauthorized'])); }

$id   = (int)($_GET['id'] ?? 0);
$from = $_GET['from'] ?? date('Y-m-d', strtotime(localToday() . ' -30 days'));
$to   = $_GET['to']   ?? localToday();

if (!$id) { echo json_encode(['error'=>'No id']); exit; }

$emp = getEmployeeById($pdo, $id);
if (!$emp) { echo json_encode(['error'=>'Not found']); exit; }

$scannedLocal = tzExpr('scanned_at');
$stmt = $pdo->prepare("
    SELECT meal_type, COUNT(*) as cnt
    FROM meal_logs
    WHERE employee_id = ? AND DATE($scannedLocal) BETWEEN ? AND ?
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
    SELECT COUNT(DISTINCT DATE($scannedLocal)) as days
    FROM meal_logs
    WHERE employee_id = ? AND DATE($scannedLocal) BETWEEN ? AND ?
");
$stmtDays->execute([$id, $from, $to]);
$days = (int)$stmtDays->fetchColumn();

// Dry rations / field catering in period
$rationDays = 0;
$rationItems = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dry_rations (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        ration_date DATE NOT NULL,
        ration_type VARCHAR(20) NOT NULL DEFAULT 'field',
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_emp_date (employee_id, ration_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add status column if missing (schema migration)
    try { $pdo->exec("ALTER TABLE dry_rations ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE dry_rations ADD COLUMN cancelled_at DATETIME DEFAULT NULL"); } catch (PDOException $e) {}
    $stmtRat = $pdo->prepare("SELECT ration_date, ration_type, status FROM dry_rations WHERE employee_id=? AND ration_date BETWEEN ? AND ? ORDER BY ration_date");
    $stmtRat->execute([$id, $from, $to]);
    $rationItems = $stmtRat->fetchAll(PDO::FETCH_ASSOC);
    // Only active (not cancelled) count toward days
    $rationDays = count(array_filter($rationItems, fn($r) => $r['status'] === 'active'));
} catch (PDOException $e) {}

// Count dry_ration type and field type separately
$dryRationCount = count(array_filter($rationItems, fn($r) => $r['status'] === 'active' && $r['ration_type'] === 'dry_ration'));
$fieldCount     = count(array_filter($rationItems, fn($r) => $r['status'] === 'active' && $r['ration_type'] === 'field'));

header('Content-Type: application/json');
echo json_encode([
    'ok'               => true,
    'name'             => $emp['full_name'],
    'total'            => $total,
    'days'             => $days,         // cafeteria days only
    'by_type'          => $byType,
    'dry_ration_count' => $dryRationCount,
    'field_count'      => $fieldCount,
]);
