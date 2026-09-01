<?php
/**
 * Построение сводных отчётов по нескольким регионам.
 *
 * Вынесено отдельным модулем, потому что этим же кодом пользуются страница
 * отчётов и выгрузка в Excel: если запросы разъедутся, на экране и в файле
 * окажутся разные цифры — а это худший вид ошибки, его замечают позже всего.
 *
 * Местные сутки считаются по часовому поясу ТОЧКИ (meal_points.tz_offset), как
 * и в региональных отчётах. Иначе Кызыл и Нерюнгри с разницей в четыре часа
 * дали бы в сводке разные «сегодня».
 */

declare(strict_types=1);

/**
 * Журнал проходов по выбранным регионам.
 *
 * @param array $filters date_from, date_to, meal_type, source, search
 * @return array{0:string,1:array} SQL и параметры
 */
function adminMealsQuery(array $regions, array $filters, string $fallbackTz = '+03:00'): array
{
    $where  = ['ml.access_granted = 1'];
    $params = [];

    // Часовой пояс подставляется в текст запроса, а не плейсхолдером: он
    // встречается в нескольких местах, и передавать его параметром пришлось бы
    // столько же раз — легко ошибиться. Формат проверяем строго.
    if (!preg_match('/^[+-]\d{2}:\d{2}$/', $fallbackTz)) {
        throw new InvalidArgumentException("Некорректный часовой пояс: «{$fallbackTz}»");
    }

    // Местная дата прохода: UTC в базе → часовой пояс точки.
    $localDate = "DATE(CONVERT_TZ(ml.scanned_at, '+00:00', COALESCE(mp.tz_offset, '{$fallbackTz}')))";

    if (!empty($filters['date_from'])) { $where[] = "$localDate >= ?"; $params[] = $filters['date_from']; }
    if (!empty($filters['date_to']))   { $where[] = "$localDate <= ?"; $params[] = $filters['date_to']; }

    if (!empty($filters['meal_type'])) { $where[] = 'ml.meal_type = ?'; $params[] = $filters['meal_type']; }

    if (!empty($filters['search'])) {
        $where[] = '(e.full_name LIKE ? OR e.organization LIKE ?)';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }

    // Способ проводки определяется так же, как в региональных отчётах, —
    // по имени оператора: отдельного поля в таблице нет.
    if (!empty($filters['source'])) {
        $where[] = match ($filters['source']) {
            'bulk'    => "ml.operator_name LIKE 'Массовая%'",
            'manual'  => "ml.operator_name LIKE 'Ручная%'",
            'offline' => "ml.operator_name LIKE 'Офлайн%'",
            'scanner' => "(ml.operator_name IS NULL OR ml.operator_name NOT LIKE 'Массовая%'
                           AND ml.operator_name NOT LIKE 'Ручная%' AND ml.operator_name NOT LIKE 'Офлайн%')",
            default   => '1=1',
        };
    }

    $part = "SELECT {REGION} AS region_key,
                    ml.id           AS log_id,
                    ml.employee_id,
                    ml.scanned_at,
                    CONVERT_TZ(ml.scanned_at, '+00:00', COALESCE(mp.tz_offset, '{$fallbackTz}')) AS local_at,
                    ml.meal_type,
                    ml.operator_name,
                    e.full_name,
                    e.organization,
                    e.department,
                    e.vjg_type,
                    e.price,
                    mp.point_name
             FROM {DB}.meal_logs ml
             LEFT JOIN {DB}.employees   e  ON e.id  = ml.employee_id
             LEFT JOIN {DB}.meal_points mp ON mp.id = ml.meal_point_id
             WHERE " . implode(' AND ', $where);

    return adminUnionQuery($regions, $part, $params);
}

/**
 * Сводка по сотрудникам: сколько завтраков, обедов, ужинов и ночных приёмов у
 * каждого и в скольких днях он появлялся в столовой.
 *
 * Приёмы считаются так же, как в региональном отчёте: внутри одного типа за
 * один день — один приём, сколько бы строк в журнале ни было. Дублирующие
 * записи (скан плюс не удалённая ручная проводка) не должны раздувать счёт,
 * а сумма по четырём типам обязана сходиться с колонкой «Приёмов пищи».
 *
 * Группировка включает регион и employee_id: одинаковые id в разных базах —
 * разные люди, складывать их нельзя.
 */
function adminEmployeesQuery(array $regions, array $filters, string $fallbackTz = '+03:00'): array
{
    [$inner, $params] = adminMealsQuery($regions, $filters, $fallbackTz);
    $day = "DATE(local_at)";
    $sql = "SELECT region_key, employee_id, full_name, organization, department,
                   COUNT(DISTINCT CONCAT(meal_type, '_', {$day})) AS meals,
                   COUNT(DISTINCT CASE WHEN meal_type = 'breakfast' THEN {$day} END) AS breakfast,
                   COUNT(DISTINCT CASE WHEN meal_type = 'lunch'     THEN {$day} END) AS lunch,
                   COUNT(DISTINCT CASE WHEN meal_type = 'dinner'    THEN {$day} END) AS dinner,
                   COUNT(DISTINCT CASE WHEN meal_type = 'night'     THEN {$day} END) AS night,
                   COUNT(DISTINCT {$day}) AS days
            FROM ({$inner}) AS u
            GROUP BY region_key, employee_id, full_name, organization, department";
    return [$sql, $params];
}

/** Сводка «сколько приёмов пищи в каждом регионе по типам». */
function adminMealsSummaryQuery(array $regions, array $filters, string $fallbackTz = '+03:00'): array
{
    [$inner, $params] = adminMealsQuery($regions, $filters, $fallbackTz);
    $sql = "SELECT region_key, meal_type, COUNT(*) AS cnt, SUM(COALESCE(price,0)) AS total
            FROM ({$inner}) AS u
            GROUP BY region_key, meal_type";
    return [$sql, $params];
}

/** Выдача сухпаев и выездного питания по выбранным регионам. */
function adminRationsQuery(array $regions, array $filters): array
{
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['date_from'])) { $where[] = 'dr.issue_date >= ?'; $params[] = $filters['date_from']; }
    if (!empty($filters['date_to']))   { $where[] = 'dr.issue_date <= ?'; $params[] = $filters['date_to']; }
    if (!empty($filters['dry_type']))  { $where[] = 'dr.dry_type = ?';    $params[] = $filters['dry_type']; }
    if (!empty($filters['search'])) {
        $where[] = '(e.full_name LIKE ? OR e.organization LIKE ?)';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }

    $part = "SELECT {REGION} AS region_key,
                    dr.id AS ration_id, dr.issue_date, dr.dry_type, dr.status,
                    dr.created_at, e.full_name, e.organization, e.department
             FROM {DB}.dry_rations dr
             LEFT JOIN {DB}.employees e ON e.id = dr.employee_id
             WHERE " . implode(' AND ', $where);

    return adminUnionQuery($regions, $part, $params);
}

/** Человекочитаемое название приёма пищи. */
function adminMealLabel(?string $type): string
{
    return [
        'breakfast' => 'Завтрак',
        'lunch'     => 'Обед',
        'dinner'    => 'Ужин',
        'night'     => 'Ночное',
    ][$type] ?? (string)$type;
}
