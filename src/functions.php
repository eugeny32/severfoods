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

function getCurrentMealType(?PDO $pdo = null, $meal_point_id = null): string
{
    $current_time = date('H:i:s');
    $current_day  = date('N'); // 1=Пн … 7=Вс

    if (!$pdo || !$meal_point_id) {
        if ($current_time >= '07:00:00' && $current_time < '11:00:00') return 'breakfast';
        if ($current_time >= '12:00:00' && $current_time < '15:00:00') return 'lunch';
        if ($current_time >= '18:00:00' && $current_time < '21:00:00') return 'dinner';
        if ($current_time >= '23:00:00' || $current_time <  '06:00:00') return 'night';
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
            if ($current_time >= $start || $current_time < $end) return $s['meal_type'];
        } else {
            if ($current_time >= $start && $current_time < $end) return $s['meal_type'];
        }
    }
    return 'none';
}

function getNextMealInfo(PDO $pdo, $meal_point_id): array
{
    if (!$meal_point_id) return [];
    $current_time = date('H:i:s');
    $current_day  = date('N');

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
    $current_day = date('N');
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
    if (!empty($employee['qr_expires_at']) && $employee['qr_expires_at'] < date('Y-m-d')) {
        return ['success' => false, 'message' => 'Срок действия QR-кода истёк',
                'employee' => $employee, 'code' => 'EXPIRED'];
    }

    $operator_id     = $_SESSION['user_id']        ?? null;
    $operator_name   = $_SESSION['user_name']       ?? 'Система';
    $meal_point_id   = $_SESSION['meal_point_id']   ?? null;
    $meal_point_name = $_SESSION['meal_point_name'] ?? null;

    $meal_type = getCurrentMealType($pdo, $meal_point_id);

    if ($meal_type === 'none') {
        return ['success' => false,
                'message'  => 'Сейчас не время приёма пищи' . ($meal_point_name ? " на точке «{$meal_point_name}»" : ''),
                'employee' => $employee, 'code' => 'NO_MEAL_TIME'];
    }

    $today = date('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT scanned_at FROM meal_logs
         WHERE employee_id = ? AND meal_type = ? AND DATE(scanned_at) = ?
           AND access_granted = 1
         ORDER BY scanned_at DESC LIMIT 1"
    );
    $stmt->execute([$employee['id'], $meal_type, $today]);
    $last_scan = $stmt->fetch();

    if ($last_scan) {
        // Повторное сканирование в течение 30 сек — не ошибка
        if ((time() - strtotime($last_scan['scanned_at'])) <= 30) {
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
    $today = date('Y-m-d');
    $stats = ['total' => 0, 'breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'night' => 0];
    try {
        $stmt = $pdo->prepare(
            "SELECT meal_type,
                    COUNT(DISTINCT CONCAT(employee_id,'_',meal_type)) AS cnt
             FROM meal_logs
             WHERE DATE(scanned_at) = ? AND access_granted = 1
             GROUP BY meal_type"
        );
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['meal_type']] = (int)$row['cnt'];
            $stats['total']           += (int)$row['cnt'];
        }
    } catch (PDOException $e) {}
    return $stats;
}

function getPointTodayStats(PDO $pdo, $meal_point_id): array
{
    $today = date('Y-m-d');
    $stats = ['total' => 0, 'breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'night' => 0];
    if (!$meal_point_id) return $stats;
    try {
        $stmt = $pdo->prepare(
            "SELECT meal_type,
                    COUNT(DISTINCT CONCAT(employee_id,'_',meal_type)) AS cnt
             FROM meal_logs
             WHERE meal_point_id = ? AND DATE(scanned_at) = ? AND access_granted = 1
             GROUP BY meal_type"
        );
        $stmt->execute([$meal_point_id, $today]);
        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['meal_type']] = (int)$row['cnt'];
            $stats['total']           += (int)$row['cnt'];
        }
    } catch (PDOException $e) {}
    return $stats;
}

function getAllPointsStats(PDO $pdo): array
{
    $today = date('Y-m-d');
    try {
        $stmt = $pdo->prepare(
            "SELECT mp.id, mp.point_name, mp.point_code, mp.city,
                    COALESCE(SUM(CASE WHEN ml.access_granted=1 THEN 1 ELSE 0 END),0) AS today_count
             FROM meal_points mp
             LEFT JOIN meal_logs ml
               ON ml.meal_point_id = mp.id AND DATE(ml.scanned_at) = ?
             WHERE mp.is_active = 1
             GROUP BY mp.id
             ORDER BY mp.point_name"
        );
        $stmt->execute([$today]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function getWeeklyStats(PDO $pdo, $meal_point_id = null): array
{
    $days    = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    $stats   = array_fill_keys($days, 0);
    $weekMap = [2=>'Пн',3=>'Вт',4=>'Ср',5=>'Чт',6=>'Пт',7=>'Сб',1=>'Вс'];
    try {
        if ($meal_point_id) {
            $stmt = $pdo->prepare(
                "SELECT DAYOFWEEK(scanned_at) AS dow,
                        COUNT(DISTINCT CONCAT(employee_id,'_',DATE(scanned_at),'_',meal_type)) AS cnt
                 FROM meal_logs
                 WHERE DATE(scanned_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   AND access_granted = 1 AND meal_point_id = ?
                 GROUP BY DAYOFWEEK(scanned_at)"
            );
            $stmt->execute([$meal_point_id]);
        } else {
            $stmt = $pdo->query(
                "SELECT DAYOFWEEK(scanned_at) AS dow,
                        COUNT(DISTINCT CONCAT(employee_id,'_',DATE(scanned_at),'_',meal_type)) AS cnt
                 FROM meal_logs
                 WHERE DATE(scanned_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   AND access_granted = 1
                 GROUP BY DAYOFWEEK(scanned_at)"
            );
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
        if ($meal_point_id) {
            $stmt = $pdo->prepare(
                "SELECT e.full_name, e.organization,
                        COUNT(DISTINCT CONCAT(ml.employee_id,'_',DATE(ml.scanned_at),'_',ml.meal_type)) AS meals_count
                 FROM meal_logs ml JOIN employees e ON ml.employee_id = e.id
                 WHERE DATE(ml.scanned_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND ml.access_granted = 1 AND ml.meal_point_id = ?
                 GROUP BY e.id ORDER BY meals_count DESC LIMIT ?"
            );
            $stmt->execute([$meal_point_id, $limit]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT e.full_name, e.organization,
                        COUNT(DISTINCT CONCAT(ml.employee_id,'_',DATE(ml.scanned_at),'_',ml.meal_type)) AS meals_count
                 FROM meal_logs ml JOIN employees e ON ml.employee_id = e.id
                 WHERE DATE(ml.scanned_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND ml.access_granted = 1
                 GROUP BY e.id ORDER BY meals_count DESC LIMIT ?"
            );
            $stmt->execute([$limit]);
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
               AND qr_expires_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY qr_expires_at ASC"
        );
        $stmt->execute([$days]);
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
    if (!empty($employee['qr_expires_at']) && $employee['qr_expires_at'] < date('Y-m-d')) return false;
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
    $sql = "SELECT * FROM meal_points";
    $sql .= $onlyActive ? " WHERE is_active = 1" : '';
    $sql .= " ORDER BY sort_order, point_name";
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) { return []; }
}

function getMealPointById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM meal_points WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
