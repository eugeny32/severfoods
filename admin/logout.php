<?php
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';

adminLogout($pdo);
header('Location: login.php');
