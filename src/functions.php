<?php
/**
 * =====================================================
 *  CANTEEN ACCESS SYSTEM — БИЗНЕС-ЛОГИКА
 * =====================================================
 */

// ─── Типы питания ─────────────────────────────────────

function getMealTypeName(string $type): string
{
    return [
        'breakfast' => 'Завтрак',
        'lunch'     => 'Обед',
        'dinner'    => 'Ужин',
        'night'     => 'Ночное',
        'none'      => 'Вне приёма пищи',
    ][$type] ?? 'Неизвестно';
}

function getMealTypeIcon(string $type): string
{
    return [
        'breakfast' => '<i class="fas fa-cloud-sun"></i>',
        'lunch'     => '<i class="fas fa-sun"></i>',
        'dinner'    => '<i class="fas fa-moon"></i>',
        'night'     => '<i class="fas fa-star"></i>',
        'none'      => '<i class="fas fa-pause-circle"></i>',
    ][$type] ?? '<i class="fas fa-utensils"></i>';
}

// ─── Расписание и текущий приём пищи ─────────────────

/**
 * Тип приёма пищи из расписания точки — как есть.
 *
 * Раньше 'night' здесь принудительно переводился в завтрак/ужин: ночного
 * питания в системе не было, и такие окна расписания считались ошибкой.
 * Теперь ночное питание — полноценный приём наравне с остальными: если на
 * точке заведено окно с типом «Ночное», проход в это окно так и записывается,
 * попадает в статистику ночного питания и в фильтр отчёта.
 *
 * Функция оставлена единой точкой применения: правило определения типа по
 * местному времени точки живёт в одном месте, а не размазано по четырём
 * эндпоинтам (сканирование, оффлайн-синхронизация, sync_log, миграции).
 */
function normalizeMealType(string $type, string $localTime): string
{
    return $type;
}

/**
 * Какое окно расписания действует в данный момент местного времени точки.
 *
 * Вынесено отдельной функцией, потому что это же правило нужно отчёту (бейдж
 * «вне графика») — раньше оно было переписано там вторым экземпляром и могло
 * разъехаться с этим.
 *
 * Окно, у которого время окончания меньше времени начала, переходит через
 * полночь (типично для ночного питания). После полуночи такое окно принадлежит
 * ПРЕДЫДУЩЕМУ дню недели: смена «Пн 23:00–06:00» продолжается во вторник, и
 * проход в 00:30 вторника обязан попасть в понедельничное окно.
 *
 * @param array  $schedules строки meal_point_schedules (start_time, end_time, days_of_week, meal_type)
 * @param string $localTime местное время точки, 'HH:MM:SS'
 * @param int    $weekday   день недели местного времени, 1=Пн … 7=Вс
 * @return array|null подошедшее окно или null
 */
function matchSchedule(array $schedules, string $localTime, int $weekday): ?array
{
    $prevDay = $weekday == 1 ? 7 : $weekday - 1;
    foreach ($schedules as $s) {
        $days  = ',' . ($s['days_of_week'] ?? '') . ',';
        $today = strpos($days, ',' . $weekday . ',') !== false;
        $yday  = strpos($days, ',' . $prevDay . ',') !== false;
        if ($s['end_time'] < $s['start_time']) {
            if ($today && $localTime >= $s['start_time']) return $s;
            if ($yday  && $localTime <  $s['end_time'])   return $s;
        } elseif ($today) {
            if ($localTime >= $s['start_time'] && $localTime < $s['end_time']) return $s;
        }
    }
    return null;
}

function getCurrentMealType(?PDO $pdo = null, $meal_point_id = null): string
{
    // Местное время считаем по часовому поясу КОНКРЕТНОЙ точки (если она известна),
    // иначе — по фиксированному серверному офсету (SERVER_TZ_OFFSET), а не по
    // браузерному cookie: иначе расписание/тип питания могли бы определяться
    // по-разному в зависимости от того, чей браузер сделал запрос.
    $tz           = ($pdo && $meal_point_id) ? getPointTz($pdo, $meal_point_id) : SERVER_TZ_OFFSET;
    $current_time = gmdate('H:i:s', time() + offsetToMinutes($tz) * 60);
    $current_day  = gmdate('N', time() + offsetToMinutes($tz) * 60); // 1=Пн … 7=Вс

    if (!$pdo || !$meal_point_id) {
        if ($current_time >= '07:00:00' && $current_time < '11:00:00') return 'breakfast';
        if ($current_time >= '12:00:00' && $current_time < '15:00:00') return 'lunch';
        if ($current_time >= '18:00:00' && $current_time < '21:00:00') return 'dinner';
        // Ночное питание — такой же приём, как остальные. Окно переходит через
        // полночь, поэтому проверяется двумя условиями.
        if ($current_time >= '23:00:00' || $current_time < '06:00:00') return 'night';
        return 'none';
    }

    // Расписания берём за текущий и предыдущий день недели: окно, переходящее
    // через полночь, после полуночи относится к предыдущему дню (см. matchSchedule).
    $prev_day = $current_day == 1 ? 7 : $current_day - 1;
    $stmt = $pdo->prepare(
        "SELECT * FROM meal_point_schedules
         WHERE meal_point_id = ? AND is_active = 1
           AND (FIND_IN_SET(?, days_of_week) > 0 OR FIND_IN_SET(?, days_of_week) > 0)
         ORDER BY sort_order"
    );
    $stmt->execute([$meal_point_id, $current_day, $prev_day]);

    $hit = matchSchedule($stmt->fetchAll(), $current_time, (int)$current_day);
    return $hit ? normalizeMealType($hit['meal_type'], $current_time) : 'none';
}

function getNextMealInfo(PDO $pdo, $meal_point_id): array
{
    if (!$meal_point_id) return [];
    $tz           = getPointTz($pdo, $meal_point_id);
    $current_time = gmdate('H:i:s', time() + offsetToMinutes($tz) * 60);
    $current_day  = gmdate('N', time() + offsetToMinutes($tz) * 60);

    $stmt = $pdo->prepare(
        "SELECT * FROM meal_point_schedules
         WHERE meal_point_id = ? AND is_active = 1
           AND FIND_IN_SET(?, days_of_week) > 0
         ORDER BY start_time"
    );
    $stmt->execute([$meal_point_id, $current_day]);
    $schedules = $stmt->fetchAll();

    foreach ($schedules as $s) {
        if ($s['start_time'] > $current_time) {
            return [
                'meal_type' => $s['meal_type'],
                'name'      => $s['meal_name_ru'],
                'start'     => substr($s['start_time'], 0, 5),
                'end'       => substr($s['end_time'],   0, 5),
            ];
        }
    }
    return [];
}

function getPointScheduleInfo(PDO $pdo, $meal_point_id): array
{
    $current_day = gmdate('N', time() + offsetToMinutes(getPointTz($pdo, $meal_point_id)) * 60);
    $stmt = $pdo->prepare(
        "SELECT * FROM meal_point_schedules
         WHERE meal_point_id = ? AND is_active = 1
           AND FIND_IN_SET(?, days_of_week) > 0
         ORDER BY sort_order"
    );
    $stmt->execute([$meal_point_id, $current_day]);
    return $stmt->fetchAll();
}

// ─── Основной процесс доступа ─────────────────────────

function processAccess(PDO $pdo, string $qr_code, ?string $ip = null): array
{
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE qr_code = ? AND is_active = 1");
    $stmt->execute([$qr_code]);
    $employee = $stmt->fetch();

    if (!$employee) {
        return ['success' => false, 'message' => 'Сотрудник не найден или заблокирован',
                'employee' => null, 'code' => 'NOT_FOUND'];
    }
    if ($employee['qr_status'] === 'blocked') {
        return ['success' => false, 'message' => 'QR-код заблокирован',
                'employee' => $employee, 'code' => 'BLOCKED'];
    }
    if (!empty($employee['qr_expires_at']) && $employee['qr_expires_at'] < localToday()) {
        return ['success' => false, 'message' => 'Срок действия QR-кода истёк',
                'employee' => $employee, 'code' => 'EXPIRED'];
    }

    $operator_id     = $_SESSION['user_id']        ?? null;
    $operator_name   = $_SESSION['user_name']       ?? 'Система';
    // Администратор при входе не выбирает точку явно (в отличие от оператора) —
    // meal_point_id в сессии не появляется. Без этого расписание точки (например,
    // завтрак с 6:20) игнорируется, и подстановка "по умолчанию" в getCurrentMealType
    // (07:00–11:00) ошибочно блокирует более ранние приёмы пищи. Используем
    // назначенную администратору точку как запасной вариант.
    $meal_point_id   = $_SESSION['meal_point_id']   ?? ($_SESSION['assigned_point_id'] ?? null);
    $meal_point_name = $_SESSION['meal_point_name'] ?? null;
    if (!$meal_point_name && $meal_point_id) {
        $mp = getMealPointById($pdo, $meal_point_id);
        $meal_point_name = $mp['point_name'] ?? null;
    }

    $meal_type = getCurrentMealType($pdo, $meal_point_id);

    if ($meal_type === 'none') {
        return ['success' => false,
                'message'  => 'Сейчас не время приёма пищи' . ($meal_point_name ? " на точке «{$meal_point_name}»" : ''),
                'employee' => $employee, 'code' => 'NO_MEAL_TIME'];
    }

    $pointTz = getPointTz($pdo, $meal_point_id);
    $today   = gmdate('Y-m-d', time() + offsetToMinutes($pointTz) * 60);

    // Лок на время проверки+вставки — исключает дубль при двух почти
    // одновременных сканированиях одного сотрудника (см. hasExistingMealLog).
    $locked = acquireMealLock($pdo, $employee['id']);
    if (!$locked) {
        return ['success' => false, 'message' => 'Система обрабатывает предыдущий запрос, повторите сканирование',
                'employee' => $employee, 'code' => 'BUSY'];
    }
    try {
        $last_scan = hasExistingMealLog($pdo, $employee['id'], $meal_type, $meal_point_id, $today);

        if ($last_scan) {
            // Повторное сканирование в течение 30 сек — не ошибка
            if ((time() - strtotime($last_scan['scanned_at'] . ' UTC')) <= 30) {
                return ['success' => true,
                        'message'   => "ДОСТУП РАЗРЕШЁН (повтор): {$employee['full_name']}",
                        'employee'  => $employee, 'meal_type' => $meal_type, 'code' => 'REPEAT_SCAN'];
            }
            return ['success' => false,
                    'message'       => "{$employee['full_name']} уже питался(ась) сегодня — " . getMealTypeName($meal_type),
                    'employee'      => $employee, 'code' => 'ALREADY_ATE',
                    'last_scan_at'  => $last_scan['scanned_at']];
        }

        // Новый проход — фиксируем
        $pdo->prepare(
            "INSERT INTO meal_logs
                 (employee_id, meal_type, access_granted, scanner_ip,
                  operator_id, operator_name, meal_point_id, meal_point_name)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?)"
        )->execute([
            $employee['id'], $meal_type, $ip,
            $operator_id, $operator_name,
            $meal_point_id, $meal_point_name,
        ]);
    } finally {
        releaseMealLock($pdo, $employee['id']);
    }

    // Аннулировать выездное питание на сегодня (отметить красным, не удалять)
    try {
        $pdo->prepare(
            "UPDATE dry_rations SET status='cancelled', cancelled_at=NOW() WHERE employee_id=? AND ration_date=? AND ration_type='field' AND status='active'"
        )->execute([$employee['id'], $today]);
    } catch (PDOException $e) {}

    $price_msg = ($employee['price'] > 0)
        ? number_format($employee['price'], 0, '.', ' ') . ' ₽'
        : null;

    return ['success' => true,
            'message'   => "ДОСТУП РАЗРЕШЁН: {$employee['full_name']}",
            'employee'  => $employee, 'meal_type' => $meal_type,
            'price'     => $price_msg, 'point' => $meal_point_name, 'code' => 'OK'];
}

// ─── Статистика ───────────────────────────────────────

function getTodayStats(PDO $pdo): array
{
    $stats = ['total' => 0, 'breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'night' => 0];
    try {
        // Каждая точка считает "сегодня" по своему часовому поясу — точки
        // группируем по офсету, чтобы не делать запрос на каждую точку отдельно.
        $points  = $pdo->query("SELECT id, tz_offset FROM meal_points WHERE is_active = 1")->fetchAll();
        $byOffset = [];
        foreach ($points as $p) {
            $tz = (!empty($p['tz_offset']) && preg_match('/^[+-]\d{2}:\d{2}$/', $p['tz_offset'])) ? $p['tz_offset'] : APP_TZ_OFFSET;
            $byOffset[$tz][] = (int)$p['id'];
        }
        if (!$byOffset) $byOffset[APP_TZ_OFFSET] = [];

        foreach ($byOffset as $tz => $pointIds) {
            [$start, $end] = pointTodayWindow($tz);
            if ($pointIds) {
                $ph  = implode(',', array_fill(0, count($pointIds), '?'));
                $sql = "SELECT meal_type, COUNT(DISTINCT CONCAT(employee_id,'_',meal_type)) AS cnt
                        FROM meal_logs
                        WHERE meal_point_id IN ($ph) AND scanned_at BETWEEN ? AND ? AND access_granted = 1
                        GROUP BY meal_type";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([...$pointIds, $start, $end]);
            } else {
                // Записи без активной точки питания — по глобальному часовому поясу
                $stmt = $pdo->prepare(
                    "SELECT meal_type, COUNT(DISTINCT CONCAT(employee_id,'_',meal_type)) AS cnt
                     FROM meal_logs
                     WHERE meal_point_id IS NULL AND scanned_at BETWEEN ? AND ? AND access_granted = 1
                     GROUP BY meal_type"
                );
                $stmt->execute([$start, $end]);
            }
            foreach ($stmt->fetchAll() as $row) {
                $stats[$row['meal_type']] = ($stats[$row['meal_type']] ?? 0) + (int)$row['cnt'];
                $stats['total']           += (int)$row['cnt'];
            }
        }
    } catch (PDOException $e) {}
    return $stats;
}

function getPointTodayStats(PDO $pdo, $meal_point_id): array
{
    $stats = ['total' => 0, 'breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'night' => 0];
    if (!$meal_point_id) return $stats;
    try {
        [$start, $end] = pointTodayWindow(getPointTz($pdo, $meal_point_id));
        $stmt = $pdo->prepare(
            "SELECT meal_type,
                    COUNT(DISTINCT CONCAT(employee_id,'_',meal_type)) AS cnt
             FROM meal_logs
             WHERE meal_point_id = ? AND scanned_at BETWEEN ? AND ? AND access_granted = 1
             GROUP BY meal_type"
        );
        $stmt->execute([$meal_point_id, $start, $end]);
        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['meal_type']] = (int)$row['cnt'];
            $stats['total']           += (int)$row['cnt'];
        }
    } catch (PDOException $e) {}
    return $stats;
}

function getAllPointsStats(PDO $pdo): array
{
    try {
        $points = $pdo->query(
            "SELECT id, point_name, point_code, city, tz_offset
             FROM meal_points WHERE is_active = 1 ORDER BY point_name"
        )->fetchAll();
        foreach ($points as &$p) {
            $tz = (!empty($p['tz_offset']) && preg_match('/^[+-]\d{2}:\d{2}$/', $p['tz_offset'])) ? $p['tz_offset'] : APP_TZ_OFFSET;
            [$start, $end] = pointTodayWindow($tz);
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM meal_logs
                 WHERE meal_point_id = ? AND access_granted = 1 AND scanned_at BETWEEN ? AND ?"
            );
            $stmt->execute([$p['id'], $start, $end]);
            $p['today_count'] = (int)$stmt->fetchColumn();
        }
        unset($p);
        return $points;
    } catch (PDOException $e) { return []; }
}

function getWeeklyStats(PDO $pdo, $meal_point_id = null): array
{
    $days    = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    $stats   = array_fill_keys($days, 0);
    $weekMap = [2=>'Пн',3=>'Вт',4=>'Ср',5=>'Чт',6=>'Пт',7=>'Сб',1=>'Вс'];
    try {
        $weekAgo = gmdate('Y-m-d', strtotime(localToday() . ' -7 days'));
        $tzCol   = tzExpr('scanned_at');
        if ($meal_point_id) {
            $stmt = $pdo->prepare(
                "SELECT DAYOFWEEK($tzCol) AS dow,
                        COUNT(DISTINCT CONCAT(employee_id,'_',DATE($tzCol),'_',meal_type)) AS cnt
                 FROM meal_logs
                 WHERE DATE($tzCol) >= ?
                   AND access_granted = 1 AND meal_point_id = ?
                 GROUP BY DAYOFWEEK($tzCol)"
            );
            $stmt->execute([$weekAgo, $meal_point_id]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT DAYOFWEEK($tzCol) AS dow,
                        COUNT(DISTINCT CONCAT(employee_id,'_',DATE($tzCol),'_',meal_type)) AS cnt
                 FROM meal_logs
                 WHERE DATE($tzCol) >= ?
                   AND access_granted = 1
                 GROUP BY DAYOFWEEK($tzCol)"
            );
            $stmt->execute([$weekAgo]);
        }
        foreach ($stmt->fetchAll() as $row) {
            $key = $weekMap[$row['dow']] ?? null;
            if ($key) $stats[$key] = (int)$row['cnt'];
        }
    } catch (PDOException $e) {}
    return $stats;
}

function getTopEmployees(PDO $pdo, int $limit = 10, $meal_point_id = null): array
{
    try {
        $monthAgo = gmdate('Y-m-d', strtotime(localToday() . ' -30 days'));
        $tzCol    = tzExpr('ml.scanned_at');
        if ($meal_point_id) {
            $stmt = $pdo->prepare(
                "SELECT e.full_name, e.organization,
                        COUNT(DISTINCT CONCAT(ml.employee_id,'_',DATE($tzCol),'_',ml.meal_type)) AS meals_count
                 FROM meal_logs ml JOIN employees e ON ml.employee_id = e.id
                 WHERE DATE($tzCol) >= ?
                   AND ml.access_granted = 1 AND ml.meal_point_id = ?
                 GROUP BY e.id ORDER BY meals_count DESC LIMIT ?"
            );
            $stmt->execute([$monthAgo, $meal_point_id, $limit]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT e.full_name, e.organization,
                        COUNT(DISTINCT CONCAT(ml.employee_id,'_',DATE($tzCol),'_',ml.meal_type)) AS meals_count
                 FROM meal_logs ml JOIN employees e ON ml.employee_id = e.id
                 WHERE DATE($tzCol) >= ?
                   AND ml.access_granted = 1
                 GROUP BY e.id ORDER BY meals_count DESC LIMIT ?"
            );
            $stmt->execute([$monthAgo, $limit]);
        }
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function getExpiringEmployees(PDO $pdo, int $days = 7): array
{
    // Используем prepare + биндинг — не строковую интерполяцию
    try {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, organization, qr_expires_at, role
             FROM employees
             WHERE is_active = 1 AND qr_expires_at IS NOT NULL
               AND qr_expires_at <= DATE_ADD(?, INTERVAL ? DAY)
             ORDER BY qr_expires_at ASC"
        );
        $stmt->execute([localToday(), $days]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

// ─── Сотрудники ───────────────────────────────────────

function getEmployees(PDO $pdo, bool $onlyActive = true): array
{
    $sql = "SELECT id, full_name, birth_date, organization, department, position,
                   vjg_type, price, qr_expires_at, qr_status, is_active, qr_code, role
            FROM employees";
    $conditions = ["NOT (COALESCE(chat_access,0) = 1 AND role IS NULL)"];
    if ($onlyActive) $conditions[] = "is_active = 1";
    $sql .= " WHERE " . implode(" AND ", $conditions);
    $sql .= " ORDER BY full_name";
    return $pdo->query($sql)->fetchAll();
}

function getEmployeeById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function isQrCodeValid(array $employee): bool
{
    if ($employee['qr_status'] !== 'active') return false;
    if (!empty($employee['qr_expires_at']) && $employee['qr_expires_at'] < localToday()) return false;
    if ($employee['is_active'] != 1) return false;
    return true;
}

function generateQRCode(string $text, int $size = 300): string
{
    return "https://quickchart.io/qr?text=" . urlencode($text)
         . "&size={$size}&margin=2&dark=003366&light=ffffff";
}

function generateUniqueQrCode(): string
{
    return 'EMP_' . time() . '_' . bin2hex(random_bytes(6));
}

// ─── ВЖГ ──────────────────────────────────────────────

function getVjgList(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT * FROM vjg_prices WHERE is_active = 1 ORDER BY sort_order, vjg_name"
        )->fetchAll();
    } catch (PDOException $e) { return []; }
}

// ─── Логирование действий ─────────────────────────────

function logAction(string $action, ?string $details = null): void
{
    global $pdo;
    $adminName = $_SESSION['user_name'] ?? 'Система';
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $pdo->prepare(
            "INSERT INTO admin_logs (admin_name, action, details, ip_address)
             VALUES (?, ?, ?, ?)"
        )->execute([$adminName, $action, $details, $ip]);
    } catch (PDOException $e) {
        // Не прерываем работу из-за ошибки логирования
    }
}

// ─── Фильтр отчётов по организациям ───────────────────

/**
 * Список организаций для фильтра отчётов.
 *
 * Берётся из карточек сотрудников — отдельного справочника организаций в
 * системе нет. Чат-аккаунты исключаются: это не сотрудники предприятий.
 *
 * @return string[]
 */
function getOrganizationList(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT DISTINCT TRIM(organization) AS o
             FROM employees
             WHERE organization IS NOT NULL AND TRIM(organization) <> ''
               AND NOT (COALESCE(chat_access,0) = 1 AND role IS NULL)
             ORDER BY o"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($rows));
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Условие «организация входит в выбранные» для отчётов.
 *
 * Один помощник на все запросы отчёта и обе выгрузки в Excel: условие
 * применяется в четырёх местах, и если бы оно разъехалось, на экране и в файле
 * оказались бы разные наборы строк — ошибка, которую замечают позже всего.
 *
 * Пустой выбор означает «все организации»: так отчёт открывается сразу, без
 * лишнего щелчка, и ведёт себя как раньше.
 *
 * @param string[] $orgs   выбранные организации
 * @param string   $column выражение с организацией (обычно e.organization)
 * @param string   $prefix префикс имён параметров — чтобы не столкнуться в одном запросе
 * @return array{0:string,1:array} кусок SQL (может быть пустым) и параметры
 */
function orgFilterSql(array $orgs, string $column = 'e.organization', string $prefix = 'org'): array
{
    $orgs = array_values(array_filter(array_map('trim', $orgs), fn($o) => $o !== ''));
    if (!$orgs) return ['', []];

    $names  = [];
    $params = [];
    foreach ($orgs as $i => $o) {
        $key = ":{$prefix}{$i}";
        $names[]       = $key;
        $params[$key]  = $o;
    }
    return [" AND TRIM({$column}) IN (" . implode(',', $names) . ")", $params];
}

/**
 * Организации, выбранные в запросе. Значения сверяются со списком реально
 * существующих — иначе в фильтр попадёт что угодно из адресной строки.
 *
 * @return string[]
 */
function selectedOrganizations(PDO $pdo, $raw): array
{
    if (!is_array($raw)) return [];
    $known = getOrganizationList($pdo);
    return array_values(array_intersect(array_map('trim', $raw), $known));
}

// ─── Дистрибутивы приложения ──────────────────────────

/**
 * Самый свежий файл сборки в каталоге: установщик Windows или APK.
 *
 * Один хелпер на все места, где предлагается скачать приложение — раньше
 * этот перебор был скопирован в download.php и в index.php, а с появлением
 * APK добавилось бы третье место, причём с нетривиальным правилом.
 *
 * Правило: **боевая сборка всегда важнее тестовой**, даже если у тестовой
 * номер версии выше. Сборка без ключа подписи помечается суффиксом "-test";
 * поставить её можно, а обновить потом боевой — уже нет, поэтому предлагать
 * её вместо боевой нельзя ни при каких условиях. При равном статусе
 * побеждает бо́льшая версия.
 *
 * @return array{path:string,name:string,version:string,size:string,date:string,is_test:bool}|null
 */
function latestBuildFile(string $dir, string $ext): ?array
{
    if (!is_dir($dir)) return null;

    $best = null; $bestVer = [0, 0, 0]; $bestIsRelease = false;

    foreach (glob(rtrim($dir, '/') . '/*.' . $ext) as $f) {
        $name = basename($f);
        if (!preg_match('/(\d+)\.(\d+)\.(\d+)/', $name, $m)) continue;
        $ver    = [(int)$m[1], (int)$m[2], (int)$m[3]];
        $isTest = str_contains($name, '-test');

        if ($best === null)                        { $take = true; }
        elseif ($bestIsRelease !== !$isTest)       { $take = !$isTest; }
        else                                       { $take = $ver > $bestVer; }

        if ($take) { $best = $f; $bestVer = $ver; $bestIsRelease = !$isTest; }
    }

    if ($best === null) return null;

    $name = basename($best);
    preg_match('/(\d+\.\d+\.\d+)/', $name, $m);
    return [
        'path'    => $best,
        'name'    => $name,
        'version' => 'v' . ($m[1] ?? '?'),
        'size'    => round(filesize($best) / 1024 / 1024, 1) . ' МБ',
        'date'    => date('d.m.Y', filemtime($best)),
        'is_test' => !$bestIsRelease,
    ];
}

// ─── Точки питания ────────────────────────────────────

/**
 * Досоздание колонки tz_offset для баз, развёрнутых до её появления.
 *
 * Раньше эта попытка стояла прямо в getMealPoints()/getMealPointById() и
 * выполнялась при КАЖДОМ вызове. В отправке офлайн-записей (doPush) точка
 * запрашивается на каждую запись, то есть на пакете в несколько сотен строк
 * сервер столько же раз пытался менять схему таблицы. Старые версии
 * приложения (1.3.x) шлют весь накопленный пакет одним запросом и ждут
 * ответа всего 15 секунд — из-за этого точка могла не уложиться в таймаут и
 * навсегда застрять с неотправленными записями.
 */
function ensureMealPointTzColumn(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->exec("ALTER TABLE meal_points ADD COLUMN tz_offset VARCHAR(6) DEFAULT NULL"); } catch (PDOException $e) {}
}

function getMealPoints(PDO $pdo, bool $onlyActive = true): array
{
    ensureMealPointTzColumn($pdo);
    $sql = "SELECT * FROM meal_points";
    $sql .= $onlyActive ? " WHERE is_active = 1" : '';
    $sql .= " ORDER BY sort_order, point_name";
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) { return []; }
}

function getMealPointById(PDO $pdo, int $id): ?array
{
    // Кэш в пределах запроса — по образцу getPointTz() ниже. В doPush() точка
    // у всего пакета обычно одна и та же, так что вместо сотен одинаковых
    // запросов остаётся один. Отсутствие точки тоже кэшируем (значение null),
    // иначе неверный id продолжал бы бить в базу на каждой записи.
    // Ключ включает соединение: в отчётах супер-администратор смотрит другой
    // регион через getRegionalPdo() (см. src/regions.php), а это ДРУГАЯ база с
    // такими же id точек — кэш только по id отдал бы точку не из той базы.
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $id;
    if (array_key_exists($key, $cache)) return $cache[$key];

    ensureMealPointTzColumn($pdo);
    $stmt = $pdo->prepare("SELECT * FROM meal_points WHERE id = ?");
    $stmt->execute([$id]);
    return $cache[$key] = ($stmt->fetch() ?: null);
}

/**
 * Часовой пояс точки питания ("+07:00") — свой, если задан, иначе
 * SERVER_TZ_OFFSET (фиксированное серверное значение, НЕ APP_TZ_OFFSET —
 * см. комментарий в bootstrap.php: для записей без точки нужна стабильная,
 * не зависящая от cookie браузера граница суток, иначе дедупликация может
 * давать разный результат в зависимости от того, кто и в каком браузере её
 * выполняет).
 */
function getPointTz(PDO $pdo, $meal_point_id): string
{
    static $cache = [];
    if (!$meal_point_id) return SERVER_TZ_OFFSET;
    // Ключ с соединением — по той же причине, что и в getMealPointById():
    // у другого региона своя база с такими же id точек.
    $ck = spl_object_id($pdo) . ':' . $meal_point_id;
    if (isset($cache[$ck])) return $cache[$ck];
    $tz = SERVER_TZ_OFFSET;
    try {
        $stmt = $pdo->prepare("SELECT tz_offset FROM meal_points WHERE id = ?");
        $stmt->execute([$meal_point_id]);
        $v = $stmt->fetchColumn();
        if ($v && preg_match('/^[+-]\d{2}:\d{2}$/', $v)) $tz = $v;
    } catch (PDOException $e) {}
    return $cache[$ck] = $tz;
}

// ─── Деконфликтинг: единая проверка дублей + межпроцессная блокировка ──

// ─── Разграничение доступа к карточкам сотрудников ────────────────────
// Единое правило для всех эндпоинтов, работающих с карточкой сотрудника
// (get_employee.php, update_employee.php, employee_stats.php, dry_rations.php,
// print_qr.php и т.д.), чтобы оно не расходилось между ними.

/**
 * Может ли текущий пользователь просматривать/редактировать карточку
 * этого сотрудника?
 *
 * Правило: администратор работает со всеми организациями; НИКОГДА не может
 * открыть карточку супер-администратора (защита его QR-кода от
 * компрометации — он же служит паролем). Супер-администратор — любую.
 *
 * Ограничение «только своя организация» здесь было и оказалось ошибкой:
 * администраторы обслуживают несколько организаций сразу и потеряли
 * возможность работать с чужими сотрудниками. Запрет на карточки
 * супер-администраторов при этом сохранён — он и был настоящей целью.
 *
 * ВАЖНО: это правило про КАРТОЧКУ сотрудника (его данные), а не про выдачу
 * питания. Проход через точку намеренно НЕ ограничен организацией — на одной
 * точке питаются сотрудники разных организаций, и оператор обязан обслужить
 * любого из них (см. manual_pass.php).
 */
function canAccessEmployeeCard(PDO $pdo, array $emp): bool
{
    if (($_SESSION['role'] ?? '') === 'super_admin') return true;
    return ($emp['role'] ?? '') !== 'super_admin';
}

/**
 * Есть ли у сотрудника уже активная запись этого типа питания на указанную
 * местную дату (по часовому поясу точки, либо SERVER_TZ_OFFSET если точка
 * не указана)? $localDate = null → берётся "сегодня" по этому же поясу.
 * Единая точка применения дедуп-логики — используется во всех путях записи
 * (реальный скан, ручной пропуск, массовая проводка, оффлайн-синхронизация),
 * чтобы правило не расходилось между ними.
 */
function hasExistingMealLog(PDO $pdo, int $employeeId, string $mealType, $meal_point_id, ?string $localDate = null, ?int $excludeId = null): ?array
{
    $tz   = getPointTz($pdo, $meal_point_id);
    $date = $localDate ?? gmdate('Y-m-d', time() + offsetToMinutes($tz) * 60);
    $sql  = "SELECT id, scanned_at FROM meal_logs
             WHERE employee_id = ? AND meal_type = ? AND DATE(CONVERT_TZ(scanned_at, '+00:00', ?)) = ?
               AND access_granted = 1";
    $params = [$employeeId, $mealType, $tz, $date];
    if ($excludeId !== null) { $sql .= " AND id != ?"; $params[] = $excludeId; }
    $sql .= " ORDER BY scanned_at DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/**
 * Межпроцессная блокировка на время "проверить нет ли записи → вставить"
 * для конкретного сотрудника (MySQL именованный лок, живёт на уровне
 * соединения). Без неё два почти одновременных запроса (двойной скан,
 * два админа проводят одного и того же сотрудника, повторная отправка при
 * обрыве связи) могут оба пройти проверку "записи нет" ещё до того, как
 * первый INSERT зафиксируется — итог: дубль. Таймаут короткий (5 сек) —
 * это разовая проверка+вставка, а не долгая операция.
 */
function acquireMealLock(PDO $pdo, int $employeeId, int $timeoutSec = 5): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute(['meal_emp_' . $employeeId, $timeoutSec]);
    return (bool)$stmt->fetchColumn();
}

function releaseMealLock(PDO $pdo, int $employeeId): void
{
    try {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['meal_emp_' . $employeeId]);
    } catch (PDOException $e) {}
}
