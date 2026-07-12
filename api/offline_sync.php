<?php
/**
 * =====================================================
 *  OFFLINE SYNC API  —  severfoods.ru
 *
 *  Используется Electron-приложением severfoods_offline
 *  для двусторонней синхронизации данных.
 *
 *  Auth: заголовок  X-Sync-Token: <OFFLINE_SYNC_TOKEN из .env>
 *
 *  GET  ?action=ping              — проверка связи
 *  GET  ?action=employees         — все активные сотрудники + QR
 *  GET  ?action=meal_points       — точки питания с расписаниями
 *  GET  ?action=logs&since=ISO    — записи питания с указанной даты
 *  POST ?action=push              — загрузить офлайн-записи на сервер
 *  POST ?action=auth              — аутентификация пользователя
 * =====================================================
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Sync-Token, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ─── Аутентификация ──────────────────────────────────
$syncToken = env('OFFLINE_SYNC_TOKEN', '');
$provided  = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';

if ($syncToken === '' || $provided === '' || !hash_equals($syncToken, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid sync token']);
    exit;
}

$action = $_GET['action'] ?? '';

// ─── Роутер ──────────────────────────────────────────
switch ($action) {
    case 'ping':       doPing();       break;
    case 'employees':  doEmployees();  break;
    case 'meal_points':doMealPoints(); break;
    case 'logs':       doLogs();       break;
    case 'push':       doPush();       break;
    case 'auth':       doAuth();           break;
    case 'mobile_chat_login': doMobileChatLogin(); break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

// ═════════════════════════════════════════════════════
//  HANDLERS
// ═════════════════════════════════════════════════════

function doPing(): void
{
    echo json_encode([
        'ok'      => true,
        'ts'      => time(),
        'server'  => 'severfoods.ru',
        'version' => APP_VERSION,
    ]);
}

function doEmployees(): void
{
    global $pdo;
    $rows = $pdo->query(
        "SELECT id, full_name, birth_date, organization, department, position,
                vjg_type, price, qr_code, qr_expires_at, qr_status,
                is_active, role, assigned_point_id,
                UNIX_TIMESTAMP(updated_at) AS updated_ts
         FROM employees
         WHERE is_active = 1
           AND qr_code IS NOT NULL
           AND NOT (COALESCE(chat_access,0)=1 AND role IS NULL)
         ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id']           = (int)$r['id'];
        $r['price']        = (float)($r['price'] ?? 0);
        $r['is_active']    = (int)$r['is_active'];
        $r['updated_ts']   = (int)($r['updated_ts'] ?? 0);
        $r['assigned_point_id'] = $r['assigned_point_id'] ? (int)$r['assigned_point_id'] : null;
    }
    unset($r);

    echo json_encode(['ok' => true, 'employees' => $rows, 'ts' => time()]);
}

function doMealPoints(): void
{
    global $pdo;

    $points = $pdo->query(
        "SELECT id, point_name, point_code, city, address, is_active
         FROM meal_points WHERE is_active = 1 ORDER BY sort_order, point_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($points as &$p) {
        $p['id'] = (int)$p['id'];
        $p['is_active'] = (int)$p['is_active'];

        // Расписание точки
        $sched = $pdo->prepare(
            "SELECT meal_type, start_time, end_time, days_of_week
             FROM meal_point_schedules
             WHERE meal_point_id = ? AND is_active = 1
             ORDER BY meal_type"
        );
        $sched->execute([$p['id']]);
        $p['schedules'] = $sched->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($p);

    echo json_encode(['ok' => true, 'meal_points' => $points, 'ts' => time()]);
}

function doLogs(): void
{
    global $pdo;
    $since = $_GET['since'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 500), 2000);

    $params = [];
    $where  = "access_granted = 1";
    if ($since) {
        $where   .= " AND scanned_at >= ?";
        $params[] = $since;
    }

    $stmt = $pdo->prepare(
        "SELECT ml.id, ml.employee_id, ml.meal_type, ml.access_granted,
                ml.scanner_ip, ml.operator_name, ml.meal_point_id,
                ml.meal_point_name, ml.scanned_at,
                e.full_name AS employee_name, e.organization
         FROM meal_logs ml
         JOIN employees e ON e.id = ml.employee_id
         WHERE {$where}
         ORDER BY ml.scanned_at DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id']          = (int)$r['id'];
        $r['employee_id'] = (int)$r['employee_id'];
    }
    unset($r);

    echo json_encode(['ok' => true, 'logs' => $rows, 'ts' => time()]);
}

function doPush(): void
{
    global $pdo;

    $body = json_decode(file_get_contents('php://input'), true);
    if (!isset($body['records']) || !is_array($body['records'])) {
        http_response_code(400);
        echo json_encode(['error' => 'records[] required']);
        exit;
    }

    $results  = [];
    $inserted = 0;
    $skipped  = 0;
    $errors   = 0;

    foreach ($body['records'] as $rec) {
        $offlineId  = $rec['offline_id'] ?? null;
        $empId      = (int)($rec['employee_id'] ?? 0);
        $mealType   = $rec['meal_type'] ?? '';
        $scannedAt  = $rec['scanned_at'] ?? null;  // ISO 8601 или UNIX ms
        $pointId    = isset($rec['meal_point_id']) ? (int)$rec['meal_point_id'] : null;
        $pointName  = $rec['meal_point_name'] ?? 'Офлайн';
        $opName     = $rec['operator_name']   ?? 'Офлайн-синхронизация';

        $validTypes = ['breakfast','lunch','dinner','night'];
        if ($empId <= 0 || !in_array($mealType, $validTypes, true)) {
            $results[] = ['offline_id' => $offlineId, 'status' => 'error', 'msg' => 'invalid'];
            $errors++;
            continue;
        }

        // Нормализуем время: в БД всегда храним чистый UTC.
        // Клиент шлёт либо UNIX-мс (уже честный UTC-эпох), либо строку времени.
        // Офлайн-приложение формирует scanned_at через toISOString() и обрезает 'Z' —
        // это ГОЛАЯ UTC-строка без офсета, поэтому strtotime() нельзя доверять "как есть":
        // без явного офсета он трактует её в серверном часовом поясе PHP (Europe/Moscow),
        // что даёт сдвиг на 3 часа. Явный офсет/Z в строке — уважаем и парсим как есть.
        if (is_numeric($scannedAt)) {
            $ts = (int)($scannedAt / 1000);
        } elseif ($scannedAt) {
            $hasOffset = (bool)preg_match('/[Zz]$|[+\-]\d{2}:?\d{2}$/', trim($scannedAt));
            $ts = strtotime($hasOffset ? $scannedAt : $scannedAt . ' UTC');
            if ($ts === false) $ts = time();
        } else {
            $ts = time();
        }
        $scannedAt = gmdate('Y-m-d H:i:s', $ts);

        // Клиент присылает meal_point_id из своего локального кэша — он может
        // быть устаревшим (точка деактивирована/удалена на сервере). Не
        // доверяем ему вслепую для расчёта часового пояса/дедупа.
        if ($pointId) {
            $point = getMealPointById($pdo, $pointId);
            if (!$point) $pointId = null;
        }

        // 'night' в базе не хранится — переклассифицируем по местному времени точки
        // (до полудня — завтрак, после — ужин), см. normalizeMealType().
        // Если точки нет/невалидна — фиксированный серверный часовой пояс
        // (SERVER_TZ_OFFSET), НЕ APP_TZ_OFFSET: у офлайн-синхронизации нет
        // браузера/cookie, так что APP_TZ_OFFSET здесь всегда был бы жёстко
        // захардкоженным дефолтом — лучше явная стабильная константа.
        $pointTzForType = $pointId ? getPointTz($pdo, $pointId) : SERVER_TZ_OFFSET;
        $localTimeAtScan = gmdate('H:i:s', $ts + offsetToMinutes($pointTzForType) * 60);
        $mealType = normalizeMealType($mealType, $localTimeAtScan);
        $day = gmdate('Y-m-d', $ts + offsetToMinutes($pointTzForType) * 60);

        // Лок на время проверки+вставки — исключает дубль при повторной
        // отправке того же батча из-за обрыва связи или при синхронизации
        // с двух устройств одновременно.
        $locked = acquireMealLock($pdo, $empId);
        try {
            if (hasExistingMealLog($pdo, $empId, $mealType, $pointId, $day)) {
                $results[] = ['offline_id' => $offlineId, 'status' => 'duplicate'];
                $skipped++;
                continue;
            }

            $pdo->prepare(
                "INSERT INTO meal_logs
                     (employee_id, meal_type, access_granted, scanner_ip,
                      operator_name, meal_point_id, meal_point_name, scanned_at)
                 VALUES (?, ?, 1, 'offline', ?, ?, ?, ?)"
            )->execute([$empId, $mealType, $opName, $pointId, $pointName, $scannedAt]);

            $serverId  = (int)$pdo->lastInsertId();
            $results[] = ['offline_id' => $offlineId, 'status' => 'ok', 'server_id' => $serverId];
            $inserted++;
        } catch (PDOException $e) {
            $results[] = ['offline_id' => $offlineId, 'status' => 'error', 'msg' => $e->getMessage()];
            $errors++;
        } finally {
            if ($locked) releaseMealLock($pdo, $empId);
        }
    }

    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'results'  => $results,
        'ts'       => time(),
    ]);
}

function doAuth(): void
{
    global $pdo;
    // Body from POST (json) or GET params fallback
    $body = [];
    $raw  = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    if (empty($body)) $body = $_GET + $_POST; // fallback for redirect-converted GETs
    $qrCode  = trim($body['qr_code'] ?? '');
    $role    = $body['role'] ?? 'operator';
    $pointId = isset($body['meal_point_id']) ? (int)$body['meal_point_id'] : null;

    if ($qrCode === '') {
        http_response_code(400);
        echo json_encode(['error' => 'QR-код обязателен']);
        exit;
    }

    if ($role === 'admin') {
        $stmt = $pdo->prepare(
            "SELECT * FROM employees
             WHERE qr_code = ? AND role IN ('admin','super_admin') AND is_active = 1 LIMIT 1"
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM employees
             WHERE qr_code = ? AND role IN ('operator','admin','super_admin') AND is_active = 1 LIMIT 1"
        );
    }
    $stmt->execute([$qrCode]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$emp) {
        http_response_code(401);
        echo json_encode(['error' => 'QR-код не найден или недостаточно прав']);
        exit;
    }

    // If operator chose a specific point — store it
    $selectedPointId = $pointId ?: ($emp['assigned_point_id'] ?? null);
    $pointName = null;
    if ($selectedPointId) {
        $pt = $pdo->prepare("SELECT point_name FROM meal_points WHERE id = ? LIMIT 1");
        $pt->execute([$selectedPointId]);
        $ptRow = $pt->fetch(PDO::FETCH_ASSOC);
        $pointName = $ptRow ? $ptRow['point_name'] : null;
    }

    $payload = base64_encode(json_encode([
        'id'   => (int)$emp['id'],
        'role' => $emp['role'],
        'exp'  => time() + 30 * 86400,
    ]));
    $sig   = hash_hmac('sha256', $payload, env('OFFLINE_SYNC_TOKEN', ''));
    $token = $payload . '.' . $sig;

    unset($emp['password']);
    $emp['id']                = (int)$emp['id'];
    $emp['assigned_point_id'] = $emp['assigned_point_id'] ? (int)$emp['assigned_point_id'] : null;
    $emp['selected_point_id'] = $selectedPointId ? (int)$selectedPointId : null;
    $emp['selected_point_name'] = $pointName;

    echo json_encode([
        'ok'            => true,
        'employee'      => $emp,
        'session_token' => $token,
        'expires_at'    => date('c', time() + 30 * 86400),
        'ts'            => time(),
    ]);
}

function doMobileChatLogin(): void {
    global $pdo;
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $qrCode  = trim($body['qr_code'] ?? '');
    if (!$qrCode) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'qr_code required']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM employees WHERE qr_code=? AND is_active=1 LIMIT 1");
    $stmt->execute([$qrCode]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'QR-код не найден']); exit; }

    // Generate mobile chat token (random, stored in sync_meta as JSON map)
    $token    = bin2hex(random_bytes(32));
    $expires  = time() + 30 * 86400;

    // Store token → employee_id mapping in sync_meta table
    $mapRaw = $pdo->query("SELECT value FROM sync_meta WHERE `key`='mobile_chat_tokens' LIMIT 1")->fetchColumn();
    $map    = $mapRaw ? json_decode($mapRaw, true) : [];
    // Clean expired tokens
    foreach ($map as $t => $data) { if ($data['exp'] < time()) unset($map[$t]); }
    $map[$token] = ['uid' => (int)$emp['id'], 'uname' => $emp['full_name'], 'urole' => $emp['role'], 'exp' => $expires];
    $json = json_encode($map);
    $pdo->prepare("INSERT INTO sync_meta(`key`,value) VALUES('mobile_chat_tokens',?) ON DUPLICATE KEY UPDATE value=?")->execute([$json,$json]);

    unset($emp['password']);
    $emp['id'] = (int)$emp['id'];
    echo json_encode(['ok'=>true,'mobile_token'=>$token,'employee'=>$emp,'expires_at'=>date('c',$expires)]);
}
