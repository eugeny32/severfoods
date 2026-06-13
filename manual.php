<?php
/**
 * Публичная страница — Справочное руководство СеверФудс
 * Доступна без авторизации (QR-auth или прямой переход)
 */
$appName = 'СеверФудс';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Справочное руководство — <?= $appName ?></title>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Onest',sans-serif;background:#f1f5f9;color:#0f172a;line-height:1.6;font-size:15px}
.page-header{background:#003366;color:#fff;padding:18px 32px;display:flex;align-items:center;gap:16px}
.page-header h1{font-size:20px;font-weight:700}
.page-header .sub{font-size:13px;opacity:.7;margin-top:2px}
.back-btn{background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:8px;padding:8px 14px;font-family:'Onest',sans-serif;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
.back-btn:hover{background:rgba(255,255,255,.25)}
.container{max-width:900px;margin:32px auto;padding:0 20px 60px}
.card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);padding:32px 36px;margin-bottom:24px}
h1{font-size:26px;font-weight:800;margin-bottom:8px;color:#003366}
h2{font-size:20px;font-weight:700;margin:28px 0 12px;color:#003366;border-bottom:2px solid #e2e8f0;padding-bottom:6px}
h3{font-size:16px;font-weight:700;margin:20px 0 8px;color:#1e3a5f}
h4{font-size:14px;font-weight:700;margin:16px 0 6px;color:#374151}
p{margin-bottom:10px}
ul,ol{margin:8px 0 10px 22px}
li{margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin:12px 0;font-size:14px}
th{background:#003366;color:#fff;padding:8px 12px;text-align:left;font-weight:600}
td{padding:7px 12px;border-bottom:1px solid #e2e8f0}
tr:last-child td{border-bottom:none}
tr:nth-child(even) td{background:#f8fafc}
blockquote{background:#eff6ff;border-left:4px solid #003366;padding:10px 16px;border-radius:0 8px 8px 0;margin:10px 0;font-size:14px;color:#1e40af}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:13px}
.toc{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:28px}
.toc h3{margin-top:0;margin-bottom:10px;font-size:15px}
.toc a{color:#003366;text-decoration:none;font-size:14px;display:block;padding:2px 0}
.toc a:hover{text-decoration:underline}
.toc .toc-section{margin-left:14px;color:#64748b}
hr{border:none;border-top:1.5px solid #e2e8f0;margin:24px 0}
@media(max-width:640px){.card{padding:20px}.container{padding:0 12px 40px}}
</style>
</head>
<body>
<div class="page-header">
    <div>
        <h1><svg style="display:inline;vertical-align:middle;margin-right:8px;margin-bottom:2px" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>Справочное руководство</h1>
        <div class="sub">Система учёта питания СеверФудс · ООО «Север»</div>
    </div>
    <a href="javascript:history.back()" class="back-btn" style="margin-left:auto">← Назад</a>
</div>

<div class="container">

<div class="card">
<div class="toc">
    <h3>Содержание</h3>
    <a href="#s1">1. Общее описание</a>
    <a href="#s2">2. Онлайн-система</a>
    <span class="toc-section"><a href="#s21">2.1 Роли пользователей</a></span>
    <span class="toc-section"><a href="#s22">2.2 Вход и навигация</a></span>
    <span class="toc-section"><a href="#s23">2.3 Сканер QR (главная)</a></span>
    <span class="toc-section"><a href="#s24">2.4 Сотрудники</a></span>
    <span class="toc-section"><a href="#s25">2.5 Отчёты</a></span>
    <span class="toc-section"><a href="#s26">2.6 Чат</a></span>
    <span class="toc-section"><a href="#s27">2.7 Статистика питания сотрудника</a></span>
    <a href="#s3">3. Оффлайн-приложение</a>
    <span class="toc-section"><a href="#s31">3.1 Установка</a></span>
    <span class="toc-section"><a href="#s32">3.2 Обновление</a></span>
    <span class="toc-section"><a href="#s33">3.3 Сканер и очередь QR</a></span>
    <span class="toc-section"><a href="#s34">3.4 Синхронизация</a></span>
    <a href="#s4">4. Администрирование</a>
    <a href="#s5">5. FAQ</a>
</div>

<h2 id="s1">1. Общее описание</h2>
<p><strong>СеверФудс</strong> — система учёта питания сотрудников предприятия ООО «Север». Состоит из двух компонентов:</p>
<table>
<tr><th>Компонент</th><th>Назначение</th></tr>
<tr><td><strong>Онлайн</strong> (веб-приложение)</td><td>Центральный сервер: база данных сотрудников, отчёты, управление пользователями, чат</td></tr>
<tr><td><strong>Оффлайн</strong> (приложение Windows)</td><td>Автономная точка питания: сканирование QR-карт, локальная БД, синхронизация</td></tr>
</table>
<p><strong>Принцип работы:</strong> администратор регистрирует сотрудников → печатает QR-карты → на точке питания сканируется карта → оффлайн-приложение фиксирует приём → данные синхронизируются с сервером → руководитель просматривает отчёты.</p>

<h2 id="s2">2. Онлайн-система</h2>

<h3 id="s21">2.1 Роли пользователей</h3>
<table>
<tr><th>Роль</th><th>Доступные разделы</th></tr>
<tr><td><strong>Оператор</strong></td><td>Главная (сканер), Чат</td></tr>
<tr><td><strong>Администратор</strong></td><td>Главная, Сотрудники, Отчёты, Чат, QR-карты, Точки питания</td></tr>
<tr><td><strong>Супер-администратор</strong></td><td>Все разделы + Пользователи + Настройки системы</td></tr>
</table>

<h3 id="s22">2.2 Вход и навигация</h3>
<p>Вход выполняется по логину/паролю <strong>или</strong> сканированием QR-карты камерой устройства или USB-сканером. При сканировании QR система автоматически идентифицирует сотрудника и открывает его сессию.</p>

<h3 id="s23">2.3 Сканер QR (главная страница)</h3>
<p>Основное рабочее место оператора. Сканер подключается как HID-клавиатура. Результаты:</p>
<ul>
<li><svg style="display:inline;vertical-align:middle;margin-right:5px" width="13" height="13" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#16a34a"/></svg><strong>Зелёный</strong> — приём зафиксирован, выводятся ФИО и организация</li>
<li><svg style="display:inline;vertical-align:middle;margin-right:5px" width="13" height="13" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#d97706"/></svg><strong>Оранжевый</strong> — повторный приём (уже питался сегодня)</li>
<li><svg style="display:inline;vertical-align:middle;margin-right:5px" width="13" height="13" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#dc2626"/></svg><strong>Красный</strong> — не найден, заблокирован или ошибка</li>
</ul>

<h3 id="s24">2.4 Сотрудники</h3>
<p>Управление базой сотрудников. Поля: ФИО, организация, подразделение, должность, Вахтовый жилой городок (ВЖГ), срок QR, статус. Экспорт в Excel. Печать QR-карт.</p>

<h3 id="s25">2.5 Отчёты</h3>
<p>Фильтры: период, точка питания, организация, тип приёма. Отчёты: сводный по дням, по сотрудникам, по организациям, детальный журнал. Экспорт в Excel.</p>

<h3 id="s26">2.6 Чат</h3>
<p>Корпоративный мессенджер. Типы комнат: личные диалоги (DM), группы, каналы. Все участники могут писать в группах и каналах. Уведомления: бейдж на кнопке «Чат» + всплывающие тосты + звуковой перезвон.</p>

<h3 id="s27">2.7 Статистика питания сотрудника</h3>
<p>Открывается кнопкой <svg style="display:inline;vertical-align:middle" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#003366" stroke-width="2.2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> в списке сотрудников. Показывает: общее число приёмов, дней питания (столовая + выездное), разбивку по типам. Блок <strong>Выездное питание</strong>:</p>
<ul>
<li>Добавление диапазоном дат (можно указывать будущие даты — командировки заранее), лимит <strong>4 дня</strong> за период</li>
<li><svg style="display:inline;vertical-align:middle;margin-right:5px" width="13" height="13" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#16a34a"/></svg>Зелёный — сотрудник был на выезде (дата прошла)</li>
<li><svg style="display:inline;vertical-align:middle;margin-right:5px" width="13" height="13" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#d97706"/></svg>Оранжевый — запланировано (будущая дата)</li>
<li><svg style="display:inline;vertical-align:middle;margin-right:5px" width="13" height="13" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#dc2626"/></svg>Красный — аннулировано: сотрудник явился в столовую в этот день</li>
</ul>

<h2 id="s3">3. Оффлайн-приложение</h2>

<h3 id="s31">3.1 Установка</h3>
<p><strong>Требования:</strong> Windows 10/11 x64, права пользователя (администратор НЕ нужен).</p>
<ol>
<li>Запустите <code>SeverFoods-Setup-X.X.X.exe</code></li>
<li>Введите токен синхронизации (выдаётся администратором в разделе «Точки питания»)</li>
<li>Нажмите «Далее» → «Установить» → «Завершить»</li>
</ol>
<p>База данных хранится в <code>%APPDATA%\SeverFoods\</code> — отдельно от программы, не удаляется при обновлении.</p>

<h3 id="s32">3.2 Обновление без сброса токена</h3>
<ol>
<li>Закройте приложение (трей → Выход)</li>
<li>Запустите новый инсталлятор</li>
<li><strong>Оставьте поле токена пустым</strong> — текущий токен сохранится автоматически</li>
<li>Завершите установку. База данных и история питания сохранены.</li>
</ol>
<blockquote>Никогда не удаляйте вручную папку <code>%APPDATA%\SeverFoods\</code> — там находится база данных.</blockquote>

<h3 id="s33">3.3 Сканер и очередь QR</h3>
<p>При потоковом сканировании коды выстраиваются в очередь FIFO с паузой 250 мс. Цветовые сигналы:</p>
<table>
<tr><th>Цвет</th><th>Значение</th><th>Время</th></tr>
<tr><td style="color:#16a34a;font-weight:700">Зелёный</td><td>Успешно</td><td>3 сек</td></tr>
<tr><td style="color:#d97706;font-weight:700">Оранжевый</td><td>Повтор</td><td>3 сек</td></tr>
<tr><td style="color:#dc2626;font-weight:700">Красный</td><td>Отказ</td><td>8 сек + 5 сек тревога</td></tr>
</table>
<p>Красный баннер (отказ) не прерывается зелёным/оранжевым — оператор всегда увидит ошибку.</p>

<h3 id="s34">3.4 Синхронизация</h3>
<p>Запускается автоматически по расписанию или вручную (кнопка в приложении или трей → «Синхронизировать»). При отсутствии связи приложение работает автономно — данные отправятся при восстановлении соединения.</p>

<h2 id="s4">4. Администрирование</h2>
<h3>Добавление сотрудника</h3>
<ol>
<li>Раздел «Сотрудники» → «Добавить»</li>
<li>Заполните ФИО, организацию → сохранить. QR-код генерируется автоматически.</li>
<li>Распечатайте QR-карту, выдайте сотруднику.</li>
<li>Синхронизируйте оффлайн-точки — новый сотрудник появится там.</li>
</ol>
<h3>Токен синхронизации</h3>
<p>Хранится в <code>%LOCALAPPDATA%\SeverFoods\.env</code>. Один токен — одна точка питания. При компрометации — перевыпустить в разделе «Точки питания» и обновить настройки приложения.</p>

<h2 id="s5">5. FAQ</h2>
<h4>Сотрудник потерял карту — что делать?</h4>
<p>Откройте карточку сотрудника → «Перевыпустить QR» → распечатайте новую карту. Старый код аннулируется после синхронизации.</p>
<h4>Оффлайн-приложение не синхронизируется</h4>
<p>Проверьте интернет-соединение, затем Настройки → убедитесь что токен введён верно → «Синхронизировать» вручную.</p>
<h4>Красный баннер «Не найден в базе»</h4>
<p>Сотрудник не добавлен в систему, или добавлен но синхронизация не выполнялась, или сотрудник заблокирован.</p>
<h4>Перенос на другой компьютер</h4>
<p>Установите приложение на новый ПК с тем же токеном, скопируйте <code>%APPDATA%\SeverFoods\severfoods.db</code> — история питания восстановится.</p>

</div><!-- .card -->

<div style="text-align:center;font-size:12px;color:#94a3b8;margin-top:8px">
    Система СеверФудс · ООО «Север» · <?= date('Y') ?>
</div>

</div><!-- .container -->
</body>
</html>
