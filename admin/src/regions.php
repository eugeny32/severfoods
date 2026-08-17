<?php
/**
 * Реестр регионов и сборка кросс-региональных запросов.
 *
 * Главное отличие от src/regions.php основной системы: список регионов
 * хранится в таблице, а не в коде. Добавление региона — строка в базе, без
 * правки PHP и без передеплоя существующих площадок.
 *
 * Второе отличие — способ получить данные. В регионах механизм подменяет
 * соединение целиком, поэтому объединить два региона в одном отчёте нельзя.
 * Здесь все базы лежат на одном MySQL, и запрос собирается через границы баз:
 *
 *     SELECT …, 'kyzyl' AS region FROM `u3523560_canteen`.meal_logs
 *     UNION ALL
 *     SELECT …, 'nrg'   AS region FROM `u3523560_canteen_ngr`.meal_logs
 *
 * Группировка, сортировка и постраничность остаются на стороне базы. Слияние
 * нескольких выборок в PHP этого не даёт и упирается в память на больших
 * периодах.
 */

declare(strict_types=1);

/**
 * Все регионы из реестра.
 *
 * @param bool $onlyActive выключенные регионы не участвуют в отчётах
 * @return array<string,array> ключ региона → строка реестра
 */
function adminRegions(PDO $pdo, bool $onlyActive = true): array
{
    static $cache = [];
    $ck = $onlyActive ? 'active' : 'all';
    if (isset($cache[$ck])) return $cache[$ck];

    $sql = 'SELECT r.*, c.name AS customer_name FROM regions r
            LEFT JOIN customers c ON c.id = r.customer_id';
    if ($onlyActive) $sql .= ' WHERE r.is_active = 1';
    $sql .= ' ORDER BY r.sort_order, r.label';

    $out = [];
    foreach ($pdo->query($sql) as $row) {
        $out[$row['region_key']] = $row;
    }
    return $cache[$ck] = $out;
}

/** Один регион по ключу или null. */
function adminRegion(PDO $pdo, string $key, bool $onlyActive = true): ?array
{
    return adminRegions($pdo, $onlyActive)[$key] ?? null;
}

/**
 * Имя базы региона, пригодное для подстановки в SQL.
 *
 * Идентификаторы нельзя передать через плейсхолдер, поэтому имя базы
 * подставляется в текст запроса — и обязано быть проверено. Пропускаем только
 * то, что вообще может быть именем базы MySQL; всё остальное — ошибка, а не
 * попытка «очистить» строку.
 */
function adminQuoteDb(string $dbName): string
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
        throw new InvalidArgumentException("Недопустимое имя базы данных: «{$dbName}»");
    }
    return '`' . $dbName . '`';
}

/**
 * Отдельное соединение к базе региона — нужно для ЗАПИСИ в конкретный регион
 * (второй этап) и для проверок доступности.
 *
 * Если реквизиты в реестре не заданы, регион лежит на том же сервере, что и
 * админка, и используется её пользователь — самый частый случай.
 */
function adminRegionPdo(PDO $adminPdo, array $region): PDO
{
    static $cache = [];
    $key = $region['region_key'];
    if (isset($cache[$key])) return $cache[$key];

    // Имя базы проверяем и здесь. Раньше оно уходило в строку подключения как
    // есть, и незаполненный заполнитель из заготовки реестра доходил до MySQL,
    // возвращая «Access denied» — сообщение, по которому невозможно понять,
    // что на самом деле надо просто вписать имя базы.
    adminQuoteDb((string)$region['db_name']);

    $host = $region['db_host'] ?: ADMIN_DB_HOST;
    $user = $region['db_user'] ?: ADMIN_DB_USER;
    $pass = $region['db_pass'] !== null && $region['db_pass'] !== '' ? $region['db_pass'] : ADMIN_DB_PASS;

    try {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$region['db_name']};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        throw new RuntimeException(adminDbErrorHint($e, (string)$region['db_name'], $user), 0, $e);
    }
    $pdo->exec("SET time_zone = '+00:00'");
    return $cache[$key] = $pdo;
}

/**
 * Понятное объяснение вместо сырой ошибки MySQL.
 *
 * Отказ в доступе (1044/1045) — не поломка кода, а незаданные права на
 * хостинге, и человеку нужно сказать именно это, иначе он будет искать ошибку
 * в программе.
 */
function adminDbErrorHint(Throwable $e, string $dbName, string $user): string
{
    $m = $e->getMessage();

    if (str_contains($m, '1044') || str_contains($m, 'Access denied') && str_contains($m, 'to database')) {
        return "Пользователю «{$user}» не выданы права на базу «{$dbName}». "
             . 'Это настройка хостинга, а не программы: в панели управления базами данных '
             . 'привяжите этого пользователя к базе с полными правами. '
             . 'Сводные отчёты по всем регионам работают только тогда, когда один пользователь '
             . 'видит все базы.';
    }
    if (str_contains($m, '1045')) {
        return "Неверный пароль пользователя «{$user}» для базы «{$dbName}».";
    }
    if (str_contains($m, '1049')) {
        return "База «{$dbName}» не существует. Проверьте имя в реестре регионов.";
    }
    return $m;
}

/**
 * Собирает UNION ALL по выбранным регионам.
 *
 * $partSql — тело запроса с меткой {DB}, куда подставляется имя базы, и
 * {REGION} — ключ региона как строковый литерал. Параметры повторяются для
 * каждой части: плейсхолдеры позиционные, поэтому массив значений размножается
 * ровно столько раз, сколько регионов вошло в запрос.
 *
 * @param array<string,array> $regions выбранные регионы
 * @param string              $partSql тело одной части
 * @param array               $params  параметры одной части
 * @return array{0:string,1:array} готовый SQL и полный набор параметров
 */
function adminUnionQuery(array $regions, string $partSql, array $params = []): array
{
    if (!$regions) {
        throw new InvalidArgumentException('Не выбрано ни одного региона');
    }

    $parts = [];
    $all   = [];
    foreach ($regions as $key => $r) {
        $parts[] = str_replace(
            ['{DB}', '{REGION}'],
            [adminQuoteDb($r['db_name']), "'" . str_replace("'", "''", $key) . "'"],
            $partSql
        );
        foreach ($params as $p) $all[] = $p;
    }

    return ['(' . implode(")\nUNION ALL\n(", $parts) . ')', $all];
}

/**
 * Регионы, выбранные в фильтре. Пустой или отсутствующий выбор означает «все»
 * — так сводный отчёт открывается сразу, без лишнего щелчка.
 *
 * @param string[]|null $selected ключи из формы
 */
function adminSelectedRegions(PDO $pdo, ?array $selected): array
{
    $all = adminRegions($pdo);
    if (!$selected) return $all;
    $out = [];
    foreach ($selected as $key) {
        if (isset($all[$key])) $out[$key] = $all[$key];
    }
    return $out ?: $all;
}
