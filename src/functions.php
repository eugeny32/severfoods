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
        'night'     => 'Ночное питание',
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
 * 'night' больше не используется как самостоятельный тип приёма пищи в базе —
 * ночные проходы переклассифицируются в ближайший осмысленный тип по местному
 * времени точки (до полудня — завтрак, после — ужин). Единая точка применения
 * гарантирует, что новые 'night'-записи в meal_logs никогда не появятся,
 * независимо от того, как настроено расписание точки.
 */
function normalizeMealType(string $type, string $localTime): string
{
    if ($type !== 'night') return $type;
    return $localTime < '12:00:00' ? 'breakfast' : 'dinner';
}

function getCurrentMealType(?PDO $pdo = null, $meal_point_id = null): string
{
    // Местное время считаем по часовому поясу КОНКРЕТНОЙ точки (если она известна),
    // а не по браузерному офсету — иначе расписание точки сверяется с чужим временем.
    $tz           = ($pdo && $meal_point_id) ? getPointTz($pdo, $meal_point_id) : APP_TZ_OFFSET;
    $current_time = gmdate('H:i:s', time() + offsetToMinutes($tz) * 60);
    $current_day  = gmdate('N', time() + offsetToMinutes($tz) * 60); // 1=Пн … 7=Вс

    if (!$pdo || !$meal_point_id) {
        if ($current_time >= '07:00:00' && $current_time < '11:00:00') return 'breakfast';
        if ($current_time >= '12:00:00' && $current_time < '15:00:00') return 'lunch';
        if ($current_time >= '18:00:00' && $current_time < '21:00:00') return 'dinner';
        if ($current_time >= '23:00:00') return 'dinner';
        if ($current_time <  '06:00:00') return 'breakfast';
        return 'none';
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM meal_point_schedules
         WHERE meal_point_id = ? AND is_active = 1
           AND FIND_IN_SET(?, days_of_week) > 0
         ORDER BY sort_order"
    );
    $stmt->execute([$meal_point_id, $current_day]);

    foreach ($stmt->fetchAll() as $s) {
        $start = $s['start_time'];
        $end   = $s['end_time'];
        // Поддержка ночного расписания (переход через полночь)
        if ($end < $start) {
            if ($current_time >= $start || $current_time < $end) return normalizeMealType($s['meal_type'], $current_time);
        } else {
            if ($current_time >= $start && $current_time < $end) return normalizeMealType($s['meal_type'], $current_time);
        }
    }
    return 'none';
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
    $stmt = $pdo->prepare(
        "SELECT scanned_at FROM meal_logs
         WHERE employee_id = ? AND meal_type = ? AND DATE(CONVERT_TZ(scanned_at, '+00:00', ?)) = ?
           AND access_granted = 1
         ORDER BY scanned_at DESC LIMIT 1"
    );
    $stmt->execute([$employee['id'], $meal_type, $pointTz, $today]);
    $last_scan = $stmt->fetch();

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
            "SELECT id, full_name, organization, qr_expires_at
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

// ─── Точки питания ────────────────────────────────────

function getMealPoints(PDO $pdo, bool $onlyActive = true): array
{
    try { $pdo->exec("ALTER TABLE meal_points ADD COLUMN tz_offset VARCHAR(6) DEFAULT NULL"); } catch (PDOException $e) {}
    $sql = "SELECT * FROM meal_points";
    $sql .= $onlyActive ? " WHERE is_active = 1" : '';
    $sql .= " ORDER BY sort_order, point_name";
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) { return []; }
}

function getMealPointById(PDO $pdo, int $id): ?array
{
    try { $pdo->exec("ALTER TABLE meal_points ADD COLUMN tz_offset VARCHAR(6) DEFAULT NULL"); } catch (PDOException $e) {}
    $stmt = $pdo->prepare("SELECT * FROM meal_points WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Часовой пояс точки питания ("+07:00") — свой, если задан, иначе глобальный по умолчанию. */
function getPointTz(PDO $pdo, $meal_point_id): string
{
    static $cache = [];
    if (!$meal_point_id) return APP_TZ_OFFSET;
    if (isset($cache[$meal_point_id])) return $cache[$meal_point_id];
    $tz = APP_TZ_OFFSET;
    try {
        $stmt = $pdo->prepare("SELECT tz_offset FROM meal_points WHERE id = ?");
        $stmt->execute([$meal_point_id]);
        $v = $stmt->fetchColumn();
        if ($v && preg_match('/^[+-]\d{2}:\d{2}$/', $v)) $tz = $v;
    } catch (PDOException $e) {}
    return $cache[$meal_point_id] = $tz;
}
