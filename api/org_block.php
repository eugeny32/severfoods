<?php
/**
 * Блокировка организации.
 *
 *   GET                                       — список заблокированных (любой администратор)
 *   POST {organization, action, reason?}      — action = block | unblock (только супер-администратор)
 *
 * Организация, помеченная заблокированной, не получает питание: ни по скану, ни
 * по ручному пропуску, ни по массовой проводке, ни сухим пайком/выездным питанием,
 * ни на терминалах (см. organizationBlock() в src/functions.php). Индивидуальные
 * блокировки QR сотрудников при этом не затрагиваются — снятие блокировки организации
 * возвращает каждого в то состояние, в котором он был.
 *
 * Блокировка действует и на тех, кого добавят в организацию позже.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json; charset=utf-8');

checkAuth();
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступно только администраторам'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isSuper = ($_SESSION['role'] ?? '') === 'super_admin';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'blocked' => blockedOrganizationsList($pdo), 'can_manage' => $isSuper],
        JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isAjax()) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Только AJAX']); exit; }
Csrf::guard();

// Массовое действие, затрагивающее сразу многих людей, — только высшая роль.
if (!$isSuper) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Блокировать и разблокировать организации может только супер-администратор'],
        JSON_UNESCAPED_UNICODE);
    exit;
}

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';
$org    = trim((string)($data['organization'] ?? ''));
$reason = trim((string)($data['reason'] ?? ''));
if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

if (!in_array($action, ['block', 'unblock'], true)) {
    echo json_encode(['success' => false, 'message' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($org === '' || mb_strlen($org) > 190) {
    echo json_encode(['success' => false, 'message' => 'Не указана организация'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    ensureBlockedOrganizationsTable($pdo);

    // Для информации в журнале и ответе: сколько сотрудников затронуто. Сравнение — тем
    // же способом, что и во всех фильтрах по организациям (TRIM средствами SQL).
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE TRIM(organization) = ?");
    $cntStmt->execute([$org]);
    $affected = (int)$cntStmt->fetchColumn();

    if ($action === 'block') {
        // Организация должна существовать: опечатка иначе создала бы «фантомную» блокировку,
        // которая ничего не блокирует, но выглядит как действующая.
        if ($affected === 0) {
            echo json_encode(['success' => false, 'message' => 'Организация не найдена'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO blocked_organizations (organization, reason, blocked_by) VALUES (?, ?, ?)"
        );
        $ins->execute([$org, $reason !== '' ? $reason : null, $_SESSION['user_name'] ?? null]);
        $changed = $ins->rowCount() > 0;
        if ($changed) {
            logAction('org_block', "Заблокирована организация «{$org}» (сотрудников: {$affected})"
                . ($reason !== '' ? ". Причина: {$reason}" : ''));
        }
    } else {
        $del = $pdo->prepare("DELETE FROM blocked_organizations WHERE organization = ?");
        $del->execute([$org]);
        $changed = $del->rowCount() > 0;
        if ($changed) {
            logAction('org_unblock', "Разблокирована организация «{$org}» (сотрудников: {$affected})");
        }
    }
} catch (PDOException $e) {
    error_log('org_block: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Не удалось выполнить операцию, подробности в журнале сервера'],
        JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success'  => true,
    'changed'  => $changed,
    'affected' => $affected,
    'blocked'  => blockedOrganizationsList($pdo),
], JSON_UNESCAPED_UNICODE);
