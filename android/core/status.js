/**
 * Заряд батареи и состояние сети в шапке рабочего экрана.
 *
 * На планшете приложение работает в полноэкранном режиме, и системная строка
 * состояния скрыта — оператор не видит ни заряда, ни значка Wi-Fi. Поэтому
 * показываем это сами, рядом с типом питания.
 *
 * Блок разметки лежит в общем index.html скрытым; включает его только этот
 * файл, который попадает лишь в Android-сборку. Windows-версия остаётся
 * прежней.
 */
(function (global) {
    'use strict';

    const REFRESH_MS = 30_000; // сеть опрашивается по событиям, это лишь подстраховка

    function el(id) { return document.getElementById(id); }

    // ── Батарея ───────────────────────────────────────────
    // navigator.getBattery() в вебвью Android есть; если вдруг недоступна —
    // показываем прочерк, а не пустое место, чтобы было видно, что данные
    // просто не пришли, а не что заряд нулевой.
    function renderBattery(bat) {
        const node = el('dsBattery');
        if (!node) return;

        if (!bat) { node.innerHTML = '<i class="fas fa-battery-half"></i>—'; return; }

        const pct = Math.round(bat.level * 100);
        let icon = 'fa-battery-full';
        if      (pct <= 10) icon = 'fa-battery-empty';
        else if (pct <= 35) icon = 'fa-battery-quarter';
        else if (pct <= 65) icon = 'fa-battery-half';
        else if (pct <= 90) icon = 'fa-battery-three-quarters';

        // Зарядка важнее уровня: пока питание подключено, низкий заряд не повод
        // тревожить оператора красным.
        const cls = bat.charging ? 'ds-ok' : (pct <= 15 ? 'ds-warn' : '');
        const bolt = bat.charging ? '<i class="fas fa-bolt"></i>' : '';

        node.className = 'ds-item ' + cls;
        node.innerHTML = `<i class="fas ${icon}"></i>${bolt}${pct}%`;
        node.title = bat.charging ? `Заряд ${pct}%, идёт зарядка` : `Заряд ${pct}%`;
    }

    async function initBattery() {
        if (!navigator.getBattery) { renderBattery(null); return; }
        try {
            const bat = await navigator.getBattery();
            const upd = () => renderBattery(bat);
            upd();
            // Перерисовываем по событиям, а не по таймеру: у батареи они есть.
            bat.addEventListener('levelchange', upd);
            bat.addEventListener('chargingchange', upd);
        } catch (_) {
            renderBattery(null);
        }
    }

    // ── Сеть ──────────────────────────────────────────────
    // Показываем именно ПОДКЛЮЧЕНИЕ устройства к сети. Доступность сервера —
    // отдельная вещь, она уже видна в карточке синхронизации: точка может быть
    // в Wi-Fi и при этом без интернета, путать эти два состояния нельзя.
    function renderNetwork() {
        const node = el('dsNetwork');
        if (!node) return;

        if (!navigator.onLine) {
            node.className = 'ds-item ds-warn';
            node.innerHTML = '<i class="fas fa-plane"></i>Нет сети';
            node.title = 'Устройство не подключено к сети';
            return;
        }

        const c    = navigator.connection || {};
        const type = c.type || c.effectiveType || '';
        let icon = 'fa-wifi', label = 'Сеть';

        if (type === 'wifi')          { icon = 'fa-wifi';   label = 'Wi-Fi'; }
        else if (type === 'cellular') { icon = 'fa-signal'; label = 'Моб. сеть'; }
        else if (type === 'ethernet') { icon = 'fa-network-wired'; label = 'Кабель'; }
        else if (/^[234]g$/.test(type)) { icon = 'fa-signal'; label = type.toUpperCase(); }

        node.className = 'ds-item';
        node.innerHTML = `<i class="fas ${icon}"></i>${label}`;
        node.title = 'Устройство подключено к сети';
    }

    function initNetwork() {
        renderNetwork();
        global.addEventListener('online', renderNetwork);
        global.addEventListener('offline', renderNetwork);
        if (navigator.connection && navigator.connection.addEventListener) {
            navigator.connection.addEventListener('change', renderNetwork);
        }
        setInterval(renderNetwork, REFRESH_MS);
    }

    function start() {
        const box = el('deviceStatus');
        if (!box) return;
        box.style.display = ''; // блок существует в общей разметке, но скрыт
        initBattery();
        initNetwork();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window);
