<?php
require_once 'config.php';
require_once 'functions.php';
checkAdmin();

Csrf::guard();

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, string $msg = '', array $extra = []): void
{
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// Только супер-админ может удалять
if (($_SESSION['role'] ?? null) !== 'super_admin') {
    respond(false, 'Недостаточно прав (только супер-администратор)');
}

$data = json_decode(file_get_contents('php://input'), true);
$id = intval($data['id'] ?? 0);
if ($id <= 0) {
    respond(false, 'Не указан идентификатор сотрудника');
}

// Запрет удалять собственную учётную запись
if ($id === (int)($_SESSION['user_id'] ?? 0)) {
    respond(false, 'Нельзя удалить собственную учётную запись');
}

// Если у сотрудника уже есть статистика питания (реальные приёмы пищи,
// сухой паёк/выездное) — не удаляем: физическое удаление сотрудника
// потянуло бы за собой всю историю его записей (или упёрлось бы в
// внешний ключ и просто падало с ошибкой БД, из-за чего кнопка
// выглядела "не работающей"). Вместо удаления предлагаем деактивировать
// сотрудника (чекбокс "Активен" в редактировании) — история сохранится,
// а сотрудник перестанет отображаться как действующий.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM meal_logs WHERE employee_id = ?");
$stmt->execute([$id]);
$mealCount = (int)$stmt->fetchColumn();

$rationCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dry_rations WHERE employee_id = ?");
    $stmt->execute([$id]);
    $rationCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // таблицы может не быть — не считаем это блокирующей причиной
}

if ($mealCount > 0 || $rationCount > 0) {
    respond(false, 'У сотрудника есть статистика питания (' .
        ($mealCount > 0 ? "приёмов пищи: {$mealCount}" : '') .
        ($mealCount > 0 && $rationCount > 0 ? ', ' : '') .
        ($rationCount > 0 ? "сухпай/выездное: {$rationCount}" : '') .
        ') — удаление запрещено, чтобы не потерять историю. Деактивируйте сотрудника (снимите галку «Активен» в редактировании) вместо удаления.');
}

try {
    $pdo->beginTransaction();

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
            // таблица может отсутствовать
        }
    }

    $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $deleted = $stmt->rowCount();

    $pdo->commit();

    if ($deleted < 1) {
        respond(false, 'Сотрудник не найден или уже удалён');
    }
    respond(true, 'Сотрудник удалён', ['deleted' => $deleted]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(false, 'Ошибка базы данных: ' . $e->getMessage());
}
