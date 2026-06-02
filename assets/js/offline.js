'use strict';
/**
 * Canteen Offline Manager v1.0
 * IndexedDB employee cache + scan queue + background sync
 */
window.OfflineManager = (() => {

    // ── Constants ──────────────────────────────────────────
    const DB_NAME  = 'canteen_offline_v1';
    const DB_VER   = 1;
    const S_EMP    = 'employees';
    const S_QUEUE  = 'scan_queue';
    const S_META   = 'meta';
    const SYNC_TAG = 'canteen-offline-sync';

    // ── State ──────────────────────────────────────────────
    let _db            = null;
    let _online        = navigator.onLine;
    let _syncing       = false;
    let _autoSyncTimer = null;

    // ── IndexedDB open ────────────────────────────────────
    function openDB() {
        if (_db) return Promise.resolve(_db);
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VER);
            req.onupgradeneeded = e => {
                const d = e.target.result;
                if (!d.objectStoreNames.contains(S_EMP)) {
                    const s = d.createObjectStore(S_EMP, { keyPath: 'id' });
                    s.createIndex('by_qr', 'qr_code', { unique: false });
                }
                if (!d.objectStoreNames.contains(S_QUEUE)) {
                    const q = d.createObjectStore(S_QUEUE, { keyPath: 'local_id' });
                    q.createIndex('by_time', 'scanned_at');
                }
                if (!d.objectStoreNames.contains(S_META)) {
                    d.createObjectStore(S_META, { keyPath: 'key' });
                }
            };
            req.onsuccess = e => { _db = e.target.result; resolve(_db); };
            req.onerror   = e => reject(e.target.error);
            req.onblocked = () => reject(new Error('IDB blocked'));
        });
    }

    // ── Generic helpers ───────────────────────────────────
    function idbGetAll(store) {
        return openDB().then(d => new Promise((resolve, reject) => {
            const req = d.transaction(store, 'readonly').objectStore(store).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror   = e  => reject(e.target.error);
        }));
    }

    function idbCount(store) {
        return openDB().then(d => new Promise((resolve, reject) => {
            const req = d.transaction(store, 'readonly').objectStore(store).count();
            req.onsuccess = () => resolve(req.result);
            req.onerror   = e  => reject(e.target.error);
        }));
    }

    function idbPut(store, record) {
        return openDB().then(d => new Promise((resolve, reject) => {
            const tx  = d.transaction(store, 'readwrite');
            const req = tx.objectStore(store).put(record);
            tx.oncomplete = () => resolve(record);
            tx.onerror    = e  => reject(e.target.error);
        }));
    }

    function idbDelete(store, key) {
        return openDB().then(d => new Promise((resolve, reject) => {
            const tx = d.transaction(store, 'readwrite');
            tx.objectStore(store).delete(key);
            tx.oncomplete = () => resolve();
            tx.onerror    = e  => reject(e.target.error);
        }));
    }

    // ── Meta helpers ──────────────────────────────────────
    function getMeta(key) {
        return openDB().then(d => new Promise((resolve, reject) => {
            const req = d.transaction(S_META, 'readonly').objectStore(S_META).get(key);
            req.onsuccess = () => resolve(req.result ? req.result.value : null);
            req.onerror   = e  => reject(e.target.error);
        }));
    }

    function setMeta(key, value) {
        return idbPut(S_META, { key, value });
    }

    // ── Employee cache ────────────────────────────────────
    async function cacheEmployees(list) {
        const d = await openDB();
        await new Promise((resolve, reject) => {
            const tx = d.transaction(S_EMP, 'readwrite');
            const st = tx.objectStore(S_EMP);
            st.clear();
            list.forEach(emp => st.put(emp));
            tx.oncomplete = () => resolve();
            tx.onerror    = e  => reject(e.target.error);
        });
        await setMeta('emp_cached_at', Date.now());
        await setMeta('emp_count', list.length);
        return list.length;
    }

    function getEmployeeCount() { return idbCount(S_EMP); }

    function findByQR(qrCode) {
        return openDB().then(d => new Promise((resolve, reject) => {
            const tx  = d.transaction(S_EMP, 'readonly');
            const idx = tx.objectStore(S_EMP).index('by_qr');
            const req = idx.get(qrCode);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror   = e  => reject(e.target.error);
        }));
    }

    // ── Scan queue ────────────────────────────────────────
    function queueScan(scan) {
        const record = {
            local_id:        'lq_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
            qr_code:         scan.qr_code         || '',
            employee_id:     scan.employee_id     || null,
            employee_name:   scan.employee_name   || null,
            meal_type:       scan.meal_type        || null,
            meal_point_id:   scan.meal_point_id   || null,
            meal_point_name: scan.meal_point_name || null,
            scanned_at:      scan.scanned_at       || new Date().toISOString(),
        };
        return idbPut(S_QUEUE, record).then(r => { updateStatusUI(); return r; });
    }

    function getQueue()      { return idbGetAll(S_QUEUE); }
    function getQueueCount() { return idbCount(S_QUEUE); }

    function removeSynced(localIds) {
        if (!localIds || !localIds.length) return Promise.resolve();
        return openDB().then(d => new Promise((resolve, reject) => {
            const tx = d.transaction(S_QUEUE, 'readwrite');
            const st = tx.objectStore(S_QUEUE);
            localIds.forEach(id => st.delete(id));
            tx.oncomplete = () => resolve();
            tx.onerror    = e  => reject(e.target.error);
        }));
    }

    // ── Employee refresh ──────────────────────────────────
    async function refreshEmployees() {
        const resp = await fetch('/offline_sync.php?action=employees', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Server error');
        const count = await cacheEmployees(data.data);
        updateStatusUI();
        return count;
    }

    // ── Queue sync ────────────────────────────────────────
    async function syncQueue() {
        if (_syncing) return { skipped: true };
        const queue = await getQueue();
        if (!queue.length) return { ok: 0, fail: 0, processed: 0 };

        _syncing = true;
        updateStatusUI();

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const resp = await fetch('/offline_sync.php', {
                method:      'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':     'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     csrf,
                },
                body: JSON.stringify({ action: 'batch_scan', scans: queue }),
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const result = await resp.json();

            if (result.success) {
                const doneIds = (result.results || [])
                    .filter(r => r.success || r.code === 'ALREADY_ATE' || r.code === 'REPEAT_SCAN')
                    .map(r => r.local_id)
                    .filter(Boolean);
                await removeSynced(doneIds);
                await setMeta('last_sync_at', Date.now());
                updateStatusUI();
                return result;
            }
            return { ok: 0, fail: queue.length, error: result.error };
        } catch (err) {
            return { ok: 0, fail: 0, error: err.message };
        } finally {
            _syncing = false;
            updateStatusUI();
        }
    }

    // ── Service Worker ────────────────────────────────────
    function registerSW() {
        if (!('serviceWorker' in navigator)) return;
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then(reg => {
                reg.addEventListener('updatefound', () => {
                    const sw = reg.installing;
                    if (!sw) return;
                    sw.addEventListener('statechange', () => {
                        if (sw.state === 'installed' && navigator.serviceWorker.controller) {
                            sw.postMessage({ type: 'SKIP_WAITING' });
                            setTimeout(() => window.location.reload(), 500);
                        }
                    });
                });
            })
            .catch(() => {});

        navigator.serviceWorker.addEventListener('message', ({ data }) => {
            if (data && data.type === 'DO_SYNC' && _online) {
                syncQueue().then(() => updateStatusUI());
            }
        });
    }

    function requestBackgroundSync() {
        if (!('serviceWorker' in navigator)) return;
        navigator.serviceWorker.ready
            .then(reg => reg.sync && reg.sync.register(SYNC_TAG))
            .catch(() => {});
    }

    // ── Status ────────────────────────────────────────────
    async function getStatus() {
        const [queueCount, empCount, lastSync, empCachedAt] = await Promise.all([
            getQueueCount(),
            getEmployeeCount(),
            getMeta('last_sync_at'),
            getMeta('emp_cached_at'),
        ]);
        return {
            online:        _online,
            syncing:       _syncing,
            queueCount:    queueCount  || 0,
            employeeCount: empCount    || 0,
            lastSync:      lastSync    ? new Date(lastSync)    : null,
            empCachedAt:   empCachedAt ? new Date(empCachedAt) : null,
        };
    }

    // ── UI helpers ────────────────────────────────────────
    function fmtTime(d) {
        if (!d) return '—';
        const now     = new Date();
        const isToday = d.toDateString() === now.toDateString();
        const time    = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        if (isToday) return 'сегодня ' + time;
        return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + ' ' + time;
    }

    function escH(s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function updateStatusUI() {
        const chip  = document.getElementById('offlineChip');
        const badge = document.getElementById('offlineQueueBadge');
        const banner = document.getElementById('offlineBanner');

        getStatus().then(st => {
            // Header chip
            if (chip) {
                if (_syncing) {
                    chip.textContent = '🔄 Синхронизация…';
                    chip.className   = 'chip offline-indicator is-syncing';
                } else if (st.online && st.queueCount > 0) {
                    chip.textContent = '🟡 Онлайн · очередь: ' + st.queueCount;
                    chip.className   = 'chip offline-indicator has-queue';
                } else if (st.online) {
                    chip.textContent = '🟢 Онлайн';
                    chip.className   = 'chip offline-indicator is-online';
                } else {
                    chip.textContent = st.queueCount > 0
                        ? '📴 Офлайн · в очереди: ' + st.queueCount
                        : '📴 Офлайн';
                    chip.className = 'chip offline-indicator is-offline';
                }
            }

            // Queue badge on tab button
            if (badge) {
                badge.textContent   = st.queueCount;
                badge.style.display = st.queueCount > 0 ? 'inline-flex' : 'none';
            }

            // Offline banner in scan panel
            if (banner) banner.classList.toggle('show', !st.online);

            updateOfflineTabUI(st);
        });
    }

    function updateOfflineTabUI(st) {
        const ids = ['offlineStatOnline','offlineStatQueue','offlineStatEmps',
                     'offlineStatLastSync','offlineStatEmpCached',
                     'offlineSyncBtn','offlineRefreshBtn'];
        const el = {};
        ids.forEach(id => { el[id] = document.getElementById(id); });
        if (!el.offlineStatOnline) return;

        el.offlineStatOnline.textContent = st.online ? '🟢 Онлайн' : '📴 Офлайн';
        el.offlineStatOnline.className   = 'offline-stat-val ' + (st.online ? 'clr-green' : 'clr-red');

        if (el.offlineStatQueue) {
            el.offlineStatQueue.textContent = st.queueCount;
            el.offlineStatQueue.className   = 'offline-stat-val' + (st.queueCount > 0 ? ' clr-amber' : '');
        }
        if (el.offlineStatEmps)      el.offlineStatEmps.textContent      = st.employeeCount;
        if (el.offlineStatLastSync)  el.offlineStatLastSync.textContent  = fmtTime(st.lastSync);
        if (el.offlineStatEmpCached) el.offlineStatEmpCached.textContent = fmtTime(st.empCachedAt);

        if (el.offlineSyncBtn)    el.offlineSyncBtn.disabled    = !st.online || _syncing || st.queueCount === 0;
        if (el.offlineRefreshBtn) el.offlineRefreshBtn.disabled = !st.online || _syncing;

        renderQueueList();
    }

    async function renderQueueList() {
        const tbody = document.getElementById('offlineQueueTbody');
        if (!tbody) return;
        const queue = await getQueue();
        if (!queue.length) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-4);padding:20px">Очередь пуста — все данные синхронизированы</td></tr>';
            return;
        }
        tbody.innerHTML = queue.map(s =>
            '<tr>'
            + '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escH(s.employee_name || s.qr_code) + '</td>'
            + '<td>' + escH(s.meal_point_name || '—') + '</td>'
            + '<td style="white-space:nowrap;font-size:11px;color:var(--text-3)">' + fmtTime(new Date(s.scanned_at)) + '</td>'
            + '<td><button class="btn-sm danger" title="Удалить из очереди" onclick="OfflineManager._deleteQueueItem(\'' + escH(s.local_id) + '\')">&times;</button></td>'
            + '</tr>'
        ).join('');
    }

    async function deleteQueueItem(localId) {
        await idbDelete(S_QUEUE, localId);
        updateStatusUI();
    }

    // ── Manual sync (called from UI) ──────────────────────
    async function manualSync() {
        const btn = document.getElementById('offlineSyncBtn');
        const msg = document.getElementById('offlineSyncMsg');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Синхронизация…'; }
        try {
            const result = await syncQueue();
            if (msg) {
                const text = result.skipped
                    ? '✅ Нет данных для синхронизации'
                    : '✅ Синхронизовано: ' + (result.ok || 0) + ' успешно, ' + (result.fail || 0) + ' ошибок';
                msg.className = 'msg success'; msg.textContent = text; msg.style.display = 'block';
                setTimeout(() => { msg.style.display = 'none'; }, 5000);
            }
        } catch (e) {
            if (msg) { msg.className = 'msg error'; msg.textContent = '❌ Ошибка: ' + e.message; msg.style.display = 'block'; }
        }
        if (btn) { btn.textContent = '🔄 Синхронизировать очередь'; }
        updateStatusUI();
    }

    // ── Manual employee cache refresh ─────────────────────
    async function manualRefreshEmployees() {
        const btn = document.getElementById('offlineRefreshBtn');
        const msg = document.getElementById('offlineSyncMsg');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Загрузка…'; }
        try {
            const count = await refreshEmployees();
            if (msg) {
                msg.className   = 'msg success';
                msg.textContent = '✅ Кэш сотрудников обновлён: ' + count + ' записей';
                msg.style.display = 'block';
                setTimeout(() => { msg.style.display = 'none'; }, 5000);
            }
        } catch (e) {
            if (msg) {
                msg.className   = 'msg error';
                msg.textContent = '❌ Ошибка обновления кэша: ' + e.message;
                msg.style.display = 'block';
            }
        }
        if (btn) { btn.disabled = false; btn.textContent = '📥 Обновить кэш сотрудников'; }
    }

    // ── Init ─────────────────────────────────────────────
    function init() {
        registerSW();

        window.addEventListener('online', () => {
            _online = true;
            updateStatusUI();
            clearTimeout(_autoSyncTimer);
            _autoSyncTimer = setTimeout(() => {
                syncQueue().catch(() => {});
                requestBackgroundSync();
            }, 2500);
        });

        window.addEventListener('offline', () => {
            _online = false;
            updateStatusUI();
        });

        const ready = fn => {
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
            else fn();
        };

        ready(() => {
            updateStatusUI();

            // Pre-fetch employee cache if online and empty
            if (_online) {
                getEmployeeCount().then(count => {
                    if (count === 0) refreshEmployees().catch(() => {});
                }).catch(() => {});
            }

            // Wire up buttons
            const syncBtn    = document.getElementById('offlineSyncBtn');
            const refreshBtn = document.getElementById('offlineRefreshBtn');
            if (syncBtn)    syncBtn.addEventListener('click', manualSync);
            if (refreshBtn) refreshBtn.addEventListener('click', manualRefreshEmployees);
        });
    }

    // ── Public API ────────────────────────────────────────
    return {
        init,
        isOnline:         () => _online,
        isSyncing:        () => _syncing,
        findByQR,
        queueScan,
        getQueue,
        getQueueCount,
        removeSynced,
        refreshEmployees,
        syncQueue,
        getStatus,
        updateStatusUI,
        manualSync,
        manualRefreshEmployees,
        _deleteQueueItem: deleteQueueItem,
    };
})();

OfflineManager.init();
