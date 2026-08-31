<?php
/**
 * Реестр регионов для кросс-регионального просмотра ОТЧЁТОВ супер-
 * администратором (см. reports.php). Каждый регион — отдельный сайт со
 * своей БД (см. манифест инфраструктуры в разговоре с администратором):
 * «Кызыл» (основной, severfoods.ru) и «Нерюнгри» (новый, nrg.severfoods.ru).
 *
 * Важно: это ТОЛЬКО про чтение отчётов. Ни один из мутирующих сценариев
 * (сканирование, ручной пропуск, массовая проводка, назначение точки,
 * нормализация БД и т.д.) НЕ использует этот механизм — они всегда
 * работают с $pdo текущего деплоя (текущего региона), как и раньше.
 * Так минимизирован риск случайно записать что-то не в ту базу.
 */

/**
 * ВРЕМЕННО ВЫКЛЮЧЕНО: переключение отчёта на чужой регион.
 *
 * Сводные и порегиональные отчёты по всем площадкам теперь строит central
 * admin (admin.severfoods.ru) — там реестр регионов лежит в таблице, а не в
 * коде, и запрос идёт через границы баз одним соединением. Держать на каждой
 * площадке второй, независимый механизм с реквизитами чужих баз в её .env —
 * это лишние копии секретов и вторая логика, которая обязательно разъедется
 * с первой.
 *
 * Пока переключатель выключен, отчёт площадки всегда показывает ТОЛЬКО свой
 * регион. Механизм не удалён: чтобы вернуть его, достаточно вернуть здесь
 * true — реквизиты в .env и весь код на месте.
 */
function crossRegionEnabled(): bool
{
    return false;
}

/** Все известные регионы: ключ → человекочитаемая метка + префикс переменных .env для доступа к "чужой" БД. */
function getRegions(): array
{
    return [
        'kyzyl' => ['label' => 'Кызыл',    'env_prefix' => 'KYZYL_DB'],
        'nrg'   => ['label' => 'Нерюнгри', 'env_prefix' => 'NRG_DB'],
    ];
}

/**
 * Регион ЭТОГО деплоя — определяется переменной REGION_KEY в .env
 * (kyzyl|nrg). Если не задана — считаем, что это исходный деплой (Кызыл),
 * чтобы существующий .env на проде можно было вообще не трогать.
 */
function currentRegionKey(): string
{
    $key = env('REGION_KEY', 'kyzyl');
    return isset(getRegions()[$key]) ? $key : 'kyzyl';
}

/**
 * PDO-соединение к БД указанного региона — для отчётов супер-администратора.
 * Для "своего" региона возвращает переданное основное соединение (не плодит
 * лишних подключений). Для "чужого" — открывает отдельное read-соединение
 * по реквизитам {PREFIX}_HOST/_NAME/_USER/_PASS из .env; если они не заданы,
 * бросает понятную ошибку вместо того, чтобы молча показать не те данные.
 */
function getRegionalPdo(string $regionKey, PDO $defaultPdo): PDO
{
    static $cache = [];

    if ($regionKey === currentRegionKey()) return $defaultPdo;
    if (isset($cache[$regionKey])) return $cache[$regionKey];

    $regions = getRegions();
    if (!isset($regions[$regionKey])) {
        throw new InvalidArgumentException("Неизвестный регион: {$regionKey}");
    }

    $prefix = $regions[$regionKey]['env_prefix'];
    $host   = env("{$prefix}_HOST");
    $name   = env("{$prefix}_NAME");
    $user   = env("{$prefix}_USER");
    $pass   = env("{$prefix}_PASS");

    if (!$host || !$name || !$user) {
        throw new RuntimeException(
            "Доступ к региону «{$regions[$regionKey]['label']}» не настроен " .
            "(добавьте {$prefix}_HOST/_NAME/_USER/_PASS в .env этого деплоя)"
        );
    }

    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Та же честная UTC-дисциплина, что и в src/bootstrap.php для основного $pdo.
    $pdo->exec("SET time_zone = '+00:00'");

    return $cache[$regionKey] = $pdo;
}
