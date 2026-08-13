/**
 * Часовой пояс точки — построчный порт offline/src/tz.js.
 * Полагаться на часовой пояс планшета нельзя: он настраивается пользователем и
 * может не совпадать с реальным поясом точки, а от этого зависит, что считается
 * «сегодня» при проверке повторного питания.
 */
(function (global) {
    'use strict';

    const DEFAULT_TZ = '+03:00';

    function getTzOffset() {
        const v = SFDb.getMeta('tz_offset');
        return v && /^[+-]\d{2}:\d{2}$/.test(v) ? v : DEFAULT_TZ;
    }

    function setTzOffset(tz) {
        if (tz === '' || tz === null) { SFDb.setMeta('tz_offset', null); return true; }
        if (!/^[+-]\d{2}:\d{2}$/.test(tz)) return false;
        SFDb.setMeta('tz_offset', tz);
        return true;
    }

    function offsetToMinutes(tz) {
        const m = /^([+-])(\d{2}):(\d{2})$/.exec(tz);
        if (!m) return 0;
        return (m[1] === '-' ? -1 : 1) * (parseInt(m[2], 10) * 60 + parseInt(m[3], 10));
    }

    function todayWindowUtc() {
        const offMin  = offsetToMinutes(getTzOffset());
        const nowMs   = Date.now();
        const localDate = new Date(nowMs + offMin * 60000).toISOString().slice(0, 10);
        const localMidnightUtcMs = Date.parse(localDate + 'T00:00:00Z') - offMin * 60000;
        const toStr = ms => new Date(ms).toISOString().replace('T', ' ').slice(0, 19);
        return { start: toStr(localMidnightUtcMs), end: toStr(localMidnightUtcMs + 24 * 3600000) };
    }

    global.SFTz = { DEFAULT_TZ, getTzOffset, setTzOffset, offsetToMinutes, todayWindowUtc };
})(window);
