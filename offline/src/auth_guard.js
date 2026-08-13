/**
 * Проверка роли для локального HTTP-API приложения.
 *
 * Локальный сервер слушает только 127.0.0.1, но этого мало: на компьютере
 * точки может работать кто угодно, а часть эндпоинтов отдаёт секреты
 * (токен синхронизации) и меняет настройки. Раньше роль проверялась только
 * на клиенте — карточка пряталась в интерфейсе, но прямой запрос к
 * localhost:3847 её обходил.
 *
 * Источник истины о роли — та же сессия, что и у остального приложения
 * (см. routes/auth.js, db.getMeta('session')).
 */
const db = require('./db');

/** Текущий вошедший сотрудник, либо null, если сессии нет или она истекла. */
function currentUser() {
    try {
        const raw = db.getMeta('session');
        if (!raw) return null;
        const sess = JSON.parse(raw);
        if (!sess?.employee) return null;
        if (new Date(sess.expires_at) <= new Date()) return null;
        return sess.employee;
    } catch (_) {
        return null;
    }
}

function roleOf() {
    return currentUser()?.role || null;
}

function isSuperAdmin() {
    return roleOf() === 'super_admin';
}

function isAdmin() {
    return ['admin', 'super_admin'].includes(roleOf());
}

/** Express-middleware: пускает только супер-администратора. */
function requireSuperAdmin(req, res, next) {
    if (isSuperAdmin()) return next();
    res.status(403).json({ ok: false, error: 'Доступно только супер-администратору' });
}

/** Express-middleware: пускает администратора и супер-администратора. */
function requireAdmin(req, res, next) {
    if (isAdmin()) return next();
    res.status(403).json({ ok: false, error: 'Доступно только администратору' });
}

module.exports = { currentUser, roleOf, isAdmin, isSuperAdmin, requireAdmin, requireSuperAdmin };
