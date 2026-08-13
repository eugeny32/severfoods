/**
 * Хранилище файла базы для Android-версии.
 *
 * В Windows-версии база лежит обычным файлом в %APPDATA% (см. offline/src/db.js).
 * В вебвью файловой системы нет, поэтому тот же самый образ SQLite целиком
 * хранится одной записью в IndexedDB. Формат базы при этом ОДИН И ТОТ ЖЕ —
 * файл, снятый с планшета, открывается любым sqlite-клиентом, и наоборот.
 */
(function (global) {
    'use strict';

    const DB_NAME  = 'severfoods';
    const STORE    = 'files';
    const KEY      = 'severfoods.db';

    let _idb = null;

    function open() {
        if (_idb) return Promise.resolve(_idb);
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = () => {
                const d = req.result;
                if (!d.objectStoreNames.contains(STORE)) d.createObjectStore(STORE);
            };
            req.onsuccess = () => { _idb = req.result; resolve(_idb); };
            req.onerror   = () => reject(req.error);
        });
    }

    function tx(mode) {
        return open().then(d => d.transaction(STORE, mode).objectStore(STORE));
    }

    /** Читает образ базы. null — базы ещё нет (первый запуск). */
    function load() {
        return tx('readonly').then(store => new Promise((resolve, reject) => {
            const req = store.get(KEY);
            req.onsuccess = () => {
                const v = req.result;
                if (!v) return resolve(null);
                resolve(v instanceof Uint8Array ? v : new Uint8Array(v));
            };
            req.onerror = () => reject(req.error);
        }));
    }

    /** Записывает образ базы. Разрешается только после полного успеха записи. */
    function save(bytes) {
        return tx('readwrite').then(store => new Promise((resolve, reject) => {
            // Копия обязательна: sql.js переиспользует свой буфер, а IndexedDB
            // сохраняет ссылку до конца транзакции — без копии можно записать
            // на диск изменённые «на лету» байты.
            const req = store.put(new Uint8Array(bytes), KEY);
            req.onsuccess = () => resolve(true);
            req.onerror   = () => reject(req.error);
        }));
    }

    global.SFStorage = { load, save, KEY };
})(window);
