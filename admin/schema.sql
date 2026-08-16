-- ============================================================
--  ЦЕНТРАЛЬНАЯ БАЗА АДМИНКИ  —  admin.severfoods.ru
--  Выполнить один раз в отдельной базе (например u3523560_canteen_admin).
--
--  Здесь НЕТ данных о питании и сотрудниках: они остаются в базах регионов.
--  Центральная база хранит только реестр регионов, сведения о заказчиках,
--  учётные записи самой админки и журнал её действий.
-- ============================================================

SET NAMES utf8mb4;

-- ── Заказчики ────────────────────────────────────────────────
-- Отдельно от регионов: у одного заказчика может быть несколько площадок.
CREATE TABLE IF NOT EXISTS customers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    inn           VARCHAR(20)  DEFAULT NULL,
    contact_name  VARCHAR(255) DEFAULT NULL,
    contact_phone VARCHAR(50)  DEFAULT NULL,
    contact_email VARCHAR(255) DEFAULT NULL,
    contract_no   VARCHAR(100) DEFAULT NULL,
    contract_date DATE         DEFAULT NULL,
    notes         TEXT         DEFAULT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Реестр регионов ──────────────────────────────────────────
-- Смысл всей затеи: добавление региона — это СТРОКА ЗДЕСЬ, а не правка кода.
-- Раньше список был захардкожен в getRegions() (src/regions.php), и новый
-- регион требовал передеплоя всех существующих площадок.
--
-- db_host/db_user/db_pass заполняются, только если база лежит не там же, где
-- админка. При пустых значениях используется подключение самой админки — все
-- базы сейчас на одном MySQL, и это самый частый случай.
CREATE TABLE IF NOT EXISTS regions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    region_key   VARCHAR(32)  NOT NULL UNIQUE,   -- kyzyl, nrg, tnd …
    label        VARCHAR(100) NOT NULL,          -- Кызыл, Нерюнгри, Тында
    domain       VARCHAR(255) NOT NULL,          -- severfoods.ru, nrg.severfoods.ru
    db_name      VARCHAR(64)  NOT NULL,
    db_host      VARCHAR(255) DEFAULT NULL,
    db_user      VARCHAR(64)  DEFAULT NULL,
    db_pass      VARCHAR(255) DEFAULT NULL,
    customer_id  INT          DEFAULT NULL,
    tz_offset    VARCHAR(6)   NOT NULL DEFAULT '+03:00',
    sort_order   INT          NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_regions_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Учётные записи админки ───────────────────────────────────
-- Вход по ЛОГИНУ И ПАРОЛЮ, а не по QR-коду.
--
-- В региональных системах вход администратора — это поиск по qr_code, пароль
-- не проверяется вовсе, а сам код лежит в базе открытым текстом и в кармане у
-- сотрудника. Для площадки, которая видит персональные данные ВСЕХ регионов
-- сразу, такая схема неприемлема. Старый вход в регионах при этом не меняется.
CREATE TABLE IF NOT EXISTS admin_users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    login          VARCHAR(64)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,        -- password_hash(), bcrypt
    full_name      VARCHAR(255) NOT NULL,
    role           ENUM('owner','viewer') NOT NULL DEFAULT 'viewer',
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at  DATETIME     DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Журнал действий ──────────────────────────────────────────
-- Кто, что и в каком регионе сделал. Для площадки с доступом ко всем
-- персональным данным это не роскошь, а обязательная часть.
CREATE TABLE IF NOT EXISTS admin_audit (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          DEFAULT NULL,
    user_login  VARCHAR(64)  DEFAULT NULL,       -- дублируем: учётку могут удалить
    action      VARCHAR(64)  NOT NULL,
    region_key  VARCHAR(32)  DEFAULT NULL,
    details     TEXT         DEFAULT NULL,
    ip_address  VARCHAR(64)  DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Неудачные попытки входа ──────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip_address  VARCHAR(64) NOT NULL,
    login       VARCHAR(64) DEFAULT NULL,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  ПЕРВОНАЧАЛЬНОЕ ЗАПОЛНЕНИЕ
--  Проверьте имена баз перед выполнением: они должны совпадать с теми,
--  что реально созданы на хостинге.
-- ============================================================

INSERT IGNORE INTO regions (region_key, label, domain, db_name, sort_order) VALUES
    ('kyzyl', 'Кызыл',    'severfoods.ru',     'u3523560_canteen',     1),
    ('nrg',   'Нерюнгри', 'nrg.severfoods.ru', 'u3523560_canteen_ngr', 2),
    ('tnd',   'Тында',    'tnd.severfoods.ru', 'ЗАМЕНИТЕ_НА_ИМЯ_БАЗЫ',  3);

-- Первая учётная запись создаётся отдельно: пароль задаётся при установке,
-- см. admin/install.php — хеш нельзя вписать в файл, который лежит в git.
