-- ============================================================
--  ЕДИНЫЙ ПОЛЬЗОВАТЕЛЬ ДЛЯ ВСЕХ ПЛОЩАДОК
--  Акимова Валентина Владимировна · ООО "СЕВЕР" · Управление
--  Карта: EMP_1781069014_35643de852d5
--
--  Общей базы сотрудников нет: у каждого региона своя. «Один человек на всех
--  площадках» означает одну и ту же строку qr_code в базе КАЖДОГО региона —
--  тогда одна карта открывает вход на любом домене, а точки получат её
--  обычной синхронизацией своего региона.
--
--  Скрипт идемпотентен: повторный запуск не создаст дубль, а обновит запись.
--  QR-код не меняется никогда — он и есть общий ключ.
--
--  ВЫПОЛНИТЬ В КАЖДОЙ БАЗЕ РЕГИОНА ПО ОЧЕРЕДИ:
--    u3523560_canteen      (Кызыл)
--    u3523560_canteen_ngr  (Нерюнгри)
--    <база Тынды>          — когда будет заведена
--
--  РОЛЬ: ниже стоит 'super_admin' — полный доступ к площадке. Если нужен
--  обычный администратор, замените на 'admin' в ОБОИХ местах (INSERT и UPDATE).
-- ============================================================

INSERT INTO employees
    (full_name, birth_date, organization, department, position,
     vjg_type, price, qr_code, qr_expires_at, qr_status, is_active, role, assigned_point_id)
SELECT 'Акимова Валентина Владимировна', NULL, 'ООО "СЕВЕР"', 'Управление', '',
       '', 0, 'EMP_1781069014_35643de852d5', NULL, 'active', 1, 'super_admin', NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM employees e WHERE e.qr_code = 'EMP_1781069014_35643de852d5'
);

UPDATE employees SET
    full_name    = 'Акимова Валентина Владимировна',
    organization = 'ООО "СЕВЕР"',
    department   = 'Управление',
    role         = 'super_admin',
    qr_status    = 'active',
    is_active    = 1
WHERE qr_code = 'EMP_1781069014_35643de852d5';

-- Проверка: должна вернуться ровно одна строка.
SELECT id, full_name, organization, department, role, is_active, qr_status
FROM employees WHERE qr_code = 'EMP_1781069014_35643de852d5';
