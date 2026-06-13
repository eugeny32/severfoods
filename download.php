<?php
/**
 * Публичная страница загрузки оффлайн-приложения.
 * Файлы дистрибутива кладите в папку downloads/ рядом с этим файлом.
 */

// Найти .exe с максимальным номером версии в имени
$dir = __DIR__ . '/offline/dist/';
$latest = null;
$latestVer = [0,0,0];
if (is_dir($dir)) {
    foreach (glob($dir . '*.exe') as $f) {
        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', basename($f), $m)) {
            $ver = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if ($ver > $latestVer) { $latestVer = $ver; $latest = $f; }
        }
    }
}
$hasFile   = $latest !== null;
$fileName  = $hasFile ? basename($latest) : null;
$fileSize  = $hasFile ? round(filesize($latest) / 1024 / 1024, 1) . ' МБ' : null;
$fileDate  = $hasFile ? date('d.m.Y', $latestTime) : null;
// Extract version from filename like SeverFoods-Setup-1.3.0.exe
$version   = '';
if ($fileName && preg_match('/(\d+\.\d+\.\d+)/', $fileName, $m)) $version = 'v' . $m[1];

// Direct download trigger
if (isset($_GET['get']) && $hasFile) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($latest));
    header('Cache-Control: no-cache');
    readfile($latest);
    exit;
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Оффлайн-приложение СеверФудс</title>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Onest',sans-serif;background:#f1f5f9;color:#0f172a;min-height:100vh;display:flex;flex-direction:column}
.page-header{background:#003366;color:#fff;padding:18px 32px;display:flex;align-items:center;gap:16px}
.page-header h1{font-size:20px;font-weight:700}
.back-btn{background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:8px;padding:8px 14px;font-family:'Onest',sans-serif;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;margin-left:auto}
.back-btn:hover{background:rgba(255,255,255,.25)}
.container{max-width:600px;margin:40px auto;padding:0 20px 60px;width:100%}
.card{background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px;text-align:center}
.app-icon{width:72px;height:72px;background:#003366;border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:36px}
.app-name{font-size:24px;font-weight:800;color:#003366;margin-bottom:4px}
.app-sub{font-size:14px;color:#64748b;margin-bottom:28px}
.version-badge{display:inline-block;background:#eff6ff;color:#003366;border-radius:8px;padding:4px 12px;font-size:13px;font-weight:700;margin-bottom:6px}
.file-info{font-size:12px;color:#94a3b8;margin-bottom:28px}
.btn-download{display:inline-flex;align-items:center;gap:10px;background:#003366;color:#fff;border:none;border-radius:12px;padding:14px 28px;font-family:'Onest',sans-serif;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .15s;margin-bottom:12px}
.btn-download:hover{background:#00438a}
.btn-download svg{flex-shrink:0}
.no-file{background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:16px 20px;color:#92400e;font-size:14px;margin-bottom:20px;text-align:left}
.req-list{text-align:left;background:#f8fafc;border-radius:10px;padding:16px 20px;margin-top:24px}
.req-list h4{font-size:13px;font-weight:700;color:#374151;margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em}
.req-list li{font-size:13px;color:#64748b;margin-bottom:4px;margin-left:16px}
.divider{border:none;border-top:1.5px solid #e2e8f0;margin:24px 0}
.manual-link{display:inline-flex;align-items:center;gap:8px;color:#003366;text-decoration:none;font-size:14px;font-weight:600}
.manual-link:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="page-header">
    <div>
        <h1><svg style="display:inline;vertical-align:middle;margin-right:8px;margin-bottom:2px" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Загрузка оффлайн-приложения</h1>
    </div>
    <a href="javascript:history.back()" class="back-btn">← Назад</a>
</div>

<div class="container">
<div class="card">
    <div class="app-icon">
        <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
    </div>
    <div class="app-name">SeverFoods Offline</div>
    <div class="app-sub">Автономное приложение для точки питания · Windows</div>

    <?php if ($hasFile): ?>
        <div class="version-badge"><?= htmlspecialchars($version) ?></div>
        <div class="file-info"><?= htmlspecialchars($fileName) ?> · <?= $fileSize ?> · <?= $fileDate ?></div>
        <a href="download.php?get=1" class="btn-download">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Скачать установщик
        </a>
        <div style="font-size:12px;color:#94a3b8">Бесплатно · Только для Windows 10/11 x64</div>
    <?php else: ?>
        <div class="no-file">
            <strong>Файл дистрибутива не найден.</strong><br>
            Администратору: поместите файл <code>SeverFoods-Setup-X.X.X.exe</code> в папку <code>offline/dist/</code> на сервере.
        </div>
    <?php endif; ?>

    <div class="req-list">
        <h4>Системные требования</h4>
        <ul>
            <li>Windows 10 или 11, 64-бит</li>
            <li>Права пользователя (администратор не нужен)</li>
            <li>Интернет для первичной синхронизации</li>
            <li>Токен синхронизации (выдаёт администратор)</li>
        </ul>
    </div>

    <hr class="divider">
    <a href="manual.php" class="manual-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Открыть справочное руководство
    </a>
</div>

<div style="text-align:center;font-size:12px;color:#94a3b8;margin-top:20px">
    Система СеверФудс · ООО «Север» · <?= date('Y') ?>
</div>
</div>
</body>
</html>
