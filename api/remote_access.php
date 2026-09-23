<?php
/**
 * Удалённый доступ супер-администратора к запущенным оффлайн-точкам.
 *
 * Точки сами периодически ("heartbeat", раз в ~30с) отчитываются серверу
 * через api/offline_sync.php (авторизация токеном синхронизации) и заодно
 * забирают очередь команд. Этот файл — противоположная, админская сторона:
 * список точек (кто сейчас "онлайн") и постановка команд в очередь.
 * Никакого прямого/входящего соединения к точке нет и не требуется — вся
 * связь инициируется точкой, поэтому это работает даже если у точки нет
 * белого IP / она за NAT.
 *
 * "Онлайн" = heartbeat был не позднее ONLINE_THRESHOLD_SEC назад.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');

checkAuth();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступно только супер-администратору']);
    exit;
}
if (!isAjax()) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Только AJAX']); exit; }

const ONLINE_THRESHOLD_SEC = 90; // heartbeat раз в 30с — 90с даёт запас на пару пропущенных

ensureRemoteAccessTables($pdo);
ensureRemoteScreenTables($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':            doList();           break;
    case 'queue_command':   Csrf::guard(); doQueueCommand();   break;
    case 'command_history': doCommandHistory();  break;
    case 'delete':          Csrf::guard(); doDelete();          break;
    case 'screen_start':    Csrf::guard(); doScreenStart();     break;
    case 'screen_signal':   Csrf::guard(); doScreenSignal();    break;
    case 'screen_poll':     doScreenPoll();     break;
    case 'screen_end':      Csrf::guard(); doScreenEnd();       break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
}

/**
 * Таблицы для удалённого просмотра/управления экраном терминала (WebRTC).
 *
 * remote_screen_sessions — один сеанс просмотра: кто смотрит, какое
 * устройство, статус, срок жизни. remote_screen_signals — служебные
 * сообщения WebRTC (offer/answer/ice) между терминалом и зрителем: прямого
 * входящего соединения к терминалу по-прежнему нет (см. шапку файла), обмен
 * идёт через опрос этих двух таблиц (long polling) — тем же принципом,
 * что и heartbeat.
 */
function ensureRemoteScreenTables(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS remote_screen_sessions (
            session_id  VARCHAR(64) NOT NULL PRIMARY KEY,
            device_id   VARCHAR(64) NOT NULL,
            status      VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_by  VARCHAR(100) DEFAULT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            ended_at    DATETIME DEFAULT NULL,
            INDEX idx_device (device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS remote_screen_signals (
            id          BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_id  VARCHAR(64) NOT NULL,
            sender      VARCHAR(10) NOT NULL,
            type        VARCHAR(20) NOT NULL,
            payload     MEDIUMTEXT NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id, sender, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
}

const SCREEN_SESSION_MAX_AGE_SEC = 7200; // 2 часа — потолок на случай забытой открытой вкладки

/**
 * Запуск сеанса: создаёт запись сеанса, чеканит временные TURN-креды и
 * ставит в очередь команд терминала специальную команду remote_screen с
 * session_id и ICE-серверами в payload — терминал заберёт её при ближайшем
 * heartbeat (раз в ~30с, поэтому подключение стартует не мгновенно).
 */
function doScreenStart(): void
{
    global $pdo;
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceId = trim($data['device_id'] ?? '');
    if ($deviceId === '') { echo json_encode(['success' => false, 'message' => 'Не указано устройство']); return; }

    $exists = $pdo->prepare("SELECT 1 FROM offline_presence WHERE device_id = ?");
    $exists->execute([$deviceId]);
    if (!$exists->fetchColumn()) { echo json_encode(['success' => false, 'message' => 'Точка не найдена']); return; }

    $turn = mintTurnCredentials(SCREEN_SESSION_MAX_AGE_SEC);
    if ($turn === null) {
        echo json_encode(['success' => false, 'message' => 'TURN-сервер не настроен (TURN_SHARED_SECRET) — обратитесь к разработчику']);
        return;
    }

    $sessionId = bin2hex(random_bytes(16));
    $operatorName = $_SESSION['user_name'] ?? 'Супер-администратор';

    $pdo->prepare(
        "INSERT INTO remote_screen_sessions (session_id, device_id, status, created_by) VALUES (?, ?, 'pending', ?)"
    )->execute([$sessionId, $deviceId, $operatorName]);

    $pdo->prepare(
        "INSERT INTO offline_commands (device_id, command, payload, created_by) VALUES (?, 'remote_screen', ?, ?)"
    )->execute([$deviceId, json_encode(['session_id' => $sessionId, 'ice_servers' => $turn['ice_servers']], JSON_UNESCAPED_UNICODE), $operatorName]);

    logAction('remote_screen_start', "Запрошен удалённый экран устройства {$deviceId} (сеанс {$sessionId})");

    echo json_encode([
        'success'     => true,
        'session_id'  => $sessionId,
        'ice_servers' => $turn['ice_servers'],
    ], JSON_UNESCAPED_UNICODE);
}

/** Зритель отправляет свой сигнал (answer / ice) терминалу. */
function doScreenSignal(): void
{
    global $pdo;
    $data      = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = trim($data['session_id'] ?? '');
    $type      = trim($data['type'] ?? '');
    $payload   = $data['payload'] ?? null;

    if ($sessionId === '' || !in_array($type, ['answer', 'ice', 'bye'], true) || $payload === null) {
        echo json_encode(['success' => false, 'message' => 'Некорректные параметры']);
        return;
    }
    if (!screenSessionActive($pdo, $sessionId)) {
        echo json_encode(['success' => false, 'message' => 'Сеанс не найден или завершён']);
        return;
    }

    $pdo->prepare(
        "INSERT INTO remote_screen_signals (session_id, sender, type, payload) VALUES (?, 'viewer', ?, ?)"
    )->execute([$sessionId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE)]);

    if ($type === 'answer') {
        $pdo->prepare("UPDATE remote_screen_sessions SET status = 'active' WHERE session_id = ?")->execute([$sessionId]);
    }

    echo json_encode(['success' => true]);
}

/** Зритель забирает новые сигналы от терминала (offer / ice). */
function doScreenPoll(): void
{
    global $pdo;
    $sessionId = trim($_GET['session_id'] ?? '');
    $after     = (int)($_GET['after'] ?? 0);
    if ($sessionId === '') { echo json_encode(['success' => false, 'message' => 'session_id required']); return; }

    $status = $pdo->prepare("SELECT status FROM remote_screen_sessions WHERE session_id = ?");
    $status->execute([$sessionId]);
    $sessionStatus = $status->fetchColumn();
    if ($sessionStatus === false) { echo json_encode(['success' => false, 'message' => 'Сеанс не найден']); return; }

    $stmt = $pdo->prepare(
        "SELECT id, type, payload FROM remote_screen_signals
         WHERE session_id = ? AND sender = 'device' AND id > ?
         ORDER BY id ASC LIMIT 100"
    );
    $stmt->execute([$sessionId, $after]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['payload'] = json_decode($r['payload'], true); }
    unset($r);

    echo json_encode(['success' => true, 'status' => $sessionStatus, 'signals' => $rows], JSON_UNESCAPED_UNICODE);
}

function doScreenEnd(): void
{
    global $pdo;
    $data      = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = trim($data['session_id'] ?? '');
    if ($sessionId === '') { echo json_encode(['success' => false, 'message' => 'session_id required']); return; }

    $pdo->prepare("UPDATE remote_screen_sessions SET status = 'ended', ended_at = UTC_TIMESTAMP() WHERE session_id = ?")
        ->execute([$sessionId]);
    $pdo->prepare(
        "INSERT INTO remote_screen_signals (session_id, sender, type, payload) VALUES (?, 'viewer', 'bye', '{}')"
    )->execute([$sessionId]);

    echo json_encode(['success' => true]);
}

function screenSessionActive(PDO $pdo, string $sessionId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM remote_screen_sessions
         WHERE session_id = ? AND status != 'ended'
           AND created_at > UTC_TIMESTAMP() - INTERVAL " . SCREEN_SESSION_MAX_AGE_SEC . " SECOND"
    );
    $stmt->execute([$sessionId]);
    return (bool)$stmt->fetchColumn();
}

function ensureRemoteAccessTables(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS offline_presence (
            device_id     VARCHAR(64) NOT NULL PRIMARY KEY,
            point_id      INT DEFAULT NULL,
            point_name    VARCHAR(255) DEFAULT NULL,
            employee_name VARCHAR(255) DEFAULT NULL,
            app_version   VARCHAR(20) DEFAULT NULL,
            ip_address    VARCHAR(64) DEFAULT NULL,
            last_seen_at  DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS offline_commands (
            id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id   VARCHAR(64) NOT NULL,
            command     VARCHAR(30) NOT NULL,
            payload     TEXT DEFAULT NULL,
            status      VARCHAR(20) NOT NULL DEFAULT 'pending',
            result      TEXT DEFAULT NULL,
            created_by  VARCHAR(100) DEFAULT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            executed_at DATETIME DEFAULT NULL,
            INDEX idx_device_status (device_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
}

function doList(): void
{
    global $pdo;
    $rows = $pdo->query(
        "SELECT device_id, point_id, point_name, employee_name, app_version, ip_address, last_seen_at,
                TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) AS seconds_ago
         FROM offline_presence
         ORDER BY last_seen_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['point_id']     = $r['point_id'] !== null ? (int)$r['point_id'] : null;
        $r['seconds_ago']  = $r['seconds_ago'] !== null ? (int)$r['seconds_ago'] : null;
        $r['online']       = $r['seconds_ago'] !== null && $r['seconds_ago'] <= ONLINE_THRESHOLD_SEC;
    }
    unset($r);

    echo json_encode(['success' => true, 'points' => $rows], JSON_UNESCAPED_UNICODE);
}

function doQueueCommand(): void
{
    global $pdo;
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceId = trim($data['device_id'] ?? '');
    $command  = $data['command'] ?? '';
    $payload  = $data['payload'] ?? null;

    $validCommands = ['sync', 'check_update', 'install_update', 'unlock_kiosk', 'restart', 'install_tailscale'];
    if ($deviceId === '' || !in_array($command, $validCommands, true)) {
        echo json_encode(['success' => false, 'message' => 'Некорректные параметры']);
        return;
    }
    if ($command === 'install_tailscale' && empty($payload['auth_key'])) {
        echo json_encode(['success' => false, 'message' => 'Не указан Tailscale auth key']);
        return;
    }

    $exists = $pdo->prepare("SELECT 1 FROM offline_presence WHERE device_id = ?");
    $exists->execute([$deviceId]);
    if (!$exists->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Точка не найдена']);
        return;
    }

    $operatorName = $_SESSION['user_name'] ?? 'Супер-администратор';
    $pdo->prepare(
        "INSERT INTO offline_commands (device_id, command, payload, created_by) VALUES (?, ?, ?, ?)"
    )->execute([$deviceId, $command, $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, $operatorName]);

    logAction('remote_command', "Команда «{$command}» поставлена для устройства {$deviceId}");

    echo json_encode(['success' => true]);
}

/**
 * Убирает из списка неиспользуемую точку: переустановленный компьютер,
 * заменённое железо, разовый запуск на чужой машине. Каждая установка
 * получает свой device_id и остаётся в списке навсегда, поэтому без чистки
 * он со временем зарастает мёртвыми строками.
 *
 * Удаляем и очередь команд этого устройства — иначе она осталась бы висеть
 * без владельца.
 *
 * Точку, которая сейчас на связи, удалить нельзя: она пришлёт heartbeat в
 * ближайшие полминуты и появится снова. Молча «удалить» такую строку значило
 * бы обмануть администратора.
 */
function doDelete(): void
{
    global $pdo;
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceId = trim($data['device_id'] ?? '');
    if ($deviceId === '') {
        echo json_encode(['success' => false, 'message' => 'Не указано устройство']);
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT point_name, TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) AS seconds_ago
         FROM offline_presence WHERE device_id = ?"
    );
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Точка не найдена']);
        return;
    }
    if ($row['seconds_ago'] !== null && (int)$row['seconds_ago'] <= ONLINE_THRESHOLD_SEC) {
        echo json_encode([
            'success' => false,
            'message' => 'Точка сейчас на связи — она вернётся в список при следующем сеансе. Удалять имеет смысл только те, что больше не используются.',
        ]);
        return;
    }

    $pdo->prepare("DELETE FROM offline_commands WHERE device_id = ?")->execute([$deviceId]);
    $pdo->prepare("DELETE FROM offline_presence WHERE device_id = ?")->execute([$deviceId]);

    $name = $row['point_name'] !== null && $row['point_name'] !== '' ? $row['point_name'] : $deviceId;
    logAction('remote_device_delete', "Удалена неиспользуемая точка: {$name} ({$deviceId})");

    echo json_encode(['success' => true]);
}

function doCommandHistory(): void
{
    global $pdo;
    $deviceId = trim($_GET['device_id'] ?? '');
    if ($deviceId === '') { echo json_encode(['success' => false, 'message' => 'device_id required']); return; }

    $stmt = $pdo->prepare(
        "SELECT id, command, status, result, created_by, created_at, executed_at
         FROM offline_commands
         WHERE device_id = ?
         ORDER BY created_at DESC LIMIT 20"
    );
    $stmt->execute([$deviceId]);
    echo json_encode(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
}
