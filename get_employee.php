<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
}
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$emp = getEmployeeById($pdo, $id);
if (!$emp) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }

// Обычный админ предприятия видит карточки только своей организации, и
// НИКОГДА — карточки с ролью super_admin (защита от компрометации их QR),
// та же граница, что и в add_employee.php/update_employee.php/print_qr.php.
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    if (($emp['role'] ?? '') === 'super_admin') {
        http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
    }
    $me = getEmployeeById($pdo, (int)($_SESSION['user_id'] ?? 0));
    $myOrg = trim($me['organization'] ?? '');
    if ($myOrg === '' || trim($emp['organization'] ?? '') !== $myOrg) {
        http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
    }
}

echo json_encode($emp, JSON_UNESCAPED_UNICODE);
