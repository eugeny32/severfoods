<?php
/**
 * Первичная установка админки: создать таблицы и первую учётную запись.
 *
 * Пароль задаётся здесь, а не в schema.sql: хеш не должен попадать в файл,
 * который лежит в репозитории. Как только владелец создан, страница сама себя
 * запирает — повторно ей воспользоваться нельзя.
 *
 * После установки файл лучше удалить с сервера.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/layout.php';

function installOwnerExists(PDO $pdo): bool
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'owner'")->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false; // таблицы ещё нет — значит и владельца нет
    }
}

$done = [];
$error = '';
$locked = installOwnerExists($pdo);

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string)($_POST['login'] ?? ''));
    $name  = trim((string)($_POST['full_name'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $login)) {
        $error = 'Логин: латиница, цифры, точка, дефис или подчёркивание, от 3 символов';
    } elseif ($name === '') {
        $error = 'Укажите имя';
    } elseif (mb_strlen($pass) < 10) {
        $error = 'Пароль должен быть не короче 10 символов — эта площадка видит данные всех регионов';
    } elseif ($pass !== $pass2) {
        $error = 'Пароли не совпадают';
    } else {
        try {
            // Схема выполняется по одному запросу: PDO не умеет несколько
            // команд в одном вызове при отключённой эмуляции.
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt === '' || str_starts_with($stmt, '--')) continue;
                $pdo->exec($stmt);
            }
            $done[] = 'Таблицы созданы';

            $pdo->prepare(
                'INSERT INTO admin_users (login, password_hash, full_name, role) VALUES (?, ?, ?, ?)'
            )->execute([$login, password_hash($pass, PASSWORD_DEFAULT), $name, 'owner']);
            $done[] = 'Учётная запись «' . $login . '» создана';

            $locked = true;
        } catch (Throwable $e) {
            $error = 'Ошибка установки: ' . $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Установка · <?= adminEsc(ADMIN_TITLE) ?></title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=2">
<style>
/* Страница установки открывается один раз и до входа, поэтому у неё нет
   шапки и меню — только карточка по центру. Всё остальное берётся из общего
   файла стилей. */
body { padding: 40px 20px; }
.box {
    max-width: 560px; margin: 0 auto; background: var(--surface);
    border: 1px solid var(--border); border-radius: var(--radius-lg);
    padding: 30px; box-shadow: var(--shadow);
}
.box h1 { font-size: 22px; margin-bottom: 8px; }
p.sub { color: var(--text-3); font-size: 14px; margin-bottom: 22px; line-height: 1.55; }
.box input { width: 100%; margin-bottom: 15px; }
.box button {
    width: 100%; min-height: 48px;
    background: linear-gradient(135deg, var(--blue-800), var(--blue-500));
    color: #fff; border: none; border-radius: var(--radius-sm);
    font-family: inherit; font-size: 15px; font-weight: 700; cursor: pointer;
    box-shadow: 0 2px 10px rgba(0,51,102,.22);
}
.msg { display: block; }
.msg::before { content: none; }
.msg.err  { background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; }
.msg.ok   { background: #f0fdf4; border: 1.5px solid #bbf7d0; color: #166534; }
.msg.warn { background: #fff7ed; border: 1.5px solid #fed7aa; color: #92400e; line-height: 1.5; }
a { color: var(--blue-700); }
</style>
</head>
<body>
<div class="box">
<h1>Установка центральной админки</h1>

<?php if ($error): ?><div class="msg err"><?= adminEsc($error) ?></div><?php endif; ?>
<?php foreach ($done as $d): ?><div class="msg ok"><?= adminEsc($d) ?></div><?php endforeach; ?>

<?php if ($locked): ?>
    <div class="msg warn">
        <strong>Установка завершена.</strong> Владелец уже создан, повторная установка невозможна.<br>
        <strong>Удалите файл <code>install.php</code> с сервера</strong> — он больше не нужен.
    </div>
    <p class="sub">
        Дальше: на странице «Регионы» проверьте имена баз — в заготовке для Тынды
        стоит заполнитель, его нужно заменить на реальное имя.
    </p>
    <p><a href="login.php">Перейти ко входу →</a></p>
<?php else: ?>
    <p class="sub">
        Будут созданы таблицы центральной базы <code><?= adminEsc(ADMIN_DB_NAME) ?></code>
        и первая учётная запись с полными правами. Данные регионов не затрагиваются.
    </p>
    <form method="post" autocomplete="off">
        <label for="login">Логин</label>
        <input id="login" name="login" type="text" autocapitalize="off" spellcheck="false" required>

        <label for="full_name">Имя</label>
        <input id="full_name" name="full_name" type="text" required>

        <label for="password">Пароль (от 10 символов)</label>
        <input id="password" name="password" type="password" required>

        <label for="password2">Пароль ещё раз</label>
        <input id="password2" name="password2" type="password" required>

        <button type="submit">Установить</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
