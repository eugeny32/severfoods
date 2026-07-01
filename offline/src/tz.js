/**
 * Часовой пояс оффлайн-точки. Все scanned_at в локальной БД хранятся как
 * "голая" UTC-строка (см. routes/meal_logs.js), поэтому для отображения
 * и подсчёта "сегодня" нужен явный офсет, настраиваемый в приложении —
 * полагаться на часовой пояс операционной системы нельзя.
 */
const db = require('./db');

const DEFAULT_TZ = '+03:00';

function getTzOffset() {
    const v = db.getMeta('tz_offset');
    return v && /^[+-]\d{2}:\d{2}$/.test(v) ? v : DEFAULT_TZ;
}

function setTzOffset(tz) {
    if (tz === '' || tz === null) { db.setMeta('tz_offset', null); return true; }
    if (!/^[+-]\d{2}:\d{2}$/.test(tz)) return false;
    db.setMeta('tz_offset', tz);
    return true;
}

function offsetToMinutes(tz) {
    const m = /^([+-])(\d{2}):(\d{2})$/.exec(tz);
    if (!m) return 0;
    return (m[1] === '-' ? -1 : 1) * (parseInt(m[2], 10) * 60 + parseInt(m[3], 10));
}

/** Границы "сегодня" (UTC, 'YYYY-MM-DD HH:MM:SS') для текущего офсета — местная полночь → местная полночь+24ч. */
function todayWindowUtc() {
    const offMin  = offsetToMinutes(getTzOffset());
    const nowMs   = Date.now();
    const localDate = new Date(nowMs + offMin * 60000).toISOString().slice(0, 10);
    const localMidnightUtcMs = Date.parse(localDate + 'T00:00:00Z') - offMin * 60000;
    const toStr = ms => new Date(ms).toISOString().replace('T', ' ').slice(0, 19);
    return { start: toStr(localMidnightUtcMs), end: toStr(localMidnightUtcMs + 24 * 3600000) };
}

module.exports = { DEFAULT_TZ, getTzOffset, setTzOffset, offsetToMinutes, todayWindowUtc };
