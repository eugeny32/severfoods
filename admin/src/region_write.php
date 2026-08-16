<?php
/**
 * Запись в базу конкретного региона.
 *
 * Сводные отчёты читают все базы одним запросом через их границы. Запись так
 * делать нельзя: она всегда адресуется ОДНОМУ региону, выбранному явно, и идёт
 * через отдельное соединение к его базе. Так исключается целый класс ошибок,
 * когда правка уходит не в ту базу.
 */

declare(strict_types=1);

/**
 * Новый QR-код в том же формате, что и в региональной системе
 * (`generateUniqueQrCode()` в src/functions.php): EMP_<время>_<12 hex>.
 * Проверяем на неповторимость прямо в целевой базе — коды из разных регионов
 * между собой не конфликтуют, но внутри одного обязаны быть уникальны.
 */
function adminNewQrCode(PDO $regionPdo): string
{
    for ($i = 0; $i < 10; $i++) {
        $code = 'EMP_' . time() . '_' . bin2hex(random_bytes(6));
        $st = $regionPdo->prepare('SELECT 1 FROM employees WHERE qr_code = ? LIMIT 1');
        $st->execute([$code]);
        if (!$st->fetchColumn()) return $code;
    }
    throw new RuntimeException('Не удалось подобрать уникальный QR-код');
}

/**
 * Добавление сотрудника в выбранный регион.
 *
 * Дата рождения при пустом значении остаётся NULL. Форма региональной системы
 * в этом случае подставляет СЛУЧАЙНУЮ дату — выдуманные персональные данные
 * хуже отсутствующих, повторять это здесь не будем.
 *
 * Роль не задаётся: этой страницей заводят питающихся, а выдача прав
 * администратора остаётся в региональной системе. Меньше поверхность для
 * повышения привилегий из центральной панели.
 */
function adminCreateEmployee(PDO $regionPdo, array $d): int
{
    $qr = adminNewQrCode($regionPdo);
    $regionPdo->prepare(
        'INSERT INTO employees
            (full_name, birth_date, organization, department, position,
             vjg_type, price, qr_code, qr_expires_at, qr_status, is_active, role, assigned_point_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL, NULL)'
    )->execute([
        $d['full_name'],
        $d['birth_date'] !== '' ? $d['birth_date'] : null,
        $d['organization'],
        $d['department'],
        $d['position'],
        $d['vjg_type'],
        (float)$d['price'],
        $qr,
        $d['qr_status'],
        (int)$d['is_active'],
    ]);
    return (int)$regionPdo->lastInsertId();
}

/**
 * Правка сотрудника.
 *
 * QR-код НЕ меняется никогда — карта на руках должна продолжать работать и в
 * онлайне, и в оффлайне. Роль и привязка к точке тоже не трогаются: их меняют
 * в региональной системе.
 */
function adminUpdateEmployee(PDO $regionPdo, int $id, array $d): void
{
    $regionPdo->prepare(
        'UPDATE employees SET
            full_name = ?, birth_date = ?, organization = ?, department = ?,
            position = ?, vjg_type = ?, price = ?, qr_status = ?, is_active = ?
         WHERE id = ?'
    )->execute([
        $d['full_name'],
        $d['birth_date'] !== '' ? $d['birth_date'] : null,
        $d['organization'],
        $d['department'],
        $d['position'],
        $d['vjg_type'],
        (float)$d['price'],
        $d['qr_status'],
        (int)$d['is_active'],
        $id,
    ]);
}

/** Точка питания: добавление. */
function adminCreatePoint(PDO $regionPdo, array $d): int
{
    $regionPdo->prepare(
        'INSERT INTO meal_points (point_name, point_code, city, address, sort_order, tz_offset, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $d['point_name'], $d['point_code'], $d['city'], $d['address'],
        (int)$d['sort_order'], $d['tz_offset'], (int)$d['is_active'],
    ]);
    return (int)$regionPdo->lastInsertId();
}

/** Точка питания: правка. */
function adminUpdatePoint(PDO $regionPdo, int $id, array $d): void
{
    $regionPdo->prepare(
        'UPDATE meal_points SET point_name = ?, point_code = ?, city = ?, address = ?,
                sort_order = ?, tz_offset = ?, is_active = ? WHERE id = ?'
    )->execute([
        $d['point_name'], $d['point_code'], $d['city'], $d['address'],
        (int)$d['sort_order'], $d['tz_offset'], (int)$d['is_active'], $id,
    ]);
}

/**
 * Есть ли у сотрудника история питания. Удаление такого сотрудника запрещено и
 * в региональной системе: записи о питании ссылаются на него, и без него отчёт
 * за прошлый месяц перестанет читаться.
 */
function adminEmployeeHasHistory(PDO $regionPdo, int $id): bool
{
    $st = $regionPdo->prepare('SELECT 1 FROM meal_logs WHERE employee_id = ? LIMIT 1');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}
