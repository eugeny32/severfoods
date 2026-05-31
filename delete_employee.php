<?php
require_once 'config.php';
require_once 'functions.php';
require_login();

$isAjax = isset($_GET['ajax']) || isset($_POST['ajax']);

/** Унифицированный JSON-ответ для AJAX-запросов (JS читает d.success / d.message) */
function respond(bool $ok, string $msg = '', array $extra = []): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: index.php');
    exit;
}

// Только супер-админ может удалять
if (($_SESSION['role'] ?? null) !== 'super_admin') {
    respond(false, 'Недостаточно прав (только супер-администратор)');
}

// Проверка CSRF (не ломаем ответ редиректом — отдаём JSON)
if (class_exists('Csrf') && method_exists('Csrf', 'check')) {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!Csrf::check($token)) {
        respond(false, 'Недействительный CSRF-токен');
    }
}

$id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) {
    respond(false, 'Не указан идентификатор сотрудника');
}

// Запрет удалять собственную учётную запись
if ($id === (int)($_SESSION['user_id'] ?? 0)) {
    respond(false, 'Нельзя удалить собственную учётную запись');
}

try {
    $pdo->beginTransaction();

    // Чистим связанные данные чата, чтобы не оставлять «сирот»
    // (таблицы могут отсутствовать — каждую оборачиваем отдельно)
    $cleanups = [
        "DELETE FROM chat_room_members WHERE user_id = ?",
        "DELETE FROM chat_messages     WHERE sender_id = ?",
        "DELETE FROM chat_read         WHERE user_id = ?",
        "DELETE FROM chat_presence     WHERE user_id = ?",
        "DELETE FROM chat_signals      WHERE from_id = ? OR to_id = ?",
    ];
    foreach ($cleanups as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(substr_count($sql, '?') === 2 ? [$id, $id] : [$id]);
        } catch (PDOException $e) {
            // Таблицы/колонки может не быть — пропускаем
        }
    }

    // Удаляем самого сотрудника
    $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $deleted = $stmt->rowCount();

    $pdo->commit();

    if ($deleted < 1) {
        respond(false, 'Сотрудник не найден или уже удалён');
    }
    respond(true, '', ['deleted' => $deleted]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(false, 'Ошибка базы данных: ' . $e->getMessage());
}
