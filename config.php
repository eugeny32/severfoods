<?php
/**
 * Точка входа конфигурации.
 * Все файлы проекта делают require_once 'config.php' — здесь подключаем
 * ядро (bootstrap + CSRF). functions.php подключается отдельно там, где нужен.
 */
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/Csrf.php';
