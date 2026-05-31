<?php
/**
 * =====================================================
 *  MESSENGER API v2
 *  Единая точка входа для всего чата.
 *  Доступ: только admin / super_admin.
 *
 *  GET  ?action=ping          — heartbeat + список онлайн
 *  GET  ?action=rooms         — список комнат пользователя
 *  POST ?action=create_room   — создать группу/канал/direct
 *  POST ?action=add_member    — добавить участника
 *  POST ?action=leave_room    — покинуть комнату
 *  GET  ?action=room_members  — список участников комнаты
 *  GET  ?action=messages      — история сообщений
 *  POST ?action=send          — отправить сообщение
 *  POST ?action=delete_msg    — удалить своё сообщение
 *  POST ?action=mark_read     — отметить прочитанным
 *  GET  ?action=file          — скачать/отдать файл
 *  GET  ?action=poll          — входящие сигналы WebRTC
 *  POST ?action=signal        — WebRTC сигнал
 *  GET  ?action=online        — список онлайн-пользователей
 * =====================================================
 */

// Буферизируем весь вывод — PHP-ошибки не должны ломать JSON
if (ob_get_level() === 0) ob_start();

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Глобальный перехватчик: любое необработанное исключение → JSON
set_exception_handler(function (Throwable $e): void {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'error'   => 'Внутренняя ошибка сервера',
        'details' => $e->getMessage(),   // можно убрать в продакшне
    ]);
});

// PHP-предупреждения/ошибки → JSON вместо HTML
set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => "PHP: $msg ($file:$line)"]);
    exit;
});

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$uid   = (int)$_SESSION['user_id'];
$uname = $_SESSION['user_name'] ?? 'Admin';
$urole = $_SESSION['role']      ?? 'admin';
$action = $_GET['action'] ?? '';

initTables();

// ─── Роутер ──────────────────────────────────────────
switch ($action) {
    case 'ping':         doPing();        break;
    case 'online':       doOnline();      break;
    case 'rooms':        doRooms();       break;
    case 'create_room':  doCreateRoom();  break;
    case 'add_member':   doAddMember();   break;
    case 'leave_room':   doLeaveRoom();   break;
    case 'room_members': doRoomMembers(); break;
    case 'messages':     doMessages();    break;
    case 'send':         doSend();        break;
    case 'delete_msg':   doDeleteMsg();   break;
    case 'mark_read':    doMarkRead();    break;
    case 'file':         doFile();        break;
    case 'poll':         doPoll();        break;
    case 'signal':       doSignal();      break;
    default:
        echo json_encode(['error' => 'Unknown action']);
}

// ═════════════════════════════════════════════════════
//  HELPERS
// ═════════════════════════════════════════════════════

function isMember(int $roomId): bool
{
    global $pdo, $uid;
    $s = $pdo->prepare("SELECT 1 FROM chat_room_members WHERE room_id=? AND user_id=?");
    $s->execute([$roomId, $uid]);
    return (bool)$s->fetchColumn();
}

function getRoomRole(int $roomId): string
{
    global $pdo, $uid;
    $s = $pdo->prepare("SELECT room_role FROM chat_room_members WHERE room_id=? AND user_id=?");
    $s->execute([$roomId, $uid]);
    return $s->fetchColumn() ?: '';
}

function getRoomType(int $roomId): string
{
    global $pdo;
    return $pdo->prepare("SELECT type FROM chat_rooms WHERE id=?")
               ->execute([$roomId]) ? $pdo->query("SELECT type FROM chat_rooms WHERE id={$roomId}")->fetchColumn() : '';
}

function jsonBody(): array
{
    $b = json_decode(file_get_contents('php://input'), true);
    return is_array($b) ? $b : [];
}

function ok(array $data = []): void
{
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}
function err(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// ═════════════════════════════════════════════════════
//  INIT TABLES
// ═════════════════════════════════════════════════════

function initTables(): void
{
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;

    // ── Создаём таблицы (IF NOT EXISTS) ─────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_rooms (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type          ENUM('direct','group','channel') NOT NULL DEFAULT 'group',
        name          VARCHAR(200) DEFAULT NULL,
        description   TEXT DEFAULT NULL,
        avatar_color  VARCHAR(10) DEFAULT '#003366',
        created_by    INT UNSIGNED NOT NULL DEFAULT 0,
        last_msg_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_msg_prev VARCHAR(300) DEFAULT NULL,
        last_sender   VARCHAR(100) DEFAULT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_last (last_msg_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_room_members (
        room_id   BIGINT UNSIGNED NOT NULL,
        user_id   INT UNSIGNED    NOT NULL,
        user_name VARCHAR(200)    NOT NULL,
        user_role VARCHAR(20)     NOT NULL DEFAULT 'admin',
        room_role ENUM('owner','admin','member') DEFAULT 'member',
        muted     TINYINT(1)      DEFAULT 0,
        joined_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (room_id, user_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_files (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        room_id     BIGINT UNSIGNED NOT NULL,
        sender_id   INT UNSIGNED    NOT NULL,
        orig_name   VARCHAR(500)    NOT NULL,
        stored_name VARCHAR(200)    NOT NULL UNIQUE,
        mime_type   VARCHAR(120)    NOT NULL,
        file_size   BIGINT UNSIGNED NOT NULL,
        img_w       SMALLINT DEFAULT NULL,
        img_h       SMALLINT DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_room (room_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // chat_messages: создаём или мигрируем старую структуру
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        room_id       BIGINT UNSIGNED NOT NULL,
        sender_id     INT UNSIGNED    NOT NULL,
        sender_name   VARCHAR(200)    NOT NULL,
        sender_role   VARCHAR(20)     NOT NULL DEFAULT 'admin',
        msg_type      ENUM('text','image','file','video','audio','system') DEFAULT 'text',
        body          TEXT DEFAULT NULL,
        file_id       BIGINT UNSIGNED DEFAULT NULL,
        reply_to      BIGINT UNSIGNED DEFAULT NULL,
        reply_preview VARCHAR(200) DEFAULT NULL,
        reply_sender  VARCHAR(100) DEFAULT NULL,
        is_deleted    TINYINT(1) DEFAULT 0,
        edited_at     TIMESTAMP DEFAULT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_room (room_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Миграция старой схемы chat_messages ──────────────
    // (если таблица существовала раньше, могут отсутствовать столбцы)
    $existingCols = [];
    try {
        $res = $pdo->query("SHOW COLUMNS FROM chat_messages");
        foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $existingCols[] = $col['Field'];
        }
    } catch (PDOException $e) {}

    $migrate = [
        'msg_type'      => "ALTER TABLE chat_messages ADD COLUMN msg_type ENUM('text','image','file','video','audio','system') DEFAULT 'text' AFTER sender_role",
        'file_id'       => "ALTER TABLE chat_messages ADD COLUMN file_id BIGINT UNSIGNED DEFAULT NULL AFTER body",
        'reply_to'      => "ALTER TABLE chat_messages ADD COLUMN reply_to BIGINT UNSIGNED DEFAULT NULL AFTER file_id",
        'reply_preview' => "ALTER TABLE chat_messages ADD COLUMN reply_preview VARCHAR(200) DEFAULT NULL AFTER reply_to",
        'reply_sender'  => "ALTER TABLE chat_messages ADD COLUMN reply_sender VARCHAR(100) DEFAULT NULL AFTER reply_preview",
        'is_deleted'    => "ALTER TABLE chat_messages ADD COLUMN is_deleted TINYINT(1) DEFAULT 0 AFTER reply_sender",
        'edited_at'     => "ALTER TABLE chat_messages ADD COLUMN edited_at TIMESTAMP DEFAULT NULL AFTER is_deleted",
    ];
    foreach ($migrate as $col => $sql) {
        if (!in_array($col, $existingCols, true)) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
    }

    // room_id в старой схеме мог быть VARCHAR(50) — проверяем тип
    foreach ($existingCols as $col) {
        if ($col === 'room_id') {
            $info = $pdo->query("SHOW COLUMNS FROM chat_messages LIKE 'room_id'")->fetch(PDO::FETCH_ASSOC);
            if ($info && stripos($info['Type'], 'varchar') !== false) {
                // Старая VARCHAR room_id — пересоздаём таблицу с правильным типом
                // (сохраняем старые сообщения быть не можем — другой формат)
                try {
                    $pdo->exec("RENAME TABLE chat_messages TO chat_messages_bak_" . time());
                    $pdo->exec("CREATE TABLE chat_messages (
                        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        room_id       BIGINT UNSIGNED NOT NULL,
                        sender_id     INT UNSIGNED    NOT NULL,
                        sender_name   VARCHAR(200)    NOT NULL,
                        sender_role   VARCHAR(20)     NOT NULL DEFAULT 'admin',
                        msg_type      ENUM('text','image','file','video','audio','system') DEFAULT 'text',
                        body          TEXT DEFAULT NULL,
                        file_id       BIGINT UNSIGNED DEFAULT NULL,
                        reply_to      BIGINT UNSIGNED DEFAULT NULL,
                        reply_preview VARCHAR(200) DEFAULT NULL,
                        reply_sender  VARCHAR(100) DEFAULT NULL,
                        is_deleted    TINYINT(1) DEFAULT 0,
                        edited_at     TIMESTAMP DEFAULT NULL,
                        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_room (room_id, id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } catch (PDOException $e) {}
            }
            break;
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_read (
        room_id      BIGINT UNSIGNED NOT NULL,
        user_id      INT UNSIGNED    NOT NULL,
        last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (room_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_presence (
        user_id   INT UNSIGNED NOT NULL,
        user_name VARCHAR(200) NOT NULL,
        user_role VARCHAR(20)  NOT NULL DEFAULT 'admin',
        last_ping TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id),
        INDEX idx_ping (last_ping)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_signals (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        call_id    VARCHAR(64)   NOT NULL,
        from_id    INT UNSIGNED  NOT NULL,
        from_name  VARCHAR(200)  NOT NULL,
        to_id      INT UNSIGNED  DEFAULT NULL,
        sig_type   VARCHAR(30)   NOT NULL,
        payload    MEDIUMTEXT    DEFAULT NULL,
        created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        consumed   TINYINT(1)    DEFAULT 0,
        INDEX idx_to_pending (to_id, consumed, id),
        INDEX idx_call (call_id),
        INDEX idx_cleanup (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Общий канал по умолчанию
    try {
        $pdo->exec("INSERT IGNORE INTO chat_rooms (id, type, name, description, avatar_color, created_by)
                    VALUES (1, 'channel', 'Общий', 'Общий канал для администраторов', '#003366', 0)");
    } catch (PDOException $e) {}
}

// ═════════════════════════════════════════════════════
//  PING / PRESENCE
// ═════════════════════════════════════════════════════

function doPing(): void
{
    global $pdo, $uid, $uname, $urole;

    $pdo->prepare(
        "INSERT INTO chat_presence (user_id, user_name, user_role)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE user_name=VALUES(user_name), user_role=VALUES(user_role), last_ping=NOW()"
    )->execute([$uid, $uname, $urole]);

    // Авто-вступление в Общий канал
    $pdo->prepare(
        "INSERT IGNORE INTO chat_room_members (room_id, user_id, user_name, user_role, room_role)
         VALUES (1, ?, ?, ?, 'member')"
    )->execute([$uid, $uname, $urole]);

    // Чистка старых записей
    $pdo->exec("DELETE FROM chat_presence WHERE last_ping < DATE_SUB(NOW(), INTERVAL 25 SECOND)");
    $pdo->exec("DELETE FROM chat_signals WHERE consumed=1 AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

    ok(['ts' => time()]);
}

function doOnline(): void
{
    global $pdo;
    $rows = $pdo->query(
        "SELECT user_id, user_name, user_role FROM chat_presence ORDER BY user_name"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['user_id'] = (int)$r['user_id'];
    echo json_encode(['online' => $rows]);
}

// ═════════════════════════════════════════════════════
//  ROOMS
// ═════════════════════════════════════════════════════

function doRooms(): void
{
    global $pdo, $uid, $uname;

    // Все комнаты пользователя + unread count
    $stmt = $pdo->prepare("
        SELECT r.id, r.type, r.name, r.description, r.avatar_color,
               r.last_msg_at, r.last_msg_prev, r.last_sender,
               rm.room_role, rm.muted,
               COALESCE(cr.last_read_id, 0) AS last_read_id,
               (SELECT COUNT(*) FROM chat_messages m
                WHERE m.room_id = r.id AND m.id > COALESCE(cr.last_read_id,0)
                  AND m.sender_id != ? AND m.is_deleted = 0) AS unread
        FROM chat_rooms r
        JOIN chat_room_members rm ON rm.room_id = r.id AND rm.user_id = ?
        LEFT JOIN chat_read cr ON cr.room_id = r.id AND cr.user_id = ?
        ORDER BY r.last_msg_at DESC
    ");
    $stmt->execute([$uid, $uid, $uid]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rooms as &$r) {
        $r['id']      = (int)$r['id'];
        $r['unread']  = (int)$r['unread'];
        $r['muted']   = (bool)$r['muted'];

        // Для direct — имя = имя собеседника
        if ($r['type'] === 'direct') {
            $other = $pdo->prepare(
                "SELECT user_name FROM chat_room_members WHERE room_id=? AND user_id!=? LIMIT 1"
            );
            $other->execute([$r['id'], $uid]);
            $r['name'] = $other->fetchColumn() ?: 'Личная беседа';
        }
    }
    unset($r);

    echo json_encode(['rooms' => $rooms]);
}

function doCreateRoom(): void
{
    global $pdo, $uid, $uname, $urole;

    if (!isAjax()) err('AJAX only');
    Csrf::guard();

    $b    = jsonBody();
    $type = $b['type'] ?? 'group';
    if (!in_array($type, ['direct','group','channel'], true)) err('Invalid type');

    try {
        // ─── Direct message ─────────────────────────────
        if ($type === 'direct') {
            $toId   = (int)($b['to_id'] ?? 0);
            $toName = trim($b['to_name'] ?? '');
            if (!$toId || !$toName) err('to_id and to_name required');

            // Ищем существующий direct между двумя пользователями
            $stmt = $pdo->prepare("
                SELECT r.id FROM chat_rooms r
                JOIN chat_room_members m1 ON m1.room_id=r.id AND m1.user_id=?
                JOIN chat_room_members m2 ON m2.room_id=r.id AND m2.user_id=?
                WHERE r.type='direct'
                LIMIT 1
            ");
            $stmt->execute([$uid, $toId]);
            $existId = $stmt->fetchColumn();

            if ($existId) {
                ok(['room_id' => (int)$existId, 'existing' => true]);
            }

            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO chat_rooms (type, created_by, avatar_color) VALUES ('direct', ?, '#003366')"
            )->execute([$uid]);
            $roomId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                "INSERT IGNORE INTO chat_room_members (room_id, user_id, user_name, user_role, room_role)
                 VALUES (?,?,?,?,'owner')"
            );
            $ins->execute([$roomId, $uid,   $uname,  $urole]);
            $ins->execute([$roomId, $toId,  $toName, 'admin']);

            insertSystemMsg($pdo, $roomId, "{$uname} начал(а) личный чат с {$toName}");
            $pdo->commit();
            ok(['room_id' => $roomId, 'existing' => false]);
        }

        // ─── Group / Channel ─────────────────────────────
        $name    = trim($b['name']        ?? '');
        $desc    = trim($b['description'] ?? '');
        $color   = preg_match('/^#[0-9a-f]{6}$/i', $b['color'] ?? '') ? $b['color'] : '#003366';
        $members = is_array($b['members'] ?? null) ? $b['members'] : [];

        if (!$name) err('Name required');

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO chat_rooms (type, name, description, avatar_color, created_by) VALUES (?,?,?,?,?)"
        )->execute([$type, $name, $desc, $color, $uid]);
        $roomId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT IGNORE INTO chat_room_members (room_id, user_id, user_name, user_role, room_role)
             VALUES (?,?,?,?,?)"
        );
        $ins->execute([$roomId, $uid, $uname, $urole, 'owner']);

        foreach ($members as $m) {
            $mId   = (int)($m['id']   ?? 0);
            $mName = trim($m['name']  ?? '');
            $mRole = trim($m['role']  ?? 'admin');
            if ($mId && $mName && $mId !== $uid) {
                $ins->execute([$roomId, $mId, $mName, $mRole, 'member']);
            }
        }

        $sysMsg = $type === 'channel'
            ? "{$uname} создал(а) канал «{$name}»"
            : "{$uname} создал(а) группу «{$name}»";
        insertSystemMsg($pdo, $roomId, $sysMsg);

        $pdo->commit();
        ok(['room_id' => $roomId]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        err('Ошибка БД: ' . $e->getMessage(), 500);
    }
}

/** Вставляет системное сообщение без броска исключения */
function insertSystemMsg(PDO $pdo, int $roomId, string $text): void
{
    $pdo->prepare(
        "INSERT INTO chat_messages
             (room_id, sender_id, sender_name, sender_role, msg_type, body)
         VALUES (?, 0, 'Система', 'system', 'system', ?)"
    )->execute([$roomId, $text]);
}

function doAddMember(): void
{
    global $pdo, $uid, $uname;
    Csrf::guard();

    $b      = jsonBody();
    $roomId = (int)($b['room_id'] ?? 0);
    $toId   = (int)($b['user_id'] ?? 0);
    $toName = trim($b['user_name'] ?? '');
    $toRole = $b['user_role'] ?? 'admin';

    if (!$roomId || !$toId || !$toName) err('Missing fields');

    $rr = getRoomRole($roomId);
    if (!in_array($rr, ['owner','admin'], true)) err('Not enough rights', 403);

    $pdo->prepare(
        "INSERT IGNORE INTO chat_room_members (room_id, user_id, user_name, user_role, room_role) VALUES (?,?,?,?,'member')"
    )->execute([$roomId, $toId, $toName, $toRole]);

    $pdo->prepare(
        "INSERT INTO chat_messages (room_id, sender_id, sender_name, sender_role, msg_type, body)
         VALUES (?,0,'Система','system','system',?)"
    )->execute([$roomId, "{$uname} добавил(а) {$toName}"]);

    ok();
}

function doLeaveRoom(): void
{
    global $pdo, $uid, $uname;
    Csrf::guard();

    $b      = jsonBody();
    $roomId = (int)($b['room_id'] ?? 0);
    if (!$roomId) err('Missing room_id');

    try {
        $pdo->prepare("DELETE FROM chat_room_members WHERE room_id=? AND user_id=?")->execute([$roomId, $uid]);
        insertSystemMsg($pdo, $roomId, "{$uname} покинул(а) чат");
    } catch (PDOException $e) {
        err('Ошибка БД: ' . $e->getMessage(), 500);
    }
    ok();
}

function doRoomMembers(): void
{
    global $pdo;
    $roomId = (int)($_GET['room_id'] ?? 0);
    if (!$roomId || !isMember($roomId)) err('Forbidden', 403);

    $rows = $pdo->prepare(
        "SELECT user_id, user_name, user_role, room_role,
                (SELECT 1 FROM chat_presence WHERE user_id=rm.user_id) AS online
         FROM chat_room_members rm WHERE room_id=? ORDER BY room_role, user_name"
    );
    $rows->execute([$roomId]);
    $data = $rows->fetchAll(PDO::FETCH_ASSOC);
    foreach ($data as &$r) {
        $r['user_id'] = (int)$r['user_id'];
        $r['online']  = (bool)$r['online'];
    }
    echo json_encode(['members' => $data]);
}

// ═════════════════════════════════════════════════════
//  MESSAGES
// ═════════════════════════════════════════════════════

function doMessages(): void
{
    global $pdo, $uid;

    $roomId = (int)($_GET['room_id'] ?? 0);
    $after  = max(0, (int)($_GET['after'] ?? 0));
    $before = (int)($_GET['before'] ?? 0); // для пагинации вверх
    $limit  = min(60, max(1, (int)($_GET['limit'] ?? 50)));

    if (!$roomId || !isMember($roomId)) err('Forbidden', 403);

    if ($before > 0) {
        $stmt = $pdo->prepare(
            "SELECT m.*, f.orig_name, f.stored_name, f.mime_type, f.file_size, f.img_w, f.img_h
             FROM chat_messages m LEFT JOIN chat_files f ON f.id = m.file_id
             WHERE m.room_id=? AND m.id < ?
             ORDER BY m.id DESC LIMIT ?"
        );
        $stmt->execute([$roomId, $before, $limit]);
        $msgs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        $stmt = $pdo->prepare(
            "SELECT m.*, f.orig_name, f.stored_name, f.mime_type, f.file_size, f.img_w, f.img_h
             FROM chat_messages m LEFT JOIN chat_files f ON f.id = m.file_id
             WHERE m.room_id=? AND m.id > ?
             ORDER BY m.id ASC LIMIT ?"
        );
        $stmt->execute([$roomId, $after, $limit]);
        $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($msgs as &$m) {
        $m['id']        = (int)$m['id'];
        $m['sender_id'] = (int)$m['sender_id'];
        $m['file_id']   = $m['file_id'] ? (int)$m['file_id'] : null;
        $m['is_deleted']= (bool)$m['is_deleted'];
    }
    unset($m);

    echo json_encode(['messages' => $msgs]);
}

function doSend(): void
{
    global $pdo, $uid, $uname, $urole;
    Csrf::guard();

    $b      = jsonBody();
    $roomId = (int)($b['room_id'] ?? 0);
    $text   = trim($b['text'] ?? '');
    $fileId = isset($b['file_id']) ? (int)$b['file_id'] : null;
    $replyTo= isset($b['reply_to']) ? (int)$b['reply_to'] : null;

    if (!$roomId || !isMember($roomId)) err('Forbidden', 403);

    // Для каналов: только owner/admin могут писать
    $roomStmt = $pdo->prepare("SELECT type FROM chat_rooms WHERE id=?");
    $roomStmt->execute([$roomId]);
    $rType = $roomStmt->fetchColumn();
    if ($rType === 'channel' && !in_array(getRoomRole($roomId), ['owner','admin'], true)) {
        err('Only admins can post in channels', 403);
    }

    if (!$text && !$fileId) err('Empty message');
    if (mb_strlen($text) > 8000) err('Message too long');

    // Определяем тип сообщения
    $msgType = 'text';
    $replyPreview = null;
    $replySender  = null;

    if ($fileId) {
        $fStmt = $pdo->prepare("SELECT mime_type, orig_name FROM chat_files WHERE id=? AND room_id=?");
        $fStmt->execute([$fileId, $roomId]);
        $fRow = $fStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fRow) err('File not found');
        $mime = $fRow['mime_type'];
        if      (strncmp($mime,'image/',6)===0) $msgType = 'image';
        elseif  (strncmp($mime,'video/',6)===0) $msgType = 'video';
        elseif  (strncmp($mime,'audio/',6)===0) $msgType = 'audio';
        else                                    $msgType = 'file';
    }

    // Превью реплая
    if ($replyTo) {
        $rStmt = $pdo->prepare("SELECT body, sender_name FROM chat_messages WHERE id=?");
        $rStmt->execute([$replyTo]);
        $rRow = $rStmt->fetch(PDO::FETCH_ASSOC);
        if ($rRow) {
            $replyPreview = mb_substr($rRow['body'] ?? '', 0, 100);
            $replySender  = $rRow['sender_name'];
        }
    }

    try {
        $pdo->prepare(
            "INSERT INTO chat_messages
                 (room_id, sender_id, sender_name, sender_role, msg_type, body,
                  file_id, reply_to, reply_preview, reply_sender)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([$roomId, $uid, $uname, $urole, $msgType, $text ?: null,
                    $fileId, $replyTo, $replyPreview, $replySender]);

        $newId = (int)$pdo->lastInsertId();

        $preview = $fileId ? ($text ?: '📎 Файл') : mb_substr($text, 0, 100);
        $pdo->prepare(
            "UPDATE chat_rooms SET last_msg_at=NOW(), last_msg_prev=?, last_sender=? WHERE id=?"
        )->execute([$preview, $uname, $roomId]);
    } catch (PDOException $e) {
        err('Ошибка БД: ' . $e->getMessage(), 500);
    }

    ok(['id' => $newId]);
}

function doDeleteMsg(): void
{
    global $pdo, $uid;
    Csrf::guard();

    $b   = jsonBody();
    $mid = (int)($b['id'] ?? 0);
    if (!$mid) err('Missing id');

    $stmt = $pdo->prepare("SELECT sender_id, room_id FROM chat_messages WHERE id=?");
    $stmt->execute([$mid]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$msg) err('Not found', 404);

    $rr = getRoomRole((int)$msg['room_id']);
    if ($msg['sender_id'] != $uid && !in_array($rr, ['owner','admin'], true)) {
        err('Forbidden', 403);
    }

    $pdo->prepare("UPDATE chat_messages SET is_deleted=1, body='Сообщение удалено' WHERE id=?")->execute([$mid]);
    ok();
}

function doMarkRead(): void
{
    global $pdo, $uid;
    Csrf::guard();

    $b      = jsonBody();
    $roomId = (int)($b['room_id'] ?? 0);
    $lastId = (int)($b['last_id'] ?? 0);
    if (!$roomId || !$lastId) err('Missing fields');

    $pdo->prepare(
        "INSERT INTO chat_read (room_id, user_id, last_read_id)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))"
    )->execute([$roomId, $uid, $lastId]);

    ok();
}

// ═════════════════════════════════════════════════════
//  FILE DOWNLOAD
// ═════════════════════════════════════════════════════

function doFile(): void
{
    global $pdo, $uid;

    $fileId = (int)($_GET['id'] ?? 0);
    if (!$fileId) err('Missing id');

    $stmt = $pdo->prepare("SELECT * FROM chat_files WHERE id=?");
    $stmt->execute([$fileId]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$f) err('Not found', 404);

    // Проверяем членство
    if (!isMember((int)$f['room_id'])) err('Forbidden', 403);

    $path = dirname(__DIR__) . '/uploads/chat/' . $f['stored_name'];
    if (!file_exists($path)) err('File not found on disk', 404);

    // Сброс JSON-заголовка и отдача файла
    header_remove('Content-Type');
    $mt     = $f['mime_type'];
    $inline = strncmp($mt,'image/',6)===0 || strncmp($mt,'video/',6)===0
           || strncmp($mt,'audio/',6)===0 || $mt === 'application/pdf';

    header('Content-Type: ' . $f['mime_type']);
    header('Content-Length: ' . $f['file_size']);
    header('Cache-Control: private, max-age=86400');
    if ($inline) {
        header('Content-Disposition: inline; filename="' . addslashes($f['orig_name']) . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . addslashes($f['orig_name']) . '"');
    }
    readfile($path);
    exit;
}

// ═════════════════════════════════════════════════════
//  WebRTC SIGNALING
// ═════════════════════════════════════════════════════

function doPoll(): void
{
    global $pdo, $uid;

    $stmt = $pdo->prepare(
        "SELECT id, call_id, from_id, from_name, sig_type, payload
         FROM chat_signals WHERE to_id=? AND consumed=0 ORDER BY id ASC LIMIT 20"
    );
    $stmt->execute([$uid]);
    $sigs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($sigs) {
        $ids = implode(',', array_map(fn($s) => (int)$s['id'], $sigs));
        $pdo->exec("UPDATE chat_signals SET consumed=1 WHERE id IN ($ids)");
        foreach ($sigs as &$s) {
            $s['id']      = (int)$s['id'];
            $s['from_id'] = (int)$s['from_id'];
            $s['payload'] = $s['payload'] ? json_decode($s['payload'], true) : null;
        }
        unset($s);
    }

    echo json_encode(['signals' => $sigs]);
}

function doSignal(): void
{
    global $pdo, $uid, $uname;

    $b = jsonBody();
    $callId  = preg_replace('/[^a-zA-Z0-9_-]/', '', $b['call_id']  ?? '');
    $toId    = isset($b['to_id']) ? (int)$b['to_id'] : null;
    $sigType = preg_replace('/[^a-z]/', '', $b['sig_type'] ?? '');
    $payload = isset($b['payload']) ? json_encode($b['payload']) : null;

    $allowed = ['invite','answer','ice','reject','hangup','busy'];
    if (!$callId || !in_array($sigType, $allowed, true)) err('Invalid signal');

    $pdo->prepare(
        "INSERT INTO chat_signals (call_id, from_id, from_name, to_id, sig_type, payload)
         VALUES (?,?,?,?,?,?)"
    )->execute([$callId, $uid, $uname, $toId, $sigType, $payload]);

    ok();
}
