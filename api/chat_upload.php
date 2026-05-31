<?php
/**
 * =====================================================
 *  CHAT FILE UPLOAD
 *  POST multipart/form-data
 *  Fields: file (required), room_id (required)
 *
 *  Разрешённые типы: изображения, видео, аудио, документы
 *  Макс. размер: 50 МБ
 *  Файлы хранятся в uploads/chat/ (прямой доступ закрыт .htaccess)
 *  Отдаются через api/chat.php?action=file&id=X
 * =====================================================
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Auth ─────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

Csrf::guard();

$uid    = (int)$_SESSION['user_id'];
$uname  = $_SESSION['user_name'] ?? 'Admin';
$roomId = (int)($_POST['room_id'] ?? 0);

if (!$roomId) {
    http_response_code(400);
    echo json_encode(['error' => 'room_id required']);
    exit;
}

// ─── Проверка членства ────────────────────────────────
// (инициализируем PDO через bootstrap, уже подключён через config.php)
$mStmt = $pdo->prepare("SELECT 1 FROM chat_room_members WHERE room_id=? AND user_id=?");
$mStmt->execute([$roomId, $uid]);
if (!$mStmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a member']);
    exit;
}

// ─── Файл ─────────────────────────────────────────────
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? -1;
    $errMsg  = match($errCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой',
        UPLOAD_ERR_NO_FILE  => 'Файл не выбран',
        default             => 'Ошибка загрузки файла (код ' . $errCode . ')',
    };
    http_response_code(400);
    echo json_encode(['error' => $errMsg]);
    exit;
}

$file     = $_FILES['file'];
$origName = basename($file['name']);
$tmpPath  = $file['tmp_name'];
$fileSize = $file['size'];

// ─── Лимит 50 МБ ──────────────────────────────────────
if ($fileSize > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Максимальный размер файла — 50 МБ']);
    exit;
}

// ─── MIME по содержимому файла (не по заголовку!) ─────
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeReal = $finfo->file($tmpPath);

$allowed = [
    // Изображения
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    // Видео
    'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
    // Аудио
    'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/mp4',
    // Документы
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    // Архивы
    'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
    // Текстовые
    'text/plain', 'text/csv',
    // Прочие бинарники — разрешаем с предосторожностями
    'application/octet-stream',
];

if (!in_array($mimeReal, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => "Тип файла не разрешён: {$mimeReal}"]);
    exit;
}

// ─── Директория хранения ──────────────────────────────
$uploadDir = dirname(__DIR__) . '/uploads/chat/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0750, true);
    // Защита .htaccess
    file_put_contents($uploadDir . '.htaccess', "Order deny,allow\nDeny from all\n");
}

// ─── Генерация уникального имени ─────────────────────
$ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$safeExt    = preg_replace('/[^a-z0-9]/', '', $ext);
$storedName = bin2hex(random_bytes(16)) . ($safeExt ? '.' . $safeExt : '');
$destPath   = $uploadDir . $storedName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось сохранить файл']);
    exit;
}

// ─── Размеры для изображений ──────────────────────────
$imgW = null;
$imgH = null;
if (strncmp($mimeReal, 'image/', 6) === 0 && $mimeReal !== 'image/svg+xml') {
    $sz = @getimagesize($destPath);
    if ($sz) { $imgW = $sz[0]; $imgH = $sz[1]; }
}

// ─── Запись в БД ──────────────────────────────────────
$pdo->prepare(
    "INSERT INTO chat_files (room_id, sender_id, orig_name, stored_name, mime_type, file_size, img_w, img_h)
     VALUES (?,?,?,?,?,?,?,?)"
)->execute([$roomId, $uid, $origName, $storedName, $mimeReal, $fileSize, $imgW, $imgH]);

$fileId = (int)$pdo->lastInsertId();

echo json_encode([
    'ok'        => true,
    'file_id'   => $fileId,
    'orig_name' => $origName,
    'mime_type' => $mimeReal,
    'file_size' => $fileSize,
    'img_w'     => $imgW,
    'img_h'     => $imgH,
]);
