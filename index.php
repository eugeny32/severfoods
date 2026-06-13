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

// Чат-пользователи (только для суперадмина)
$chatUsers = [];
if ($is_super_admin) {
    try {
        $chatUsers = $pdo->query(
            "SELECT id, full_name, chat_username, chat_access, is_active
             FROM employees
             WHERE COALESCE(chat_access,0)=1 AND role IS NULL
             ORDER BY full_name"
        )->fetchAll();
    } catch (PDOException $e) { $chatUsers = []; }
}
$expiringEmps = getExpiringEmployees($pdo, 7);
$vjgList      = getVjgList($pdo);
$mealPointsList = getMealPoints($pdo, true); // active only
$orgStats     = $pdo->query(
    "SELECT TRIM(organization) as organization, COUNT(*) as cnt FROM employees WHERE is_active=1 AND NOT (COALESCE(chat_access,0)=1 AND role IS NULL) GROUP BY TRIM(organization) ORDER BY TRIM(organization)"
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
<meta name="theme-color" content="#002756">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Питание">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/img/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="favicon.ico">
<title><?= htmlspecialchars(APP_NAME) ?></title>
<?= Csrf::meta() ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
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
            <span class="chip role-admin"><i class="fas fa-crown"></i> <?= htmlspecialchars($user_name) ?></span>
            <?php else: ?>
            <span class="chip"><i class="fas fa-user"></i> <?= htmlspecialchars($user_name) ?></span>
            <?php endif; ?>
            <?php if ($meal_point_name && $meal_point_name !== 'Не выбрана'): ?>
            <span class="chip point"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($meal_point_name) ?></span>
            <?php endif; ?>
        </div>
        <div class="header-actions">
            <?php if ($is_admin): ?>
            <a href="chat.php" class="btn-logout" id="chatNavBtn" style="background:rgba(0,85,165,.15);color:var(--blue-400,#1a6fc4);border-color:rgba(0,85,165,.25);position:relative" title="Чат администраторов">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="display:inline;vertical-align:middle;margin-right:5px"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Чат
                <span id="chatUnreadBadge" style="display:none;position:absolute;top:-6px;right:-6px;background:#e53e3e;color:#fff;font-size:11px;font-weight:700;min-width:18px;height:18px;border-radius:9px;padding:0 4px;line-height:18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.25)"></span>
            </a>
            <?php endif; ?>
            <a href="?logout=1" class="btn-logout"
                onclick="return confirm('Выйти из системы?')">
                <i class="fas fa-sign-out-alt"></i> Выход
            </a>
        </div>
    </div>
</header>

<!-- Floating QR status indicator -->
<div id="qrStatusFloat">
    <div class="qsf-pill qsf-layout" id="qsfLayout" title="Символ сконвертирован из RU в EN"><i class="fas fa-keyboard"></i> RU→EN</div>
    <div class="qsf-pill qsf-idle"   id="qsfIdle"   title="До автофокуса на поле QR"></div>
</div>

<!-- ════════════════ MAIN ════════════════ -->
<main class="page">

    <!-- Flash message -->
    <?php if ($flash): ?>
    <div class="notif <?= $flash['type'] ?>" style="margin-bottom:16px">
        <div class="notif-inner">
            <div class="notif-icon"><?= $flash['type']==='success'?'<i class="fas fa-check-circle"></i>':'<i class="fas fa-times-circle"></i>' ?></div>
            <div class="notif-body">
                <div class="notif-title"><?= htmlspecialchars($flash['msg']) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid-2">

        <!-- ══ SCAN PANEL ══ -->
        <div class="card scan-panel">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-camera"></i> Сканирование QR-кода</div>
                <div class="scanner-toolbar">
                    <span class="scanner-pill on" id="scannerPill">
                        <span class="pulse-dot" id="scannerDot"></span>
                        <span id="scannerStatus">Активен</span>
                    </span>
                    <button class="btn-sm" onclick="toggleScanner()" title="Вкл/Выкл сканер"><i class="fas fa-cog"></i></button>
                    <button class="btn-camera" onclick="openCamera()"><i class="fas fa-camera"></i> Камера</button>
                </div>
            </div>

            <?php if (!$is_admin && $meal_point_name !== 'Не выбрана'): ?>
            <div class="point-banner">
                <div>
                    <div class="point-banner-name"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($meal_point_name) ?></div>
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
                <a href="login.php" class="btn-outline"><i class="fas fa-sync-alt"></i> Сменить</a>
            </div>
            <?php endif; ?>

            <form id="scanForm" method="POST" autocomplete="off">
                <div class="qr-label">
                    QR-код
                    <span class="mode-badge" id="modeBadge"><i class="fas fa-crosshairs"></i> Режим сканирования</span>
                </div>
                <div class="qr-field">
                    <input type="text" name="qr_data" id="qrInput"
                        class="qr-input scanner-active"
                        placeholder="Наведите сканер на QR-код…"
                        autofocus required>
                </div>
                <button type="submit" class="btn btn-primary" id="manualBtn"
                    style="display:none;width:100%"><i class="fas fa-search"></i> Проверить вручную</button>
            </form>

            <!-- Notification -->
            <div id="notification" style="display:none"></div>

            <!-- Stats -->
            <div style="margin-top:20px">
                <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">
                    <i class="fas fa-chart-bar"></i> <?= $stats_title ?>
                </div>
                <!-- Всего — отдельная строка над остальными -->
                <div class="stat-tile total" style="margin-bottom:10px;padding:10px 16px;display:flex;align-items:center;justify-content:space-between">
                    <div style="font-size:13px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px">Всего питаний сегодня</div>
                    <div class="stat-num" id="stat_total" style="font-size:36px"><?= $stats['total'] ?></div>
                </div>
                <!-- 4 плитки по типам -->
                <div class="stats-row">
                    <div class="stat-tile">
                        <div class="stat-icon-row"><i class="fas fa-cloud-sun"></i></div>
                        <div class="stat-num" id="stat_breakfast"><?= $stats['breakfast'] ?></div>
                        <div class="stat-lbl">Завтрак</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-icon-row"><i class="fas fa-sun"></i></div>
                        <div class="stat-num" id="stat_lunch"><?= $stats['lunch'] ?></div>
                        <div class="stat-lbl">Обед</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-icon-row"><i class="fas fa-moon"></i></div>
                        <div class="stat-num" id="stat_dinner"><?= $stats['dinner'] ?></div>
                        <div class="stat-lbl">Ужин</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-icon-row"><i class="fas fa-star"></i></div>
                        <div class="stat-num" id="stat_night"><?= $stats['night'] ?></div>
                        <div class="stat-lbl">Ночное</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ EMPLOYEE PANEL ══ -->
        <div class="card list-panel">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-users"></i> Сотрудники (<?= count($allEmployees) ?>)</div>
                <?php if ($is_admin): ?>
                <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Добавить</button>
                <?php endif; ?>
            </div>

            <?php if (!empty($expiringEmps)): ?>
            <div class="expiring-block">
                <div class="expiring-header"><i class="fas fa-exclamation-triangle"></i> Истекающие QR-коды (7 дней)</div>
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
                                onclick="openManualModal(<?= $emp['id'] ?>,'<?= htmlspecialchars(addslashes($emp['full_name'])) ?>')"><i class="fas fa-sign-out-alt"></i></button>
                            <?php if ($is_admin): ?>
                            <button class="btn-sm" title="Редактировать"
                                onclick="openEditModal(<?= $emp['id'] ?>)"><i class="fas fa-pencil-alt"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Search -->
            <div class="search-wrap">
                <span class="search-icon"><i class="fas fa-search"></i></span>
                <input type="text" id="searchInput" class="search-input"
                    placeholder="Поиск по ФИО, организации, отделу…"
                    autocomplete="off">
                <button class="search-clear" id="searchClear"><i class="fas fa-times"></i></button>
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
            <button class="tab-btn active" data-tab="tabReports"><i class="fas fa-chart-bar"></i> Отчёты</button>
            <button class="tab-btn" data-tab="tabPoints"><i class="fas fa-map-marker-alt"></i> Точки</button>
            <button class="tab-btn" data-tab="tabStats"><i class="fas fa-chart-line"></i> Статистика</button>
            <?php if ($is_admin): ?>
            <button class="tab-btn" data-tab="tabSchedule"><i class="fas fa-clock"></i> Расписание</button>
            <?php endif; ?>
            <button class="tab-btn" data-tab="tabQrPrint"><i class="fas fa-print"></i> Печать QR</button>
            <?php if ($is_super_admin): ?>
            <button class="tab-btn" data-tab="tabChatUsers"><i class="fas fa-comments"></i> Пользователи чата</button>
            <?php endif; ?>
        </div>

        <!-- REPORTS -->
        <div id="tabReports" class="tab-pane active">
            <form method="GET" action="reports.php" target="_blank" class="report-form">
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Дата от</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Дата до</label>
                    <input type="date" name="end_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-utensils"></i> Тип питания</label>
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
                    <label><i class="fas fa-map-marker-alt"></i> Точка</label>
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
                        <button type="submit" class="btn btn-primary"><i class="fas fa-file-alt"></i> Показать</button>
                        <a id="excelExportLink" href="#" onclick="openExcelExport(event)" class="btn btn-success" style="background:#1d6f42"><i class="fas fa-chart-bar"></i> Excel</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- POINTS -->
        <div id="tabPoints" class="tab-pane">
            <div style="font-size:13px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px">
                <i class="fas fa-map-marker-alt"></i> Статистика по точкам сегодня
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
            <div class="empty"><div class="empty-icon"><i class="fas fa-map-marker-alt"></i></div>Нет данных за сегодня</div>
            <?php endif; ?>

            <?php if ($is_super_admin): ?>
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
                <div style="font-size:13px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px">
                    <i class="fas fa-cog"></i> Управление точками
                </div>
                <a href="meal_points.php" class="btn btn-primary"><i class="fas fa-tools"></i> Управление точками</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- STATS -->
        <div id="tabStats" class="tab-pane">
            <div class="stats-cols">
                <div class="stats-block">
                    <h4><i class="fas fa-chart-bar"></i> Питания по дням недели (7 дней)</h4>
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
                    <h4><i class="fas fa-trophy"></i> Активные сотрудники (30 дней)</h4>
                    <?php if (empty($topEmployees)): ?>
                    <div class="empty"><div class="empty-icon"><i class="fas fa-trophy"></i></div>Нет данных</div>
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
                        <label><i class="fas fa-map-marker-alt"></i> Точка питания</label>
                        <select id="schedulePointTab" onchange="loadScheduleTab()">
                            <option value="">— Выберите точку —</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;align-items:flex-end">
                        <button class="btn btn-secondary" id="addScheduleRowTab"><i class="fas fa-plus"></i> Добавить приём</button>
                        <button class="btn btn-primary"   id="saveScheduleTab"><i class="fas fa-save"></i> Сохранить</button>
                    </div>
                </div>
                <div id="scheduleTabMsg"></div>
                <div id="scheduleTabEmpty" style="display:none">
                    <div class="empty"><div class="empty-icon"><i class="fas fa-clock"></i></div>Выберите точку питания для редактирования расписания</div>
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
                    <div class="empty"><div class="empty-icon"><i class="fas fa-hourglass-half"></i></div>Загрузка расписания…</div>
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
                <a href="print_all_qr.php" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i> Печать всех QR-кодов</a>
            </div>
        </div>

        <?php if ($is_super_admin): ?>
        <!-- CHAT USERS -->
        <div id="tabChatUsers" class="tab-pane">
            <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <span style="color:var(--text-2);font-size:13px">Пользователи, зарегистрированные через чат-вход</span>
                <span style="font-size:13px;color:var(--text-2)"><?= count($chatUsers) ?> пользователей</span>
            </div>
            <?php if (empty($chatUsers)): ?>
            <div style="padding:20px;text-align:center;color:var(--text-2)">Нет чат-пользователей</div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="emp-table">
                <thead><tr>
                    <th>Имя</th>
                    <th>Логин чата</th>
                    <th>Статус</th>
                    <?php if ($is_super_admin): ?><th>Действия</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($chatUsers as $cu): ?>
                <tr id="cu-row-<?= $cu['id'] ?>">
                    <td><?= htmlspecialchars($cu['full_name']) ?></td>
                    <td><code style="font-size:12px">@<?= htmlspecialchars($cu['chat_username'] ?? '—') ?></code></td>
                    <td>
                        <span style="font-size:12px;padding:2px 8px;border-radius:10px;
                            background:<?= $cu['chat_access'] ? 'rgba(34,197,94,.15)' : 'rgba(239,68,68,.15)' ?>;
                            color:<?= $cu['chat_access'] ? '#22c55e' : '#ef4444' ?>">
                            <?= $cu['chat_access'] ? 'Активен' : 'Отключён' ?>
                        </span>
                    </td>
                    <?php if ($is_super_admin): ?>
                    <td>
                        <button class="btn-sm btn-danger" title="Удалить"
                            onclick="deleteChatUser(<?= $cu['id'] ?>, '<?= htmlspecialchars(addslashes($cu['full_name'])) ?>')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>
</main>

<!-- ════════════════ MODALS ════════════════ -->

<!-- Schedule Modal -->
<div class="modal-overlay" id="scheduleModal">
    <div class="modal-box wide">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-clock"></i> Расписание питания</div>
            <button class="modal-close" onclick="closeModal('scheduleModal')"><i class="fas fa-times"></i></button>
        </div>
        <?php if (!empty($points)): ?>
        <div class="form-group" style="margin-bottom:16px">
            <label><i class="fas fa-map-marker-alt"></i> Точка питания</label>
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
        <button class="btn btn-secondary" id="addScheduleRow" style="margin-top:10px"><i class="fas fa-plus"></i> Добавить приём пищи</button>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('scheduleModal')">Отмена</button>
            <button class="btn btn-primary" id="saveSchedule"><i class="fas fa-save"></i> Сохранить</button>
        </div>
        <div id="scheduleMsg"></div>
        <?php else: ?>
        <div class="empty"><div class="empty-icon"><i class="fas fa-map-marker-alt"></i></div>Нет доступных точек питания</div>
        <?php endif; ?>
    </div>
</div>

<!-- Employee Modal -->
<div class="modal-overlay" id="empModal">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-header">
            <div class="modal-title" id="empModalTitle"><i class="fas fa-plus"></i> Добавление сотрудника</div>
            <button class="modal-close" onclick="closeModal('empModal')"><i class="fas fa-times"></i></button>
        </div>
        <form id="empForm">
            <input type="hidden" id="editId">
            <div class="form-grid">
                <div class="form-group">
                    <label>ФИО *</label>
                    <input type="text" id="empFullName" required placeholder="Иванов Иван Иванович">
                </div>
                <div class="form-group">
                    <label>Дата рождения</label>
                    <input type="date" id="empBirthDate">
                </div>
                <div class="form-group">
                    <label>Организация *</label>
                    <input type="text" id="empOrg" required placeholder="ООО Компания" list="orgDatalist" autocomplete="off">
                    <datalist id="orgDatalist"></datalist>
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
                    <label>Вахтовый жилой городок</label>
                    <select id="empVjg">
                        <option value="">— Выберите ВЖГ —</option>
                        <?php foreach ($vjgList as $vjg): ?>
                        <option value="<?= htmlspecialchars($vjg['vjg_code']) ?>">
                            <?= htmlspecialchars($vjg['vjg_name']) ?>
                            (<?= htmlspecialchars($vjg['vjg_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Срок действия QR</label>
                    <input type="date" id="empExpires">
                </div>
                <div class="form-group">
                    <label>Статус QR</label>
                    <select id="empQrStatus">
                        <option value="active"><i class="fas fa-check-circle"></i> Активен</option>
                        <option value="expired"><i class="fas fa-clock"></i> Просрочен</option>
                        <option value="blocked"><i class="fas fa-lock"></i> Заблокирован</option>
                    </select>
                </div>
                <?php if ($is_admin): ?>
                <div class="form-group">
                    <label>Роль (для входа)</label>
                    <select id="empRole">
                        <option value="">— Не назначена —</option>
                        <option value="operator"><i class="fas fa-user"></i> Оператор</option>
                        <?php if ($is_super_admin): ?>
                        <option value="admin"><i class="fas fa-crown"></i> Администратор</option>
                        <option value="super_admin"><i class="fas fa-star"></i> Супер-администратор</option>
                        <?php endif; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group" id="pointSelectGroup" style="display:none">
                    <label>Точка питания</label>
                    <select id="empPointId">
                        <option value="">— Не назначена —</option>
                        <?php if ($is_super_admin): ?>
                        <?php foreach ($mealPointsList as $mp): ?>
                        <option value="<?= $mp['id'] ?>"><?= htmlspecialchars($mp['point_name']) ?> (<?= htmlspecialchars($mp['point_code']) ?>)</option>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <?php
                        $adminPoint = null;
                        foreach ($mealPointsList as $mp) {
                            if ($mp['id'] == ($assigned_point_id ?? 0)) { $adminPoint = $mp; break; }
                        }
                        if ($adminPoint): ?>
                        <option value="<?= $adminPoint['id'] ?>" selected><?= htmlspecialchars($adminPoint['point_name']) ?></option>
                        <?php endif; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" id="empIsActive" checked>
                    <label for="empIsActive">Сотрудник активен (имеет доступ)</label>
                </div>
                <div class="checkbox-row" id="regenerateQrRow" style="display:none">
                    <input type="checkbox" id="regenerateQr">
                    <label for="regenerateQr"><i class="fas fa-sync-alt"></i> Перегенерировать QR-код</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('empModal')">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Сохранить</button>
            </div>
            <div id="empMsg"></div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-trash"></i> Удаление сотрудника</div>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
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
            <div class="modal-title"><i class="fas fa-sign-out-alt"></i> Ручной пропуск</div>
            <button class="modal-close" onclick="closeModal('manualModal')"><i class="fas fa-times"></i></button>
        </div>
        <p style="font-size:14px;color:var(--text-2);margin-bottom:16px">
            Пропустить сотрудника <strong id="manualEmpName"></strong> без сканирования QR-кода
        </p>
        <div class="form-grid" style="grid-template-columns:1fr">
            <div class="form-group">
                <label>Тип питания</label>
                <select id="manualMealType">
                    <option value="breakfast"><i class="fas fa-cloud-sun"></i> Завтрак</option>
                    <option value="lunch" selected><i class="fas fa-sun"></i> Обед</option>
                    <option value="dinner"><i class="fas fa-moon"></i> Ужин</option>
                    <option value="night"><i class="fas fa-star"></i> Ночное</option>
                </select>
            </div>
            <div class="form-group">
                <label>Причина (необязательно)</label>
                <input type="text" id="manualReason" placeholder="Например: QR-код повреждён">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('manualModal')">Отмена</button>
            <button class="btn btn-success" id="confirmManualBtn"><i class="fas fa-check-circle"></i> Пропустить</button>
        </div>
    </div>
</div>

<!-- Org Employees Modal -->
<div class="modal-overlay" id="orgModal">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-header">
            <div class="modal-title" id="orgModalTitle"><i class="fas fa-users"></i> Сотрудники</div>
            <button class="modal-close" onclick="closeModal('orgModal')"><i class="fas fa-times"></i></button>
        </div>
        <div id="orgModalBody" style="overflow-y:auto;max-height:60vh">
            <div class="empty"><div class="empty-icon"><i class="fas fa-hourglass-half"></i></div>Загрузка…</div>
        </div>
    </div>
</div>

<!-- Camera Overlay -->
<div class="camera-overlay" id="cameraOverlay">
    <div style="color:white;font-size:18px;font-weight:700;margin-bottom:12px"><i class="fas fa-camera"></i> Сканирование QR-кода</div>
    <div class="camera-frame">
        <video id="cameraVideo" playsinline autoplay muted style="display:block;width:min(90vw,420px)"></video>
    </div>
    <div class="camera-hint">Наведите камеру на QR-код</div>
    <button class="camera-close" onclick="closeCamera()"><i class="fas fa-times"></i> Закрыть камеру</button>
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
    window.CURRENT_MEAL     = <?= json_encode($current_meal) ?>;
    window.ORG_LIST         = <?= json_encode(array_column($pdo->query("SELECT DISTINCT TRIM(organization) as o FROM employees WHERE organization!='' AND NOT (COALESCE(chat_access,0)=1 AND role IS NULL) ORDER BY o")->fetchAll(), 'o'), JSON_UNESCAPED_UNICODE) ?>;
})();

function deleteChatUser(id, name) {
    if (!confirm('Удалить чат-пользователя «' + name + '»?')) return;
    fetch('delete_employee.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ id }),
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            const row = document.getElementById('cu-row-' + id);
            if (row) row.remove();
        } else {
            alert(d.message || 'Ошибка удаления');
        }
    })
    .catch(() => alert('Ошибка сервера'));
}
</script>
<!-- Employee stats modal -->
<div class="modal-overlay" id="empStatsModal">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-chart-bar"></i> Статистика питания</div>
            <button class="modal-close" onclick="closeModal('empStatsModal')"><i class="fas fa-times"></i></button>
        </div>
        <div style="padding:20px">
            <div id="empStatsName" style="font-size:15px;font-weight:700;margin-bottom:16px;color:var(--text-main)"></div>
            <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:16px;flex-wrap:wrap">
                <div>
                    <label style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;display:block;margin-bottom:4px">С</label>
                    <input type="date" id="empStatsFrom" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Onest',sans-serif;font-size:13px;color:var(--text-main);background:var(--bg-card)">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;display:block;margin-bottom:4px">По</label>
                    <input type="date" id="empStatsTo" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Onest',sans-serif;font-size:13px;color:var(--text-main);background:var(--bg-card)">
                </div>
                <button onclick="loadEmpStats()" style="padding:8px 16px;background:var(--blue-700);color:#fff;border:none;border-radius:8px;font-family:'Onest',sans-serif;font-size:13px;font-weight:600;cursor:pointer">Показать</button>
            </div>
            <div id="empStatsResult"></div>
            <!-- Dry rations section -->
            <div id="empRationsSection" style="margin-top:16px;border-top:1.5px solid var(--border);padding-top:16px;display:none">
                <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
                    Сухой паёк / Выездное питание <span id="empRationsCount" style="color:var(--blue-700)"></span>
                </div>
                <div id="empRationsList" style="margin-bottom:10px"></div>
                <div id="empRationsAdd" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <input type="date" id="empRationDate" style="padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Onest',sans-serif;font-size:13px;color:var(--text-main);background:var(--bg-card)">
                    <select id="empRationType" style="padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Onest',sans-serif;font-size:13px;color:var(--text-main);background:var(--bg-card)">
                        <option value="dry_ration">Сухой паёк</option>
                        <option value="field">Выездное питание</option>
                    </select>
                    <button onclick="addRation()" id="empRationAddBtn" style="padding:7px 14px;background:var(--blue-700);color:#fff;border:none;border-radius:8px;font-family:'Onest',sans-serif;font-size:13px;font-weight:600;cursor:pointer">+ Добавить</button>
                </div>
                <div id="empRationsMsg" style="font-size:12px;color:#dc2626;margin-top:6px;display:none"></div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/qr-input.js"></script>
<script src="assets/js/app.js?v=2"></script>
<?php if ($is_admin): ?>
<style>
#chatToastContainer {
    position: fixed; bottom: 20px; left: 20px; z-index: 99999;
    display: flex; flex-direction: column-reverse; gap: 10px;
    pointer-events: none;
}
.chat-toast {
    pointer-events: all;
    display: flex; align-items: flex-start; gap: 10px;
    background: #fff; border-radius: 14px;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
    padding: 12px 14px; max-width: 320px; min-width: 240px;
    cursor: pointer; position: relative; overflow: hidden;
    animation: toastIn .3s cubic-bezier(.22,1,.36,1);
    border: 1.5px solid #e2e8f0;
    font-family: 'Onest', 'Segoe UI', sans-serif;
}
.chat-toast.hiding {
    animation: toastOut .3s ease forwards;
}
@keyframes toastIn  { from { opacity:0; transform:translateX(-30px) scale(.95); } to { opacity:1; transform:none; } }
@keyframes toastOut { to   { opacity:0; transform:translateX(-30px) scale(.95); } }
.chat-toast-bar {
    position: absolute; bottom: 0; left: 0; height: 3px;
    background: var(--tc, #003366); border-radius: 0 0 0 12px;
    animation: toastBar 6s linear forwards;
}
@keyframes toastBar { from { width:100%; } to { width:0%; } }
.chat-toast-avatar {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 15px; font-weight: 700;
}
.chat-toast-body { flex: 1; min-width: 0; }
.chat-toast-room  { font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 2px; }
.chat-toast-sender{ font-size: 13px; font-weight: 700; color: #0f172a; }
.chat-toast-text  { font-size: 12px; color: #374151; margin-top: 2px;
                    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-toast-close {
    position: absolute; top: 8px; right: 10px;
    font-size: 16px; color: #94a3b8; cursor: pointer;
    line-height: 1; background: none; border: none; padding: 0;
}
.chat-toast-close:hover { color: #475569; }
</style>

<div id="chatToastContainer"></div>

<script>
(function() {
    const badge      = document.getElementById('chatUnreadBadge');
    const container  = document.getElementById('chatToastContainer');
    if (!badge) return;

    // roomStates: { id: { unread, lastMsgId, name, type, color } }
    const roomStates = {};
    let initialized  = false;

    /* ── Sound (Web Audio API) ── */
    function playNotif() {
        try {
            const ctx  = new (window.AudioContext || window.webkitAudioContext)();
            [[660, 0], [880, 0.13], [1100, 0.26]].forEach(([freq, delay]) => {
                const osc = ctx.createOscillator();
                const g   = ctx.createGain();
                osc.connect(g); g.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.value = freq;
                const t = ctx.currentTime + delay;
                g.gain.setValueAtTime(0, t);
                g.gain.linearRampToValueAtTime(0.25, t + 0.03);
                g.gain.exponentialRampToValueAtTime(0.001, t + 0.28);
                osc.start(t); osc.stop(t + 0.3);
            });
        } catch(e) {}
    }

    /* ── Toast ── */
    function showToast(room, msg) {
        const color   = room.avatar_color || '#003366';
        const initial = (room.name || '?').charAt(0).toUpperCase();
        const toast   = document.createElement('div');
        toast.className = 'chat-toast';
        toast.style.setProperty('--tc', color);
        toast.innerHTML =
            '<div class="chat-toast-avatar" style="background:' + color + '">' + initial + '</div>' +
            '<div class="chat-toast-body">' +
                '<div class="chat-toast-room">' + esc(room.name || 'Чат') + '</div>' +
                '<div class="chat-toast-sender">' + esc(msg.sender_name) + '</div>' +
                '<div class="chat-toast-text">' + esc(previewText(msg)) + '</div>' +
            '</div>' +
            '<button class="chat-toast-close" title="Закрыть">×</button>' +
            '<div class="chat-toast-bar"></div>';

        toast.addEventListener('click', function(e) {
            if (e.target.classList.contains('chat-toast-close')) { dismiss(toast); return; }
            window.location.href = 'chat.php';
        });
        toast.querySelector('.chat-toast-close').addEventListener('click', function(e) {
            e.stopPropagation(); dismiss(toast);
        });

        container.appendChild(toast);
        const tid = setTimeout(() => dismiss(toast), 6000);
        toast._tid = tid;
    }

    function dismiss(toast) {
        clearTimeout(toast._tid);
        toast.classList.add('hiding');
        toast.addEventListener('animationend', () => toast.remove(), {once: true});
    }

    function previewText(msg) {
        if (msg.msg_type === 'image') return '📷 Фото';
        if (msg.msg_type === 'file')  return '📎 ' + (msg.orig_name || 'Файл');
        if (msg.msg_type === 'video') return '🎥 Видео';
        if (msg.msg_type === 'audio') return '🎵 Аудио';
        return msg.body || '';
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    /* ── Fetch new messages for a room ── */
    function fetchNewMsgs(room, afterId) {
        if (room.type === 'direct') return; // DM — только звук, без тоста
        fetch('api/chat.php?action=messages&room_id=' + room.id + '&after=' + afterId + '&limit=5', {credentials:'same-origin'})
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data || !data.messages) return;
                data.messages
                    .filter(m => m.msg_type !== 'system' && m.sender_id != 0)
                    .forEach(m => showToast(room, m));
            })
            .catch(() => {});
    }

    /* ── Main poll ── */
    function poll() {
        fetch('api/chat.php?action=rooms', {credentials:'same-origin'})
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data || !data.rooms) return;

                let totalUnread = 0;
                let hasNew = false;

                data.rooms.forEach(room => {
                    const id     = room.id;
                    const unread = parseInt(room.unread) || 0;
                    totalUnread += unread;

                    if (!initialized) {
                        // Первый запрос — запоминаем состояние, не показываем тосты
                        roomStates[id] = { unread, name: room.name, type: room.type, avatar_color: room.avatar_color };
                        return;
                    }

                    const prev = roomStates[id];
                    if (!prev) {
                        // Новая комната появилась
                        roomStates[id] = { unread, name: room.name, type: room.type, avatar_color: room.avatar_color };
                        if (unread > 0) {
                            hasNew = true;
                            fetchNewMsgs(room, 0);
                        }
                        return;
                    }

                    if (unread > prev.unread) {
                        hasNew = true;
                        // lastMsgId = нужен для запроса; берём из room.last_read_id если есть
                        const afterId = room.last_read_id || 0;
                        fetchNewMsgs(room, afterId);
                    }
                    roomStates[id] = { unread, name: room.name, type: room.type, avatar_color: room.avatar_color };
                });

                if (!initialized) { initialized = true; }
                else if (hasNew) { playNotif(); }

                // Обновляем badge
                if (totalUnread > 0) {
                    badge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(() => {});
    }

    poll();
    setInterval(poll, 30000);
})();
</script>
<?php endif; ?>
</body>
</html>
