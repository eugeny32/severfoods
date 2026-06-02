<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

checkAuth();

$user_role          = $_SESSION['role']             ?? 'operator';
$user_name          = $_SESSION['user_name']        ?? 'Пользователь';
$is_admin           = $_SESSION['is_admin']         ?? false;
$is_super_admin     = $user_role === 'super_admin';
$meal_point_id      = $_SESSION['meal_point_id']    ?? null;
$meal_point_name    = $_SESSION['meal_point_name']  ?? 'Не выбрана';
$assigned_point_id  = $_SESSION['assigned_point_id'] ?? null;
$assigned_point_name = null;

if ($assigned_point_id) {
    $ap = getMealPointById($pdo, $assigned_point_id);
    $assigned_point_name = $ap['point_name'] ?? null;
}

// Выход
if (isset($_GET['logout'])) logout();

// Flash-сообщение (устанавливается через сессию, например после удаления)
$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// AJAX — сканирование QR (CSRF проверяется)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_data']) && isAjax()) {
    Csrf::guard();
    $result = processAccess($pdo, trim($_POST['qr_data']), $_SERVER['REMOTE_ADDR'] ?? null);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─ Сбор данных для страницы ─
$current_meal      = getCurrentMealType($pdo, $meal_point_id);
$current_meal_name = getMealTypeName($current_meal);
$schedule_today    = $meal_point_id ? getPointScheduleInfo($pdo, $meal_point_id) : [];

// Статистика
if ($is_super_admin) {
    $stats = getTodayStats($pdo);
    $stats_title = 'Всего сегодня';
} elseif ($is_admin && $assigned_point_id) {
    $stats = getPointTodayStats($pdo, $assigned_point_id);
    $stats_title = 'По точке ' . htmlspecialchars($assigned_point_name ?? '');
} else {
    $stats = getPointTodayStats($pdo, $meal_point_id ?: null) ?: getTodayStats($pdo);
    $stats_title = $meal_point_name !== 'Не выбрана' ? htmlspecialchars($meal_point_name) : 'Сегодня';
}

// Сотрудники
$allEmployees = getEmployees($pdo);
$expiringEmps = getExpiringEmployees($pdo, 7);
$vjgList      = getVjgList($pdo);
$orgStats     = $pdo->query(
    "SELECT TRIM(organization) as organization, COUNT(*) as cnt FROM employees WHERE is_active=1 GROUP BY TRIM(organization) ORDER BY TRIM(organization)"
)->fetchAll();

// Точки и статистика по ним
$points      = [];
$allPointStats = [];
if ($is_admin) {
    if ($is_super_admin) {
        $points = getMealPoints($pdo);
        $allPointStats = getAllPointsStats($pdo);
    } elseif ($assigned_point_id) {
        $pt = getMealPointById($pdo, $assigned_point_id);
        if ($pt) $points = [$pt];
        $allPointStats = getAllPointsStats($pdo);
    } else {
        $points = getMealPoints($pdo);
        $allPointStats = getAllPointsStats($pdo);
    }
}

// Недельная статистика и топ
$weeklyStats  = [];
$topEmployees = [];
if ($is_admin) {
    $weeklyStats  = getWeeklyStats($pdo, $assigned_point_id && !$is_super_admin ? $assigned_point_id : null);
    $topEmployees = getTopEmployees($pdo, 10, $assigned_point_id && !$is_super_admin ? $assigned_point_id : null);
}

// JSON для JS
$allEmployeesJson = array_map(function($e) {
    $today = date('Y-m-d');
    $expires = $e['qr_expires_at'];
    $expStatus = 'valid';
    if ($expires) {
        if ($expires < $today) $expStatus = 'expired';
        elseif ($expires < date('Y-m-d', strtotime('+7 days'))) $expStatus = 'warning';
    }
    return [
        'id'           => (int)$e['id'],
        'full_name'    => trim($e['full_name']),
        'birth_date'   => $e['birth_date'] ? date('d.m.Y', strtotime($e['birth_date'])) : '',
        'organization' => trim($e['organization']),
        'department'   => trim($e['department'] ?? ''),
        'vjg_type'     => trim($e['vjg_type'] ?? ''),
        'price'        => $e['price'],
        'qr_status'    => $e['qr_status'],
        'qr_expires_at'=> $expires ? date('d.m.Y', strtotime($expires)) : null,
        'expiry_status'=> $expStatus,
    ];
}, $allEmployees);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#003366">
<title><?= htmlspecialchars(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🍽️</text></svg>">
<link rel="manifest" href="manifest.json">
<?= Csrf::meta() ?>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/offline.css">
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js" defer></script>
</head>
<body>

<!-- ════════════════ HEADER ════════════════ -->
<header class="header">
    <div class="header-inner">
        <img src="logo.png" alt="Лого" class="header-logo"
            onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-chips">
            <span class="chip time" id="headerTime"></span>
            <span class="chip meal <?= $current_meal !== 'none' ? 'active' : 'none' ?>">
                <?= getMealTypeIcon($current_meal) ?> <?= $current_meal_name ?>
            </span>
            <?php if ($is_admin): ?>
            <span class="chip role-admin">👑 <?= htmlspecialchars($user_name) ?></span>
            <?php else: ?>
            <span class="chip">👤 <?= htmlspecialchars($user_name) ?></span>
            <?php endif; ?>
            <?php if ($meal_point_name && $meal_point_name !== 'Не выбрана'): ?>
            <span class="chip point">📍 <?= htmlspecialchars($meal_point_name) ?></span>
            <?php endif; ?>
            <span class="chip offline-indicator is-online" id="offlineChip" title="Статус соединения с сервером">🟢 Онлайн</span>
        </div>
        <div class="header-actions">
            <?php if ($is_admin): ?>
            <a href="chat.php" class="btn-logout" style="background:rgba(0,85,165,.15);color:var(--blue-400,#1a6fc4);border-color:rgba(0,85,165,.25)" title="Чат администраторов">
                💬 Чат
            </a>
            <?php endif; ?>
            <a href="?logout=1" class="btn-logout"
                onclick="return confirm('Выйти из системы?')">
                🚪 Выход
            </a>
        </div>
    </div>
</header>

<!-- Floating QR status indicator -->
<div id="qrStatusFloat">
    <div class="qsf-pill qsf-layout" id="qsfLayout" title="Символ сконвертирован из RU в EN">⌨ RU→EN</div>
    <div class="qsf-pill qsf-idle"   id="qsfIdle"   title="До автофокуса на поле QR"></div>
</div>

<!-- ════════════════ MAIN ════════════════ -->
<main class="page">

    <!-- Flash message -->
    <?php if ($flash): ?>
    <div class="notif <?= $flash['type'] ?>" style="margin-bottom:16px">
        <div class="notif-inner">
            <div class="notif-icon"><?= $flash['type']==='success'?'✅':'❌' ?></div>
            <div class="notif-body">
                <div class="notif-title"><?= htmlspecialchars($flash['msg']) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Offline Banner -->
    <div class="offline-banner" id="offlineBanner">
        📴 <strong>Режим офлайн</strong> — сканирования сохраняются локально и будут синхронизированы при восстановлении соединения.
    </div>

    <div class="grid-2">

        <!-- ══ SCAN PANEL ══ -->
        <div class="card scan-panel">
            <div class="card-header">
                <div class="card-title">📷 Сканирование QR-кода</div>
                <div class="scanner-toolbar">
                    <span class="scanner-pill on" id="scannerPill">
                        <span class="pulse-dot" id="scannerDot"></span>
                        <span id="scannerStatus">Активен</span>
                    </span>
                    <button class="btn-sm" onclick="toggleScanner()" title="Вкл/Выкл сканер">⚙️</button>
                    <button class="btn-camera" onclick="openCamera()">📷 Камера</button>
                </div>
            </div>

            <?php if (!$is_admin && $meal_point_name !== 'Не выбрана'): ?>
            <div class="point-banner">
                <div>
                    <div class="point-banner-name">📍 <?= htmlspecialchars($meal_point_name) ?></div>
                    <?php if (!empty($schedule_today)): ?>
                    <div class="point-schedule">
                        <?php foreach ($schedule_today as $s): ?>
                        <span class="schedule-pill">
                            <?= getMealTypeIcon($s['meal_type']) ?>
                            <?= htmlspecialchars($s['meal_name_ru'] ?: getMealTypeName($s['meal_type'])) ?>:
                            <?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <a href="login.php" class="btn-outline">🔄 Сменить</a>
            </div>
            <?php endif; ?>

            <form id="scanForm" method="POST" autocomplete="off">
                <div class="qr-label">
                    QR-код
                    <span class="mode-badge" id="modeBadge">🎯 Режим сканирования</span>
                </div>
                <div class="qr-field">
                    <input type="text" name="qr_data" id="qrInput"
                        class="qr-input scanner-active"
                        placeholder="Наведите сканер на QR-код…"
                        autofocus required>
                </div>
                <button type="submit" class="btn btn-primary" id="manualBtn"
                    style="display:none;width:100%">🔍 Проверить вручную</button>
            </form>

            <!-- Notification -->
            <div id="notification" style="display:none"></div>

            <!-- Stats -->
            <div style="margin-top:20px">
                <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">
                    📊 <?= $stats_title ?>
                </div>
                <!-- Всего — отдельная строка над остальными -->
                <div class="stat-tile total" style="margin-bottom:10px;padding:10px 16px;display:flex;align-items:center;justify-content:space-between">
                    <div style="font-size:13px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px">Всего питаний сегодня</div>
                    <div class="stat-num" id="stat_total" style="font-size:36px"><?= $stats['total'] ?></div>
                </div>
                <!-- 4 плитки по типам -->
                <div class="stats-row">
                    <div class="stat-tile">
                        <div class="stat-num" id="stat_breakfast"><?= $stats['breakfast'] ?></div>
                        <div class="stat-lbl">🌅 Завтрак</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-num" id="stat_lunch"><?= $stats['lunch'] ?></div>
                        <div class="stat-lbl">☀️ Обед</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-num" id="stat_dinner"><?= $stats['dinner'] ?></div>
                        <div class="stat-lbl">🌙 Ужин</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-num" id="stat_night"><?= $stats['night'] ?></div>
                        <div class="stat-lbl">⭐ Ночное</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ EMPLOYEE PANEL ══ -->
        <div class="card list-panel">
            <div class="card-header">
                <div class="card-title">👥 Сотрудники (<?= count($allEmployees) ?>)</div>
                <?php if ($is_admin): ?>
                <button class="btn btn-primary" onclick="openAddModal()">➕ Добавить</button>
                <?php endif; ?>
            </div>

            <?php if (!empty($expiringEmps)): ?>
            <div class="expiring-block">
                <div class="expiring-header">⚠️ Истекающие QR-коды (7 дней)</div>
                <div class="expiring-list">
                    <?php foreach ($expiringEmps as $emp):
                        $exp = date('d.m.Y', strtotime($emp['qr_expires_at']));
                        $isExp = $emp['qr_expires_at'] < date('Y-m-d');
                    ?>
                    <div class="expiring-row <?= $isExp ? 'expired' : '' ?>">
                        <span class="expiring-name"><?= htmlspecialchars($emp['full_name']) ?></span>
                        <span class="expiring-date"><?= $exp ?></span>
                        <div style="display:flex;gap:6px;flex-shrink:0">
                            <button class="btn-sm green" title="Ручной пропуск"
                                onclick="openManualModal(<?= $emp['id'] ?>,'<?= htmlspecialchars(addslashes($emp['full_name'])) ?>')">🚪</button>
                            <?php if ($is_admin): ?>
                            <button class="btn-sm" title="Редактировать"
                                onclick="openEditModal(<?= $emp['id'] ?>)">✏️</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Search -->
            <div class="search-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" class="search-input"
                    placeholder="Поиск по ФИО, организации, отделу…"
                    autocomplete="off">
                <button class="search-clear" id="searchClear">✕</button>
            </div>

            <!-- Org chips -->
            <div class="org-grid" id="orgChips"></div>

            <!-- Employee table -->
            <div id="empTableWrap" style="display:none">
                <div class="emp-table-wrap">
                    <table class="emp-table">
                        <thead>
                            <tr>
                                <th>Сотрудник</th>
                                <th>QR-статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody id="empTbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ADMIN PANEL ══ -->
    <?php if ($is_admin): ?>
    <div class="admin-panel">
        <div class="tab-nav">
            <button class="tab-btn active" data-tab="tabReports">📊 Отчёты</button>
            <button class="tab-btn" data-tab="tabPoints">📍 Точки</button>
            <button class="tab-btn" data-tab="tabStats">📈 Статистика</button>
            <?php if ($is_admin): ?>
            <button class="tab-btn" data-tab="tabSchedule">⏰ Расписание</button>
            <?php endif; ?>
            <button class="tab-btn" data-tab="tabQrPrint">🖨️ Печать QR</button>
            <button class="tab-btn" data-tab="tabOffline">📴 Офлайн <span class="queue-badge" id="offlineQueueBadge" style="display:none">0</span></button>
        </div>

        <!-- REPORTS -->
        <div id="tabReports" class="tab-pane active">
            <form method="GET" action="reports.php" target="_blank" class="report-form">
                <div class="form-group">
                    <label>📅 Дата от</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
                </div>
                <div class="form-group">
                    <label>📅 Дата до</label>
                    <input type="date" name="end_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>🍽️ Тип питания</label>
                    <select name="meal_type">
                        <option value="all">Все</option>
                        <option value="breakfast">Завтрак</option>
                        <option value="lunch">Обед</option>
                        <option value="dinner">Ужин</option>
                        <option value="night">Ночное</option>
                    </select>
                </div>
                <?php if ($is_super_admin): ?>
                <div class="form-group">
                    <label>📍 Точка</label>
                    <select name="point_id">
                        <option value="">Все точки</option>
                        <?php foreach ($points as $pt): ?>
                        <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['point_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php elseif ($assigned_point_id): ?>
                <input type="hidden" name="point_id" value="<?= $assigned_point_id ?>">
                <?php endif; ?>
                <div class="form-group" style="justify-content:flex-end">
                    <label>&nbsp;</label>
                    <div style="display:flex;gap:8px">
                        <button type="submit" class="btn btn-primary">📄 Показать</button>
                        <a id="excelExportLink" href="#" onclick="openExcelExport(event)" class="btn btn-success" style="background:#1d6f42">📊 Excel</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- POINTS -->
        <div id="tabPoints" class="tab-pane">
            <div style="font-size:13px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px">
                📍 Статистика по точкам сегодня
            </div>
            <?php if (!empty($allPointStats)): ?>
            <div class="points-grid">
                <?php foreach ($allPointStats as $ps): ?>
                <div class="point-card">
                    <div class="point-card-name"><?= htmlspecialchars($ps['point_name']) ?></div>
                    <div class="point-card-city"><?= htmlspecialchars($ps['city'] ?? '') ?> · <?= htmlspecialchars($ps['point_code'] ?? '') ?></div>
                    <div class="point-card-count"><?= $ps['today_count'] ?></div>
                    <div class="point-card-sub">питаний сегодня</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty"><div class="empty-icon">📍</div>Нет данных за сегодня</div>
            <?php endif; ?>

            <?php if ($is_super_admin): ?>
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
                <div style="font-size:13px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px">
                    ⚙️ Управление точками
                </div>
                <a href="meal_points.php" class="btn btn-primary">🏗️ Управление точками</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- STATS -->
        <div id="tabStats" class="tab-pane">
            <div class="stats-cols">
                <div class="stats-block">
                    <h4>📊 Питания по дням недели (7 дней)</h4>
                    <div class="week-chart">
                        <?php
                        $maxW = max(array_values($weeklyStats) ?: [1]);
                        foreach ($weeklyStats as $day => $cnt):
                            $pct = $maxW > 0 ? round($cnt / $maxW * 100) : 0;
                        ?>
                        <div class="week-row">
                            <span class="week-day"><?= $day ?></span>
                            <div class="week-bar-wrap">
                                <div class="week-bar" style="width:<?= max($pct,2) ?>%">
                                    <?= $pct > 15 ? $cnt : '' ?>
                                </div>
                            </div>
                            <span class="week-val"><?= $cnt ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="stats-block">
                    <h4>🏆 Активные сотрудники (30 дней)</h4>
                    <?php if (empty($topEmployees)): ?>
                    <div class="empty"><div class="empty-icon">🏆</div>Нет данных</div>
                    <?php else: ?>
                    <div class="top-list">
                        <?php foreach ($topEmployees as $i => $emp):
                            $rankClass = $i===0?'gold':($i===1?'silver':($i===2?'bronze':''));
                        ?>
                        <div class="top-row">
                            <span class="top-rank <?= $rankClass ?>"><?= $i+1 ?></span>
                            <div style="flex:1">
                                <div class="top-name"><?= htmlspecialchars($emp['full_name']) ?></div>
                                <div class="top-org"><?= htmlspecialchars($emp['organization']) ?></div>
                            </div>
                            <span class="top-count"><?= $emp['meals_count'] ?> раз</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- SCHEDULE TAB -->
        <?php if ($is_admin): ?>
        <div id="tabSchedule" class="tab-pane">
            <div class="schedule-tab-inner">
                <div class="schedule-top-bar">
                    <div class="form-group" style="flex:0 0 280px;min-width:200px">
                        <label>📍 Точка питания</label>
                        <select id="schedulePointTab" onchange="loadScheduleTab()">
                            <option value="">— Выберите точку —</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;align-items:flex-end">
                        <button class="btn btn-secondary" id="addScheduleRowTab">➕ Добавить приём</button>
                        <button class="btn btn-primary"   id="saveScheduleTab">💾 Сохранить</button>
                    </div>
                </div>
                <div id="scheduleTabMsg"></div>
                <div id="scheduleTabEmpty" style="display:none">
                    <div class="empty"><div class="empty-icon">⏰</div>Выберите точку питания для редактирования расписания</div>
                </div>
                <div id="scheduleTabTableWrap" style="display:none;margin-top:16px;overflow-x:auto">
                    <table class="schedule-table" style="min-width:680px">
                        <thead>
                            <tr>
                                <th style="width:100px">Тип</th>
                                <th style="width:120px">Название</th>
                                <th style="width:90px">Начало</th>
                                <th style="width:90px">Конец</th>
                                <th>Дни недели</th>
                                <th style="width:60px">Порядок</th>
                                <th style="width:40px"></th>
                            </tr>
                        </thead>
                        <tbody id="scheduleRowsTab"></tbody>
                    </table>
                </div>
                <div id="scheduleTabLoading" style="display:none">
                    <div class="empty"><div class="empty-icon">⏳</div>Загрузка расписания…</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- QR PRINT -->
        <div id="tabQrPrint" class="tab-pane">
            <div style="margin-bottom:16px;color:var(--text-2);font-size:13px">
                Распечатайте QR-коды для сотрудников. Вы можете напечатать все QR сразу или по одному.
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <a href="print_all_qr.php" target="_blank" class="btn btn-primary">🖨️ Печать всех QR-кодов</a>
            </div>
        </div>

        <!-- OFFLINE TAB -->
        <div id="tabOffline" class="tab-pane">
            <div class="offline-stats-grid">
                <div class="offline-stat-card">
                    <div class="offline-stat-label">Соединение</div>
                    <div class="offline-stat-val" id="offlineStatOnline">—</div>
                </div>
                <div class="offline-stat-card">
                    <div class="offline-stat-label">В очереди</div>
                    <div class="offline-stat-val" id="offlineStatQueue">0</div>
                </div>
                <div class="offline-stat-card">
                    <div class="offline-stat-label">Сотрудников в кэше</div>
                    <div class="offline-stat-val" id="offlineStatEmps">0</div>
                </div>
                <div class="offline-stat-card">
                    <div class="offline-stat-label">Последняя синхронизация</div>
                    <div class="offline-stat-val offline-stat-time" id="offlineStatLastSync">—</div>
                </div>
                <div class="offline-stat-card">
                    <div class="offline-stat-label">Кэш обновлён</div>
                    <div class="offline-stat-val offline-stat-time" id="offlineStatEmpCached">—</div>
                </div>
            </div>

            <div class="offline-actions">
                <button class="btn btn-primary" id="offlineSyncBtn" onclick="window.OfflineManager && window.OfflineManager.manualSync()">
                    🔄 Синхронизировать очередь
                </button>
                <button class="btn btn-secondary" id="offlineRefreshBtn" onclick="window.OfflineManager && window.OfflineManager.manualRefreshEmployees()">
                    📥 Обновить кэш сотрудников
                </button>
            </div>
            <div id="offlineSyncMsg" style="margin-top:8px;font-size:13px;color:var(--text-2)"></div>

            <div style="margin-top:20px">
                <div style="font-size:13px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">
                    📋 Очередь офлайн-сканирований
                </div>
                <div class="queue-table-wrap" style="overflow-x:auto">
                    <table class="queue-table">
                        <thead>
                            <tr>
                                <th>Время</th>
                                <th>Сотрудник</th>
                                <th>QR-код</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody id="offlineQueueTbody">
                            <tr><td colspan="4" style="text-align:center;color:var(--text-3);padding:20px">Очередь пуста</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="margin-top:20px;padding:14px 16px;background:var(--bg-2,#f8f9fa);border-radius:8px;border:1px solid var(--border);font-size:13px;color:var(--text-2);line-height:1.7">
                <strong>ℹ️ Как работает офлайн-режим:</strong><br>
                • При отсутствии интернета сканирования QR-кодов сохраняются локально в браузере.<br>
                • Сотрудники кэшируются заранее — нажмите «Обновить кэш» для загрузки актуального списка.<br>
                • При восстановлении соединения очередь синхронизируется автоматически.<br>
                • Для принудительной синхронизации нажмите «Синхронизировать очередь».<br>
                • Кэш и очередь хранятся в IndexedDB браузера и не исчезают при перезагрузке страницы.
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- ════════════════ MODALS ════════════════ -->

<!-- Schedule Modal -->
<div class="modal-overlay" id="scheduleModal">
    <div class="modal-box wide">
        <div class="modal-header">
            <div class="modal-title">⏰ Расписание питания</div>
            <button class="modal-close" onclick="closeModal('scheduleModal')">✕</button>
        </div>
        <?php if (!empty($points)): ?>
        <div class="form-group" style="margin-bottom:16px">
            <label>📍 Точка питания</label>
            <select id="schedulePoint">
                <?php foreach ($points as $pt): ?>
                <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['point_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="overflow-x:auto;max-height:400px;overflow-y:auto">
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th>Тип</th>
                        <th>Название</th>
                        <th>Начало</th>
                        <th>Конец</th>
                        <th>Дни недели</th>
                        <th>Порядок</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="scheduleRows"></tbody>
            </table>
        </div>
        <button class="btn btn-secondary" id="addScheduleRow" style="margin-top:10px">➕ Добавить приём пищи</button>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('scheduleModal')">Отмена</button>
            <button class="btn btn-primary" id="saveSchedule">💾 Сохранить</button>
        </div>
        <div id="scheduleMsg"></div>
        <?php else: ?>
        <div class="empty"><div class="empty-icon">📍</div>Нет доступных точек питания</div>
        <?php endif; ?>
    </div>
</div>

<!-- Employee Modal -->
<div class="modal-overlay" id="empModal">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-header">
            <div class="modal-title" id="empModalTitle">➕ Добавление сотрудника</div>
            <button class="modal-close" onclick="closeModal('empModal')">✕</button>
        </div>
        <form id="empForm">
            <input type="hidden" id="editId">
            <div class="form-grid">
                <div class="form-group">
                    <label>ФИО *</label>
                    <input type="text" id="empFullName" required placeholder="Иванов Иван Иванович">
                </div>
                <div class="form-group">
                    <label>Дата рождения *</label>
                    <input type="date" id="empBirthDate" required>
                </div>
                <div class="form-group">
                    <label>Организация *</label>
                    <input type="text" id="empOrg" required placeholder="ООО Компания">
                </div>
                <div class="form-group">
                    <label>Отдел</label>
                    <input type="text" id="empDept" placeholder="Производство">
                </div>
                <div class="form-group">
                    <label>Должность</label>
                    <input type="text" id="empPos" placeholder="Инженер">
                </div>
                <div class="form-group">
                    <label>ВЖГ</label>
                    <select id="empVjg">
                        <option value="">— Выберите ВЖГ —</option>
                        <?php foreach ($vjgList as $vjg): ?>
                        <option value="<?= htmlspecialchars($vjg['vjg_code']) ?>"
                            data-price="<?= $vjg['price'] ?>">
                            <?= htmlspecialchars($vjg['vjg_name']) ?>
                            (<?= htmlspecialchars($vjg['vjg_code']) ?>)
                            — <?= number_format($vjg['price'],0,'.',' ') ?> ₽
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Цена (₽)</label>
                    <input type="number" id="empPrice" step="0.01" min="0" placeholder="0">
                </div>
                <div class="form-group">
                    <label>Срок действия QR</label>
                    <input type="date" id="empExpires">
                </div>
                <div class="form-group">
                    <label>Статус QR</label>
                    <select id="empQrStatus">
                        <option value="active">✅ Активен</option>
                        <option value="expired">⏰ Просрочен</option>
                        <option value="blocked">🔒 Заблокирован</option>
                    </select>
                </div>
                <?php if ($is_admin): ?>
                <div class="form-group">
                    <label>Роль (для входа)</label>
                    <select id="empRole">
                        <option value="">— Не назначена —</option>
                        <option value="operator">👤 Оператор</option>
                        <?php if ($is_super_admin): ?>
                        <option value="admin">👑 Администратор</option>
                        <option value="super_admin">⭐ Супер-администратор</option>
                        <?php endif; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="checkbox-row">
                    <input type="checkbox" id="empIsActive" checked>
                    <label for="empIsActive">Сотрудник активен (имеет доступ)</label>
                </div>
                <div class="checkbox-row" id="regenerateQrRow" style="display:none">
                    <input type="checkbox" id="regenerateQr">
                    <label for="regenerateQr">🔄 Перегенерировать QR-код</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('empModal')">Отмена</button>
                <button type="submit" class="btn btn-primary">💾 Сохранить</button>
            </div>
            <div id="empMsg"></div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">🗑️ Удаление сотрудника</div>
            <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
        </div>
        <p style="font-size:14px;color:var(--text-2);line-height:1.6">
            Вы действительно хотите удалить сотрудника
            <strong id="deleteEmpName"></strong>?
            Это действие нельзя отменить.
        </p>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('deleteModal')">Отмена</button>
            <button class="btn btn-danger" id="confirmDeleteBtn">Да, удалить</button>
        </div>
    </div>
</div>

<!-- Manual Pass Modal -->
<div class="modal-overlay" id="manualModal">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title">🚪 Ручной пропуск</div>
            <button class="modal-close" onclick="closeModal('manualModal')">✕</button>
        </div>
        <p style="font-size:14px;color:var(--text-2);margin-bottom:16px">
            Пропустить сотрудника <strong id="manualEmpName"></strong> без сканирования QR-кода
        </p>
        <div class="form-grid" style="grid-template-columns:1fr">
            <div class="form-group">
                <label>Тип питания</label>
                <select id="manualMealType">
                    <option value="breakfast">🌅 Завтрак</option>
                    <option value="lunch" selected>☀️ Обед</option>
                    <option value="dinner">🌙 Ужин</option>
                    <option value="night">⭐ Ночное</option>
                </select>
            </div>
            <div class="form-group">
                <label>Причина (необязательно)</label>
                <input type="text" id="manualReason" placeholder="Например: QR-код повреждён">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('manualModal')">Отмена</button>
            <button class="btn btn-success" id="confirmManualBtn">✅ Пропустить</button>
        </div>
    </div>
</div>

<!-- Org Employees Modal -->
<div class="modal-overlay" id="orgModal">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-header">
            <div class="modal-title" id="orgModalTitle">👥 Сотрудники</div>
            <button class="modal-close" onclick="closeModal('orgModal')">✕</button>
        </div>
        <div id="orgModalBody" style="overflow-y:auto;max-height:60vh">
            <div class="empty"><div class="empty-icon">⏳</div>Загрузка…</div>
        </div>
    </div>
</div>

<!-- Camera Overlay -->
<div class="camera-overlay" id="cameraOverlay">
    <div style="color:white;font-size:18px;font-weight:700;margin-bottom:12px">📷 Сканирование QR-кода</div>
    <div class="camera-frame">
        <video id="cameraVideo" playsinline autoplay muted style="display:block;width:min(90vw,420px)"></video>
    </div>
    <div class="camera-hint">Наведите камеру на QR-код</div>
    <button class="camera-close" onclick="closeCamera()">✕ Закрыть камеру</button>
</div>

<!-- JS Data -->
<script>
(function(){
    window.isAdmin          = <?= json_encode($is_admin) ?>;
    window.isSuperAdmin     = <?= json_encode($is_super_admin) ?>;
    window.allEmployeesData = <?= json_encode($allEmployeesJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.orgStats         = <?= json_encode($orgStats,         JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.points           = <?= json_encode($points,           JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.mealPointId      = <?= json_encode($meal_point_id) ?>;
})();
</script>
<script src="assets/js/offline.js" defer></script>
<script src="assets/js/qr-input.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
