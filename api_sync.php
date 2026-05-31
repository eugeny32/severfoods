<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
// Синхронизация для офлайн-устройств (сканеры)
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? $_GET['token'] ?? '';

// Простая API-аутентификация по токену (можно расширить)
// Для быстрого старта принимаем QR-код сотрудника как токен
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token required']);
    exit;
}

// Проверяем токен = qr_code пользователя с ролью operator/admin
$stmt = $pdo->prepare("SELECT * FROM employees WHERE qr_code=? AND is_active=1 AND role IN ('operator','admin','super_admin')");
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'employees';

if ($action === 'employees') {
    // Отдаём список сотрудников для офлайн-кэша
    $employees = $pdo->query(
        "SELECT id, full_name, organization, qr_code, qr_status, qr_expires_at, is_active, price, vjg_type
         FROM employees WHERE is_active=1 ORDER BY full_name"
    )->fetchAll();
    echo json_encode(['success'=>true,'data'=>$employees,'timestamp'=>time()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'ping') {
    echo json_encode(['success'=>true,'server_time'=>date('Y-m-d H:i:s'),'user'=>$user['full_name']]);
    exit;
}

if ($action === 'scan' && $_SERVER['REQUEST_METHOD']==='POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $qr   = $body['qr_code'] ?? '';
    if (!$qr) { echo json_encode(['success'=>false,'message'=>'QR not provided']); exit; }

    // Устанавливаем сессию для processAccess
    $_SESSION['user_id']        = $user['id'];
    $_SESSION['user_name']      = $user['full_name'];
    $_SESSION['meal_point_id']  = $body['meal_point_id'] ?? null;
    $_SESSION['meal_point_name']= $body['meal_point_name'] ?? null;

    $result = processAccess($pdo, $qr, $_SERVER['REMOTE_ADDR'] ?? null);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error'=>'Unknown action']);
