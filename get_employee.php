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
echo json_encode($emp, JSON_UNESCAPED_UNICODE);
