/**
 * =====================================================
 *  CANTEEN ACCESS SYSTEM v2.0 — FRONTEND LOGIC
 * =====================================================
 */

'use strict';

// ── Globals ──────────────────────────────────────────
let scannerMode   = true;

// ── CSRF ─────────────────────────────────────────────
function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}
let cameraStream  = null;
let cameraActive  = false;
let scanInterval  = null;
let animFrame     = null;
let canvas        = null;
let ctx           = null;
let audioCtx      = null;
let notifTimer    = null;
let searchTimer   = null;
let deleteId      = null;
let manualEmpId   = null;
let activeOrgFilter = null;

// ── DOM Helpers ───────────────────────────────────────
const $  = id  => document.getElementById(id);
const $$ = sel => document.querySelectorAll(sel);

function escHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
}

// ── Clock ─────────────────────────────────────────────
function updateClock() {
    const el = $('headerTime');
    if (!el) return;
    const now = new Date();
    el.textContent = now.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
setInterval(updateClock, 1000);
updateClock();

// ── Audio ─────────────────────────────────────────────
function initAudio() {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    return audioCtx;
}

function playBeep(type) {
    try {
        const ctx = initAudio();
        const now = ctx.currentTime;

        if (type === 'success') {
            const osc = ctx.createOscillator(), g = ctx.createGain();
            osc.type = 'sine'; osc.frequency.value = 1046;
            g.gain.value = 0.25;
            osc.connect(g); g.connect(ctx.destination);
            osc.start(); g.gain.exponentialRampToValueAtTime(0.0001, now + 0.45); osc.stop(now + 0.5);
            // Second note
            setTimeout(() => {
                const o2 = ctx.createOscillator(), g2 = ctx.createGain();
                o2.type = 'sine'; o2.frequency.value = 1320;
                g2.gain.value = 0.15;
                o2.connect(g2); g2.connect(ctx.destination);
                o2.start(); g2.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.3); o2.stop(ctx.currentTime + 0.35);
            }, 100);
        } else if (type === 'error') {
            [0, 180, 360].forEach(delay => setTimeout(() => {
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'square'; o.frequency.value = 220;
                g.gain.value = 0.35;
                o.connect(g); g.connect(ctx.destination);
                o.start(); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.1); o.stop(ctx.currentTime + 0.12);
            }, delay));
        } else {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.type = 'sine'; o.frequency.value = 660;
            g.gain.value = 0.18;
            o.connect(g); g.connect(ctx.destination);
            o.start(); g.gain.exponentialRampToValueAtTime(0.0001, now + 0.2); o.stop(now + 0.22);
        }
    } catch(e) {}
}

// ── Notifications ─────────────────────────────────────
function showNotif(data) {
    const notif = $('notification');
    if (!notif) return;

    const type    = data.success ? 'success' : (data.code === 'ALREADY_ATE' ? 'warning' : 'error');
    const icons   = { success:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-5"/></svg>', warning:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12" y2="17"/></svg>', error:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>', info:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="8"/></svg>' };
    const icon    = icons[type] || '•';

    let title = data.message || '';
    let sub   = '';

    if (data.success && data.employee) {
        const emp = data.employee;
        sub = [
            emp.organization,
            data.meal_type ? getMealName(data.meal_type) : '',
            data.price ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1110.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/></svg> ' + data.price : '',
            data.point ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg> ' + data.point : '',
        ].filter(Boolean).join(' · ');
    }

    notif.className = `notif ${type}`;
    notif.innerHTML = `
        <div class="notif-inner">
            <div class="notif-icon">${icon}</div>
            <div class="notif-body">
                <div class="notif-title">${escHtml(title)}</div>
                ${sub ? `<div class="notif-sub">${escHtml(sub)}</div>` : ''}
            </div>
        </div>
        <div class="notif-bar"></div>
    `;
    notif.style.display = 'block';

    playBeep(type === 'success' ? 'success' : type === 'warning' ? 'info' : 'error');

    // Flash body
    document.body.classList.remove('flash-success', 'flash-error');
    void document.body.offsetWidth;
    document.body.classList.add(data.success ? 'flash-success' : 'flash-error');

    clearTimeout(notifTimer);
    notifTimer = setTimeout(() => { notif.style.display = 'none'; }, 5000);
}

function getMealName(type) {
    return { breakfast:'Завтрак', lunch:'Обед', dinner:'Ужин', night:'Ночное' }[type] || type;
}

// ── Scanner Mode ──────────────────────────────────────
function setScannerMode(active) {
    scannerMode = active;
    const inp     = $('qrInput');
    const pill    = $('scannerPill');
    const dot     = $('scannerDot');
    const badge   = $('modeBadge');
    const manBtn  = $('manualBtn');

    if (!inp) return;

    if (active) {
        pill?.classList.remove('off'); pill?.classList.add('on');
        dot?.classList.remove('off');
        if (badge) { badge.className = 'mode-badge'; badge.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg> Режим сканирования'; }
        if (manBtn) manBtn.style.display = 'none';
        inp.classList.add('scanner-active');
        inp.placeholder = 'Наведите сканер на QR-код…';
    } else {
        pill?.classList.remove('on'); pill?.classList.add('off');
        dot?.classList.add('off');
        if (badge) { badge.className = 'mode-badge manual'; badge.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8"/></svg> Ручной ввод'; }
        if (manBtn) manBtn.style.display = 'inline-flex';
        inp.classList.remove('scanner-active');
        inp.placeholder = 'Введите QR-код вручную…';
    }
}

function toggleScanner() {
    setScannerMode(!scannerMode);
    const inp = $('qrInput');
    if (inp) inp.focus();
}

// ── QR Form Submit ────────────────────────────────────
function submitQR(value) {
    if (!value.trim()) return;
    const inp = $('qrInput');

    fetch('index.php', {
        method:  'POST',
        headers: {
            'Content-Type':    'application/x-www-form-urlencoded',
            'X-Requested-With':'XMLHttpRequest',
            'X-CSRF-Token':    getCsrfToken(),
        },
        body: 'qr_data=' + encodeURIComponent(value.trim()),
    })
    .then(r => r.json())
    .then(data => {
        showNotif(data);
        if (inp) { inp.value = ''; inp.focus(); }
        // Refresh stats after 1s
        setTimeout(refreshStats, 1000);
    })
    .catch(() => {
        showNotif({ success:false, message:'Ошибка соединения с сервером', code:'NET_ERR' });
        if (inp) inp.focus();
    });
}

// Auto-submit: Enter → отправка QR
// (Конвертация раскладки обрабатывается в qr-input.js)
document.addEventListener('DOMContentLoaded', () => {
    const inp = $('qrInput');
    if (!inp) return;

    // Enter в поле — отправка
    inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (inp.value.trim()) submitQR(inp.value);
        }
    });

    const form = $('scanForm');
    if (form) {
        form.addEventListener('submit', e => {
            e.preventDefault();
            if (inp.value.trim()) submitQR(inp.value);
        });
    }
});

// ── Stats refresh ─────────────────────────────────────
function refreshStats() {
    fetch('api/stats.php', { headers:{ 'X-Requested-With':'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
        const keys = ['total','breakfast','lunch','dinner','night'];
        keys.forEach(k => {
            const el = $('stat_' + k);
            if (el && data[k] !== undefined) {
                const prev = parseInt(el.textContent) || 0;
                const next = parseInt(data[k]) || 0;
                el.textContent = next;
                if (next > prev) {
                    el.style.transition = 'color .3s';
                    el.style.color = 'var(--success)';
                    setTimeout(() => { el.style.color = ''; }, 1200);
                }
            }
        });
    })
    .catch(() => {});
}

// Auto-refresh stats every 30s
setInterval(refreshStats, 30000);

// ── Camera ────────────────────────────────────────────
async function openCamera() {
    const overlay = $('cameraOverlay');
    const video   = $('cameraVideo');
    if (!overlay || !video) return;

    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode:{ ideal:'environment' }, width:{ ideal:1280 } }
        });
        video.srcObject = cameraStream;
        await video.play();
        overlay.classList.add('open');
        cameraActive = true;
        canvas = canvas || document.createElement('canvas');
        ctx    = ctx    || canvas.getContext('2d');
        scanQRFromCamera(video);
    } catch(e) {
        alert('Не удалось открыть камеру: ' + e.message);
    }
}

function closeCamera() {
    cameraActive = false;
    cancelAnimationFrame(animFrame);
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
    const overlay = $('cameraOverlay');
    if (overlay) overlay.classList.remove('open');
}

function scanQRFromCamera(video) {
    if (!cameraActive || !window.jsQR) return;
    animFrame = requestAnimationFrame(() => {
        if (!cameraActive) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code    = jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts:'dontInvert' });
            if (code) {
                closeCamera();
                submitQR(code.data);
                return;
            }
        }
        scanQRFromCamera(video);
    });
}

// ── Employee Search ───────────────────────────────────
function renderOrgChips() {
    const container = $('orgChips');
    if (!container || !window.orgStats) return;

    const all = window.allEmployeesData || [];
    const opCnt    = all.filter(e => e.role === 'operator').length;
    const admCnt   = all.filter(e => e.role === 'admin' || e.role === 'super_admin').length;

    const roleChips = [];
    if (opCnt)  roleChips.push(`<button class="org-chip" data-role-filter="operator" style="border-color:#2563eb20;background:#eff6ff"><div class="org-chip-name" style="color:#1d4ed8">Операторы</div><div class="org-chip-count" style="color:#1d4ed8">${opCnt}</div><div class="org-chip-label">чел.</div></button>`);
    if (admCnt) roleChips.push(`<button class="org-chip" data-role-filter="admin"    style="border-color:#7c3aed20;background:#f5f3ff"><div class="org-chip-name" style="color:#6d28d9">Администраторы</div><div class="org-chip-count" style="color:#6d28d9">${admCnt}</div><div class="org-chip-label">чел.</div></button>`);

    if (!window.orgStats.length && !roleChips.length) { container.innerHTML = ''; return; }

    container.innerHTML =
        roleChips.join('') +
        window.orgStats.map((o, idx) =>
            '<button class="org-chip" data-idx="' + idx + '">' +
            '<div class="org-chip-name">' + escHtml(o.organization) + '</div>' +
            '<div class="org-chip-count">' + o.cnt + '</div>' +
            '<div class="org-chip-label">сотрудников</div>' +
            '</button>'
        ).join('');

    container.onclick = function(ev) {
        const btn = ev.target.closest('.org-chip');
        if (!btn) return;
        $$('.org-chip').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');

        const roleFilter = btn.dataset.roleFilter;
        if (roleFilter) {
            // Показываем список по роли
            const match = roleFilter === 'operator'
                ? e => e.role === 'operator'
                : e => e.role === 'admin' || e.role === 'super_admin';
            const list = all.filter(match).sort((a,b) => a.full_name.localeCompare(b.full_name,'ru'));
            const label = roleFilter === 'operator' ? 'Операторы' : 'Администраторы';
            openOrgModal(label, list);
            return;
        }
        const idx = parseInt(btn.dataset.idx, 10);
        if (!isNaN(idx) && window.orgStats[idx]) {
            openOrgModal(window.orgStats[idx].organization);
        }
    };
}

function filterByOrg(org) {
    // Открываем модальное окно со списком сотрудников организации
    openOrgModal(org);
}

function openOrgModal(org, prebuiltList) {
    const titleEl = $('orgModalTitle');
    const bodyEl  = $('orgModalBody');
    if (!titleEl || !bodyEl) return;

    titleEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg> ' + escHtml(org);

    let list;
    if (prebuiltList) {
        list = prebuiltList;
    } else {
        // Нормализуем: trim + collapse spaces + decode HTML entities
        function normalizeOrg(s) {
            if (!s) return '';
            const tmp = document.createElement('textarea');
            tmp.innerHTML = s;
            return tmp.value.trim().replace(/\s+/g, ' ');
        }
        const orgNorm = normalizeOrg(org);
        list = (window.allEmployeesData || [])
            .filter(e => normalizeOrg(e.organization) === orgNorm)
            .sort((a, b) => a.full_name.localeCompare(b.full_name, 'ru'));
    }

    if (!list.length) {
        bodyEl.innerHTML = '<div class="empty"><div class="empty-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>Нет сотрудников</div>';
    } else {
        bodyEl.innerHTML = `
            <div style="font-size:12px;color:var(--text-3);padding:0 0 10px;border-bottom:1px solid var(--border);margin-bottom:12px">
                Всего: <strong>${list.length}</strong> сотрудников
            </div>
            <div class="emp-table-wrap">
                <table class="emp-table">
                    <thead>
                        <tr>
                            <th>Сотрудник</th>
                            <th>QR-статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>${renderOrgRows(list)}</tbody>
                </table>
            </div>`;
    }

    openModal('orgModal');
}

function renderOrgRows(list) {
    const statusLabels = { active:'Активен', expired:'Истёк', blocked:'Заблок.' };
    return list.map(function(e) {
        const sc   = e.qr_status || 'active';
        const warn = e.expiry_status === 'expired' ? '<svg width="8" height="8" viewBox="0 0 24 24" style="color:#e53935"><circle cx="12" cy="12" r="10" fill="currentColor"/></svg>' : (e.expiry_status === 'warning' ? '<svg width="8" height="8" viewBox="0 0 24 24" style="color:#f59e0b"><circle cx="12" cy="12" r="10" fill="currentColor"/></svg>' : '');
        const exp  = e.qr_expires_at
            ? '<div style="font-size:10px;color:#94a3b8;margin-top:2px">до ' + escHtml(e.qr_expires_at) + '</div>' : '';
        const nameJson = JSON.stringify(e.full_name).replace(/[<>&]/g, function(s){return s==='<'?'\u003c':s==='>'?'\u003e':'\u0026';});

        const safeName = escHtml(e.full_name).replace(/'/g, "\\'");
        let actions = '<button class="btn-sm green" title="Пропустить вручную"'
            + ' onclick="openManualFromOrg(' + e.id + ',\'' + safeName + '\')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></button>'
            + '<button class="btn-sm" title="Статистика питания"'
            + ' data-stats-id="' + e.id + '" data-stats-name="' + escHtml(e.full_name) + '">'
            + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>'
            + '</button>'
            + '<a class="btn-sm" href="print_qr.php?id=' + e.id + '" target="_blank" title="Печать QR"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg></a>';

        if (window.isAdmin) {
            actions += '<button class="btn-sm" title="Редактировать"'
                + ' onclick="closeModal(&quot;orgModal&quot;);openEditModal(' + e.id + ')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>';
        }
        if (window.isSuperAdmin) {
            actions += '<button class="btn-sm danger" title="Удалить"'
                + ' onclick="closeModal(&quot;orgModal&quot;);openDeleteModal(' + e.id + ',' + nameJson + ')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg></button>';
        }

        return '<tr class="emp-row" data-emp-id="' + e.id + '" data-emp-name="' + escHtml(e.full_name) + '" style="cursor:pointer" title="Статистика питания">'
            + '<td><div class="emp-name">' + escHtml(e.full_name) + '</div>'
            + '<div class="emp-org">' + escHtml(e.department || '') + '</div></td>'
            + '<td><span class="qr-status-badge ' + sc + '">' + (statusLabels[sc] || sc) + '</span>'
            + (warn ? ' <span>' + warn + '</span>' : '') + exp + '</td>'
            + '<td><div class="emp-actions">' + actions + '</div></td>'
            + '</tr>';
    }).join('');
}

function searchEmployees(q) {
    activeOrgFilter = null;
    $$('.org-chip').forEach(c => c.classList.remove('active'));
    if (!q.trim()) { renderEmployeeTable([]); $('empTableWrap').style.display='none'; return; }
    const lq = q.trim().toLowerCase();

    // Ключевые слова роли: полное совпадение слова → фильтр по роли
    const roleKeywords = {
        'оператор':        r => r === 'operator',
        'операторы':       r => r === 'operator',
        'администратор':   r => r === 'admin' || r === 'super_admin',
        'администраторы':  r => r === 'admin' || r === 'super_admin',
        'супер':           r => r === 'super_admin',
        'суперадмин':      r => r === 'super_admin',
        'super_admin':     r => r === 'super_admin',
        'operator':        r => r === 'operator',
        'admin':           r => r === 'admin' || r === 'super_admin',
    };
    if (roleKeywords[lq]) {
        const match = roleKeywords[lq];
        const chip = document.querySelector(`.org-chip[data-role-filter]`);
        const res = window.allEmployeesData.filter(e => match(e.role || ''));
        renderEmployeeTable(res);
        return;
    }

    const res = window.allEmployeesData.filter(e =>
        e.full_name.toLowerCase().includes(lq) ||
        (e.organization || '').toLowerCase().includes(lq) ||
        (e.department   || '').toLowerCase().includes(lq)
    );
    renderEmployeeTable(res);
}

function renderEmployeeTable(list) {
    const wrap   = $('empTableWrap');
    const tbody  = $('empTbody');
    const clear  = $('searchClear');

    if (!wrap || !tbody) return;

    if (clear) clear.style.display = list.length || $('searchInput')?.value ? 'block' : 'none';

    if (!list.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';

    tbody.innerHTML = list.map(e => {
        const statusClass = e.qr_status || 'active';
        const statusLabels = { active:'Активен', expired:'Истёк', blocked:'Заблок.' };
        const expiryWarn = e.expiry_status === 'expired' ? '<svg width="8" height="8" viewBox="0 0 24 24" style="color:#e53935"><circle cx="12" cy="12" r="10" fill="currentColor"/></svg>' : (e.expiry_status === 'warning' ? '<svg width="8" height="8" viewBox="0 0 24 24" style="color:#f59e0b"><circle cx="12" cy="12" r="10" fill="currentColor"/></svg>' : '');

        return `
        <tr class="emp-row" data-emp-id="${e.id}" data-emp-name="${escHtml(e.full_name)}" style="cursor:pointer" title="Статистика питания">
            <td>
                <div class="emp-name">${escHtml(e.full_name)}</div>
                <div class="emp-org">${escHtml(e.organization)}${e.department ? ' · '+escHtml(e.department) : ''}</div>
            </td>
            <td>
                <span class="qr-status-badge ${statusClass}">${statusLabels[statusClass] || statusClass}</span>
                ${expiryWarn ? `<span style="margin-left:4px">${expiryWarn}</span>` : ''}
                ${e.qr_expires_at ? `<div style="font-size:10px;color:var(--text-4);margin-top:2px">до ${e.qr_expires_at}</div>` : ''}
            </td>
            <td>
                <div class="emp-actions">
                    <button class="btn-sm green" title="Пропустить вручную"
                        onclick="openManualModal(${e.id},'${escHtml(e.full_name).replace(/'/g,"&#39;")}');event.stopPropagation()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></button>
                    <button class="btn-sm" title="Статистика питания" data-stats-id="${e.id}" data-stats-name="${escHtml(e.full_name)}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>
                    </button>
                    <a class="btn-sm" href="print_qr.php?id=${e.id}" target="_blank" title="Печать QR" onclick="event.stopPropagation()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg></a>
                    ${window.isAdmin ? `
                        <button class="btn-sm" title="Редактировать" onclick="openEditModal(${e.id});event.stopPropagation()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                        ${window.isSuperAdmin ? `<button class="btn-sm danger" title="Удалить" onclick="openDeleteModal(${e.id},'${escHtml(e.full_name).replace(/'/g,"&#39;")}');event.stopPropagation()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg></button>` : ''}
                    ` : ''}
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ── Массовая проводка ─────────────────────────────────
let bpSelectedIds = new Set();
let bpSelectedNames = new Map(); // id → ФИО, чтобы показывать выбранных вне текущего поиска

function bpSearchEmployees(q) {
    const wrap = document.getElementById('bpResultsList');
    if (!wrap) return;
    const lq = q.trim().toLowerCase();
    if (!lq) {
        wrap.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-3);font-size:13px">Введите фамилию для поиска</div>';
        bpUpdateCount();
        return;
    }
    const list = (window.allEmployeesData || []).filter(e => e.full_name.toLowerCase().includes(lq));
    if (!list.length) {
        wrap.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-3);font-size:13px">Никого не найдено</div>';
        bpUpdateCount();
        return;
    }
    wrap.innerHTML = list.map(e => `
        <label style="display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--border);cursor:pointer">
            <input type="checkbox" class="bp-check" value="${e.id}" data-name="${escHtml(e.full_name)}"
                ${bpSelectedIds.has(String(e.id)) ? 'checked' : ''} onchange="bpToggle(this)">
            <div>
                <div style="font-weight:600;font-size:13px">${escHtml(e.full_name)}</div>
                <div style="font-size:11px;color:var(--text-3)">${escHtml(e.organization || '')}${e.department ? ' · '+escHtml(e.department) : ''}</div>
            </div>
        </label>
    `).join('');
}

function bpToggle(cb) {
    if (cb.checked) {
        bpSelectedIds.add(cb.value);
        bpSelectedNames.set(cb.value, cb.dataset.name || cb.value);
    } else {
        bpSelectedIds.delete(cb.value);
        bpSelectedNames.delete(cb.value);
    }
    bpUpdateCount();
}

function bpSelectAll(select) {
    document.querySelectorAll('.bp-check').forEach(cb => {
        cb.checked = select;
        if (select) {
            bpSelectedIds.add(cb.value);
            bpSelectedNames.set(cb.value, cb.dataset.name || cb.value);
        } else {
            bpSelectedIds.delete(cb.value);
            bpSelectedNames.delete(cb.value);
        }
    });
    bpUpdateCount();
}

function bpRemoveSelected(id) {
    bpSelectedIds.delete(id);
    bpSelectedNames.delete(id);
    const cb = document.querySelector(`.bp-check[value="${id}"]`);
    if (cb) cb.checked = false;
    bpUpdateCount();
}

function bpUpdateCount() {
    const countEl = document.getElementById('bpSelectedCount');
    if (countEl) countEl.textContent = bpSelectedIds.size;

    const wrap = document.getElementById('bpSelectedWrap');
    const list = document.getElementById('bpSelectedList');
    if (!wrap || !list) return;

    if (!bpSelectedIds.size) {
        wrap.style.display = 'none';
        list.innerHTML = '';
        return;
    }
    wrap.style.display = 'block';
    list.innerHTML = Array.from(bpSelectedIds).map(id => `
        <span style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--border);border-radius:20px;padding:4px 6px 4px 12px;font-size:12px">
            ${escHtml(bpSelectedNames.get(id) || id)}
            <button type="button" onclick="bpRemoveSelected('${id}')" title="Убрать из выбора"
                style="border:none;background:var(--bg-deep);color:var(--text-3);width:18px;height:18px;border-radius:50%;cursor:pointer;font-size:11px;line-height:1;display:flex;align-items:center;justify-content:center;flex-shrink:0">✕</button>
        </span>
    `).join('');
}

async function bpSubmit() {
    const date     = document.getElementById('bpDate').value;
    const mealType = document.getElementById('bpMealType').value;
    const pointId  = document.getElementById('bpPointId')?.value || null;
    const msgEl    = document.getElementById('bpResultMsg');
    const ids      = Array.from(bpSelectedIds).map(Number);

    if (!date) { alert('Укажите дату'); return; }
    if (!ids.length) { alert('Выберите хотя бы одного сотрудника'); return; }

    msgEl.innerHTML = '<span style="color:var(--text-3)">Выполняется…</span>';
    try {
        const res = await fetch('api/bulk_pass.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
            body: JSON.stringify({ date, meal_type: mealType, employee_ids: ids, point_id: pointId }),
        });
        const data = await res.json();
        if (!data.success) {
            msgEl.innerHTML = `<div class="notif error"><div class="notif-inner"><div class="notif-icon">❌</div><div class="notif-body"><div class="notif-title">${escHtml(data.message || 'Ошибка')}</div></div></div></div>`;
            return;
        }
        let html = `<div class="notif success"><div class="notif-inner"><div class="notif-icon">✅</div><div class="notif-body">`
                 + `<div class="notif-title">Проведено: ${data.inserted.length}</div>`;
        if (data.inserted.length) {
            html += `<div style="font-size:12px;margin-top:4px">${data.inserted.map(e => escHtml(e.name)).join(', ')}</div>`;
        }
        html += `</div></div></div>`;
        if (data.already.length) {
            html += `<div class="notif" style="background:#fff7ed;border-color:#fed7aa;margin-top:8px"><div class="notif-inner"><div class="notif-icon">ℹ️</div><div class="notif-body">`
                  + `<div class="notif-title">Уже отмечены ранее на эту дату: ${data.already.length}</div>`
                  + `<div style="font-size:12px;margin-top:4px">${data.already.map(e => escHtml(e.name)).join(', ')}</div>`
                  + `</div></div></div>`;
        }
        msgEl.innerHTML = html;

        // Сброс выбора после успешного проведения
        bpSelectedIds.clear();
        bpSelectedNames.clear();
        bpUpdateCount();
        document.querySelectorAll('.bp-check').forEach(cb => { cb.checked = false; });
    } catch (e) {
        msgEl.innerHTML = '<div class="notif error"><div class="notif-inner"><div class="notif-icon">❌</div><div class="notif-body"><div class="notif-title">Ошибка сети</div></div></div></div>';
    }
}

// ── Manual Pass из org-модала (сначала закрываем orgModal) ──────
function openManualFromOrg(id, name) {
    closeModal('orgModal');
    openManualModal(id, name);
}
window.openManualFromOrg = openManualFromOrg;

// ── Manual Pass Modal ────────────────────────────────
function openManualModal(id, name) {
    manualEmpId = id;
    const nameEl = $('manualEmpName');
    if (nameEl) nameEl.textContent = name;
    // Pre-select current meal type
    const sel = $('manualMealType');
    if (sel && window.CURRENT_MEAL && window.CURRENT_MEAL !== 'none') {
        sel.value = window.CURRENT_MEAL;
    }
    openModal('manualModal');
}
function closeManualModal() { closeModal('manualModal'); }

function confirmManualPass() {
    if (!manualEmpId) return;
    const mealType = $('manualMealType')?.value;
    const reason   = $('manualReason')?.value || '';

    fetch('manual_pass.php', {
        method:  'POST',
        headers: {
            'Content-Type':    'application/json',
            'X-Requested-With':'XMLHttpRequest',
            'X-CSRF-Token':    getCsrfToken(),
        },
        body: JSON.stringify({ employee_id: manualEmpId, meal_type: mealType, reason }),
    })
    .then(r => r.json())
    .then(data => {
        closeManualModal();
        showNotif(data);
        if (data.success) setTimeout(refreshStats, 800);
    })
    .catch(() => showNotif({ success:false, message:'Ошибка сервера' }));
}

// ── Employee Modal (Add / Edit) ───────────────────────
function openAddModal() {
    $('empModalTitle').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Добавление сотрудника';
    $('empForm').reset();
    $('editId').value = '';
    $('regenerateQrRow')?.setAttribute('style','display:none');
    $('empIsActive').checked = true;
    fillOrgDatalist();
    openModal('empModal');
}

function fillOrgDatalist() {
    const dl = document.getElementById('orgDatalist');
    if (!dl || !window.ORG_LIST) return;
    dl.innerHTML = window.ORG_LIST.map(o => `<option value="${o.replace(/"/g,'&quot;')}">`).join('');
}

function openEditModal(id) {
    fetch('get_employee.php?id=' + id, { headers:{ 'X-Requested-With':'XMLHttpRequest' } })
    .then(r => r.json())
    .then(emp => {
        $('empModalTitle').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Редактирование';
        $('editId').value       = emp.id;
        $('empFullName').value  = emp.full_name || '';
        $('empBirthDate').value = emp.birth_date || '';
        $('empOrg').value       = emp.organization || '';
        $('empDept').value      = emp.department || '';
        $('empPos').value       = emp.position || '';
        $('empVjg').value       = emp.vjg_type || '';
        $('empExpires').value   = emp.qr_expires_at || '';
        $('empQrStatus').value  = emp.qr_status || 'active';
        $('empIsActive').checked = emp.is_active == 1;
        if ($('empRole')) $('empRole').value = emp.role || '';
        if ($('empPointId')) $('empPointId').value = emp.assigned_point_id || '';
        const pg = $('pointSelectGroup');
        if (pg) pg.style.display = ['admin','operator','super_admin'].includes(emp.role || '') ? '' : 'none';
        $('regenerateQrRow')?.removeAttribute('style');
        openModal('empModal');
    })
    .catch(() => alert('Ошибка загрузки данных сотрудника'));
}

document.addEventListener('DOMContentLoaded', () => {
    const roleEl = document.getElementById('empRole');
    if (roleEl) {
        roleEl.addEventListener('change', function(){
            const role = this.value;
            const pg = document.getElementById('pointSelectGroup');
            if (pg) pg.style.display = ['admin','operator','super_admin'].includes(role) ? '' : 'none';
        });
    }

    const form = $('empForm');
    if (!form) return;
    form.addEventListener('submit', e => {
        e.preventDefault();
        const id = $('editId').value;
        const isEdit = !!id;

        const data = {
            full_name:       $('empFullName').value,
            birth_date:      $('empBirthDate').value,
            organization:    $('empOrg').value,
            department:      $('empDept').value || '',
            position:        $('empPos').value  || '',
            vjg_type:        $('empVjg').value  || '',
            qr_expires_at:   $('empExpires').value || null,
            qr_status:       $('empQrStatus').value,
            is_active:       $('empIsActive').checked ? 1 : 0,
            role:            $('empRole')?.value || null,
            regenerate_qr:   $('regenerateQr')?.checked || false,
            assigned_point_id: $('empPointId')?.value || null,
        };
        if (isEdit) data.id = parseInt(id);

        const url = isEdit ? 'update_employee.php' : 'add_employee.php';
        fetch(url, {
            method:  'POST',
            headers: {
                'Content-Type':    'application/json',
                'X-Requested-With':'XMLHttpRequest',
                'X-CSRF-Token':    getCsrfToken(),
            },
            body: JSON.stringify(data),
        })
        .then(r => r.json())
        .then(res => {
            const msgEl = $('empMsg');
            if (res.success) {
                closeModal('empModal');
                showNotif({ success:true, message: isEdit ? 'Сотрудник обновлён' : 'Сотрудник добавлен' });
                setTimeout(() => location.reload(), 1200);
            } else {
                if (msgEl) { msgEl.className='msg error'; msgEl.textContent=res.message; }
            }
        })
        .catch(() => {
            const msgEl = $('empMsg');
            if (msgEl) { msgEl.className='msg error'; msgEl.textContent='Ошибка сервера'; }
        });
    });

});

// ── Delete Modal ──────────────────────────────────────
function openDeleteModal(id, name) {
    deleteId = id;
    const el = $('deleteEmpName');
    if (el) el.textContent = name;
    openModal('deleteModal');
}
function closeDeleteModal() { closeModal('deleteModal'); }

function confirmDelete() {
    if (!deleteId) return;
    const btn = $('confirmDeleteBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 22h14M5 2h14M17 22v-4.172a2 2 0 00-.586-1.414L12 12M7 22v-4.172a2 2 0 01.586-1.414L12 12M7 2v4.172a2 2 0 00.586 1.414L12 12M17 2v4.172a2 2 0 01-.586 1.414L12 12"/></svg> Удаляю…'; }

    fetch('delete_employee.php', {
        method:  'POST',
        headers: {
            'Content-Type':    'application/json',
            'X-Requested-With':'XMLHttpRequest',
            'X-CSRF-Token':    getCsrfToken(),
        },
        body: JSON.stringify({ id: deleteId }),
    })
    .then(r => r.json())
    .then(data => {
        closeModal('deleteModal');
        showNotif(data);
        if (data.success) {
            // Убираем строку из таблицы без перезагрузки
            const rowInTable = document.querySelector(`button[onclick*="openDeleteModal(${deleteId},"]`);
            if (rowInTable) rowInTable.closest('tr')?.remove();
            // Обновляем счётчик и org-chips
            setTimeout(() => location.reload(), 1200);
        } else {
            if (btn) { btn.disabled = false; btn.textContent = 'Да, удалить'; }
        }
    })
    .catch(() => {
        closeModal('deleteModal');
        showNotif({ success: false, message: 'Ошибка сервера при удалении' });
        if (btn) { btn.disabled = false; btn.textContent = 'Да, удалить'; }
    });
}

// ── Schedule Modal ────────────────────────────────────
const DAYS = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
const MEAL_TYPES = [
    { val:'breakfast', label:'Завтрак' },
    { val:'lunch',     label:'Обед'    },
    { val:'dinner',    label:'Ужин'    },
    { val:'night',     label:'Ночное'  },
];

function openScheduleModal() {
    loadSchedule();
    openModal('scheduleModal');
}

function loadSchedule() {
    const sel = $('schedulePoint');
    if (!sel || !sel.value) return;

    fetch('api_schedule.php?action=get&point_id=' + sel.value, { headers:{ 'X-Requested-With':'XMLHttpRequest' } })
    .then(r => r.json())
    .then(rows => renderScheduleRows(rows))
    .catch(() => {});
}

function renderScheduleRows(rows) {
    const tbody = $('scheduleRows');
    if (!tbody) return;
    tbody.innerHTML = '';
    (rows || []).forEach(r => addScheduleRow(r));
}

function addScheduleRow(data) {
    const tbody = $('scheduleRows');
    if (!tbody) return;

    const tr    = document.createElement('tr');
    const rowId = Date.now() + Math.random();
    const days  = (data?.days_of_week || '1,2,3,4,5,6,7').split(',');

    tr.innerHTML = `
        <td>
            <select name="meal_type" style="min-width:90px">
                ${MEAL_TYPES.map(m => `<option value="${m.val}"${data?.meal_type===m.val?' selected':''}>${m.label}</option>`).join('')}
            </select>
        </td>
        <td><input type="text" name="meal_name_ru" value="${escHtml(data?.meal_name_ru||'')}" placeholder="Название" style="min-width:90px"></td>
        <td><input type="time" name="start_time" value="${(data?.start_time||'').substring(0,5)}" required></td>
        <td><input type="time" name="end_time"   value="${(data?.end_time||'').substring(0,5)}"   required></td>
        <td>
            <div class="day-checks">
                ${DAYS.map((d,i) => {
                    const val = i+1;
                    const chk = days.includes(String(val)) ? 'checked' : '';
                    const uid = `day_${rowId}_${val}`;
                    return `<input type="checkbox" class="day-check" id="${uid}" name="dow_${rowId}" value="${val}" ${chk}>
                            <label for="${uid}" title="${d}">${d}</label>`;
                }).join('')}
            </div>
        </td>
        <td><input type="number" name="sort_order" value="${data?.sort_order||0}" style="width:50px" min="0"></td>
        <td><button type="button" class="btn-sm danger" onclick="this.closest('tr').remove()" title="Удалить"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg></button></td>
    `;
    tbody.appendChild(tr);
}

function saveSchedule() {
    const sel = $('schedulePoint');
    if (!sel?.value) return alert('Выберите точку питания');

    const tbody = $('scheduleRows');
    const rows  = [];
    tbody.querySelectorAll('tr').forEach((tr, i) => {
        const days = [...tr.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);
        rows.push({
            meal_type:    tr.querySelector('[name=meal_type]')?.value,
            meal_name_ru: tr.querySelector('[name=meal_name_ru]')?.value || '',
            start_time:   tr.querySelector('[name=start_time]')?.value,
            end_time:     tr.querySelector('[name=end_time]')?.value,
            days_of_week: days.join(',') || '1,2,3,4,5,6,7',
            sort_order:   tr.querySelector('[name=sort_order]')?.value || i,
        });
    });

    fetch('api_schedule.php', {
        method:  'POST',
        headers: {
            'Content-Type':    'application/json',
            'X-Requested-With':'XMLHttpRequest',
            'X-CSRF-Token':    getCsrfToken(),
        },
        body: JSON.stringify({ action:'save', point_id: parseInt(sel.value), schedules: rows }),
    })
    .then(r => r.json())
    .then(data => {
        const msgEl = $('scheduleMsg');
        if (data.success) {
            if (msgEl) { msgEl.className='msg success'; msgEl.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-5"/></svg> Расписание сохранено!'; }
            setTimeout(() => closeModal('scheduleModal'), 1500);
        } else {
            if (msgEl) { msgEl.className='msg error'; msgEl.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg> '+data.message; }
        }
    })
    .catch(() => {
        const msgEl = $('scheduleMsg');
        if (msgEl) { msgEl.className='msg error'; msgEl.textContent='Ошибка сервера'; }
    });
}

// ── Modal helpers ─────────────────────────────────────
// Стек открытых модалов — поддержка вложенных окон
let _modalStack = [];

function openModal(id) {
    const el = $(id);
    if (!el) return;
    el.classList.add('open');
    if (!_modalStack.includes(id)) _modalStack.push(id);
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    const el = $(id);
    if (el) el.classList.remove('open');
    _modalStack = _modalStack.filter(m => m !== id);
    // Снимаем overflow только если нет больше открытых модалов
    if (_modalStack.length === 0) document.body.style.overflow = '';
}

function closeAllModals() {
    _modalStack.slice().forEach(id => closeModal(id));
}
// Close on overlay click
document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        const id = e.target.id;
        closeModal(id || '');
        if (!id) { e.target.classList.remove('open'); }
        return;
    }
    // Stats button or clickable emp-row → open stats modal
    const statsBtn = e.target.closest('[data-stats-id]');
    if (statsBtn) {
        e.stopPropagation();
        openEmpStats(+statsBtn.dataset.statsId, statsBtn.dataset.statsName);
        return;
    }
    const empRow = e.target.closest('.emp-row[data-emp-id]');
    if (empRow && !e.target.closest('button') && !e.target.closest('a')) {
        openEmpStats(+empRow.dataset.empId, empRow.dataset.empName);
    }
});
// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        $$('.modal-overlay.open').forEach(m => m.classList.remove('open'));
        document.body.style.overflow = '';
    }
});

// ── Tabs ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    $$('.tab-btn[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;
            $$('.tab-btn').forEach(b => b.classList.remove('active'));
            $$('.tab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            $(target)?.classList.add('active');
        });
    });

    // Search
    const searchInp = $('searchInput');
    if (searchInp) {
        searchInp.addEventListener('input', () => {
            clearTimeout(searchTimer);
            const q = searchInp.value;
            const clear = $('searchClear');
            if (clear) clear.style.display = q ? 'block' : 'none';
            searchTimer = setTimeout(() => searchEmployees(q), 200);
        });
    }

    const clearBtn = $('searchClear');
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            const inp = $('searchInput');
            if (inp) { inp.value = ''; inp.focus(); }
            clearBtn.style.display = 'none';
            activeOrgFilter = null;
            $$('.org-chip').forEach(c => c.classList.remove('active'));
            $('empTableWrap').style.display = 'none';
        });
    }

    // Render org chips on load
    renderOrgChips();

    // Focus QR input
    const qrInp = $('qrInput');
    if (qrInp) { qrInp.focus(); setScannerMode(true); }

    // Schedule point change (old modal, keep for compat)
    const schSel = $('schedulePoint');
    if (schSel) schSel.addEventListener('change', loadSchedule);

    const addRowBtn = $('addScheduleRow');
    if (addRowBtn) addRowBtn.addEventListener('click', () => addScheduleRow(null));

    const saveSchBtn = $('saveSchedule');
    if (saveSchBtn) saveSchBtn.addEventListener('click', saveSchedule);

    // Schedule Tab (inline in admin panel)
    if ($('schedulePointTab')) {
        initScheduleTab();
    }

    const addRowTabBtn = $('addScheduleRowTab');
    if (addRowTabBtn) addRowTabBtn.addEventListener('click', () => addScheduleRowTab(null));

    const saveTabBtn = $('saveScheduleTab');
    if (saveTabBtn) saveTabBtn.addEventListener('click', saveScheduleTab);

    // When schedule tab is clicked — init if not done
    $$('.tab-btn[data-tab="tabSchedule"]').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!$('schedulePointTab') || $('schedulePointTab').options.length <= 1) {
                initScheduleTab();
            }
        });
    });

    // Manual confirm btn
    const manConfirm = $('confirmManualBtn');
    if (manConfirm) manConfirm.addEventListener('click', confirmManualPass);

    // Delete confirm
    const delConfirm = $('confirmDeleteBtn');
    if (delConfirm) delConfirm.addEventListener('click', confirmDelete);
});


// ── Schedule Tab (inline in admin panel) ─────────────
function initScheduleTab() {
    // Загружаем список точек через API
    fetch('api_schedule.php?action=points', { headers:{ 'X-Requested-With':'XMLHttpRequest' } })
    .then(r => r.json())
    .then(pts => {
        const sel = $('schedulePointTab');
        if (!sel) return;
        pts.forEach(pt => {
            const opt = document.createElement('option');
            opt.value = pt.id;
            opt.textContent = pt.point_name + (pt.city ? ' — ' + pt.city : '');
            sel.appendChild(opt);
        });
        // Если точка уже выбрана в сессии — предвыбираем
        if (window.mealPointId) {
            sel.value = window.mealPointId;
            loadScheduleTab();
        } else {
            $('scheduleTabEmpty').style.display = 'block';
        }
    })
    .catch(() => {
        const msg = $('scheduleTabMsg');
        if (msg) { msg.className='msg error'; msg.textContent='Не удалось загрузить список точек'; }
    });
}

function loadScheduleTab() {
    const sel = $('schedulePointTab');
    if (!sel || !sel.value) {
        $('scheduleTabEmpty').style.display = 'block';
        $('scheduleTabTableWrap').style.display = 'none';
        return;
    }
    $('scheduleTabEmpty').style.display = 'none';
    $('scheduleTabTableWrap').style.display = 'none';
    $('scheduleTabLoading').style.display = 'block';
    const msg = $('scheduleTabMsg');
    if (msg) msg.className = '';

    fetch('api_schedule.php?action=get&point_id=' + sel.value, { headers:{ 'X-Requested-With':'XMLHttpRequest' } })
    .then(r => r.json())
    .then(rows => {
        $('scheduleTabLoading').style.display = 'none';
        const tbody = $('scheduleRowsTab');
        if (tbody) tbody.innerHTML = '';
        if (Array.isArray(rows) && rows.length > 0) {
            rows.forEach(r => addScheduleRowTab(r));
        } else {
            // Пустое расписание — добавляем 4 строки по умолчанию
            const defaults = [
                { meal_type:'breakfast', meal_name_ru:'Завтрак',        start_time:'07:00', end_time:'10:00', days_of_week:'1,2,3,4,5,6,7', sort_order:1 },
                { meal_type:'lunch',     meal_name_ru:'Обед',           start_time:'12:00', end_time:'15:00', days_of_week:'1,2,3,4,5,6,7', sort_order:2 },
                { meal_type:'dinner',    meal_name_ru:'Ужин',           start_time:'18:00', end_time:'21:00', days_of_week:'1,2,3,4,5,6,7', sort_order:3 },
                { meal_type:'night',     meal_name_ru:'Ночное питание', start_time:'23:00', end_time:'06:00', days_of_week:'1,2,3,4,5,6,7', sort_order:4 },
            ];
            defaults.forEach(d => addScheduleRowTab(d));
        }
        $('scheduleTabTableWrap').style.display = 'block';
    })
    .catch(err => {
        $('scheduleTabLoading').style.display = 'none';
        const msg = $('scheduleTabMsg');
        if (msg) { msg.className='msg error'; msg.textContent='Ошибка загрузки расписания: '+err.message; }
    });
}

function addScheduleRowTab(data) {
    const tbody = $('scheduleRowsTab');
    if (!tbody) return;
    const tr    = document.createElement('tr');
    const rowId = 'r' + Date.now() + Math.floor(Math.random()*1000);
    const days  = ((data && data.days_of_week) ? String(data.days_of_week) : '1,2,3,4,5,6,7').split(',').map(s=>s.trim());

    tr.innerHTML = `
        <td>
            <select name="meal_type">
                ${MEAL_TYPES.map(m => '<option value="'+m.val+'"'+(data&&data.meal_type===m.val?' selected':'')+'>'+m.label+'</option>').join('')}
            </select>
        </td>
        <td><input type="text" name="meal_name_ru" value="${escHtml((data&&data.meal_name_ru)||'')}" placeholder="Название"></td>
        <td><input type="time" name="start_time" value="${((data&&data.start_time)||'').substring(0,5)}" required></td>
        <td><input type="time" name="end_time"   value="${((data&&data.end_time)||'').substring(0,5)}"   required></td>
        <td>
            <div class="day-checks">
                ${DAYS.map((d,i) => {
                    const val = String(i+1);
                    const chk = days.includes(val) ? 'checked' : '';
                    const uid = rowId+'_d'+val;
                    return '<input type="checkbox" class="day-check" id="'+uid+'" value="'+val+'" '+chk+'>'
                         + '<label for="'+uid+'" title="'+d+'">'+d+'</label>';
                }).join('')}
            </div>
        </td>
        <td><input type="number" name="sort_order" value="${(data&&data.sort_order!=null)?data.sort_order:0}" style="width:50px" min="0"></td>
        <td><button type="button" class="btn-sm danger" onclick="this.closest('tr').remove()" title="Удалить строку"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg></button></td>
    `;
    tbody.appendChild(tr);
}

function saveScheduleTab() {
    const sel = $('schedulePointTab');
    const msg = $('scheduleTabMsg');

    if (!sel || !sel.value) {
        if (msg) { msg.className='msg error'; msg.textContent='Выберите точку питания'; }
        return;
    }

    const tbody = $('scheduleRowsTab');
    if (!tbody) return;

    const rows = [];
    tbody.querySelectorAll('tr').forEach((tr, i) => {
        const mt = tr.querySelector('[name=meal_type]')?.value    || '';
        const st = tr.querySelector('[name=start_time]')?.value   || '';
        const et = tr.querySelector('[name=end_time]')?.value     || '';
        const nm = tr.querySelector('[name=meal_name_ru]')?.value || '';
        const so = tr.querySelector('[name=sort_order]')?.value   || i;
        const checkedDays = [...tr.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);

        if (!mt || !st || !et) return;
        rows.push({
            meal_type:    mt,
            meal_name_ru: nm,
            start_time:   st,
            end_time:     et,
            days_of_week: checkedDays.length ? checkedDays.join(',') : '1,2,3,4,5,6,7',
            sort_order:   parseInt(so) || i,
        });
    });

    if (!rows.length) {
        if (msg) { msg.className='msg error'; msg.textContent='Добавьте хотя бы один приём пищи'; }
        return;
    }

    const saveBtn = $('saveScheduleTab');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 22h14M5 2h14M17 22v-4.172a2 2 0 00-.586-1.414L12 12M7 22v-4.172a2 2 0 01.586-1.414L12 12M7 2v4.172a2 2 0 00.586 1.414L12 12M17 2v4.172a2 2 0 01-.586 1.414L12 12"/></svg> Сохраняю…'; }

    fetch('api_schedule.php', {
        method:  'POST',
        headers: {
            'Content-Type':    'application/json',
            'X-Requested-With':'XMLHttpRequest',
            'X-CSRF-Token':    getCsrfToken(),
        },
        body: JSON.stringify({ point_id: parseInt(sel.value), schedules: rows }),
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(data => {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Сохранить'; }
        if (!msg) return;
        if (data && data.success) {
            msg.className = 'msg success';
            msg.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-5"/></svg> ' + (data.message || 'Расписание сохранено');
            setTimeout(() => { if (msg) msg.className = ''; }, 3000);
        } else {
            msg.className = 'msg error';
            msg.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg> ' + (data && data.message ? data.message : 'Неизвестная ошибка');
        }
    })
    .catch(err => {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Сохранить'; }
        if (msg) { msg.className='msg error'; msg.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg> Ошибка соединения: '+err.message; }
    });
}


// ── Excel Export ──────────────────────────────────────
function copySyncToken(btn) {
    const field = document.getElementById('syncTokenField');
    if (!field) return;
    navigator.clipboard.writeText(field.value).then(() => {
        const icon = btn.querySelector('i');
        const prevClass = icon.className;
        icon.className = 'fas fa-check';
        btn.style.borderColor = 'var(--success, #16a34a)';
        setTimeout(() => { icon.className = prevClass; btn.style.borderColor = ''; }, 1500);
    }).catch(() => { field.select(); document.execCommand('copy'); });
}

function openExcelExport(e) {
    e.preventDefault();
    const start = document.querySelector('[name=start_date]')?.value || '';
    const end   = document.querySelector('[name=end_date]')?.value   || '';
    const mt    = document.querySelector('[name=meal_type]')?.value  || 'all';
    const pid   = document.querySelector('[name=point_id]')?.value   || '';
    const url = 'export_excel.php?start_date=' + encodeURIComponent(start)
              + '&end_date='   + encodeURIComponent(end)
              + '&meal_type='  + encodeURIComponent(mt)
              + (pid ? '&point_id=' + encodeURIComponent(pid) : '');
    window.open(url, '_blank');
}
window.openExcelExport = openExcelExport;

// ── Employee Stats Modal ──────────────────────────────
let _empStatsId = null;

function openEmpStats(id, name) {
    _empStatsId = id;
    const modal = $('empStatsModal');
    if (!modal) return;
    closeAllModals();
    $('empStatsName').textContent = name;
    // Default: last 30 days + next 30 days (чтобы видеть запланированное выездное питание)
    const now  = new Date();
    const from = new Date(); from.setDate(now.getDate() - 30);
    const to   = new Date(); to.setDate(now.getDate() + 30);
    $('empStatsFrom').value = from.toISOString().slice(0,10);
    $('empStatsTo').value   = to.toISOString().slice(0,10);
    $('empStatsResult').innerHTML = '';
    const sec = $('empRationsSection');
    if (sec) sec.style.display = 'none';
    openModal('empStatsModal');
    loadEmpStats();
}

async function loadEmpStats() {
    if (!_empStatsId) return;
    const from = $('empStatsFrom').value;
    const to   = $('empStatsTo').value;
    const res  = $('empStatsResult');
    res.innerHTML = '<div style="text-align:center;color:var(--text-3);padding:20px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg> Загрузка...</div>';
    try {
        const d = await fetch(`api/employee_stats.php?id=${_empStatsId}&from=${from}&to=${to}`).then(r=>r.json());
        if (!d.ok) { res.innerHTML = '<div class="empty">Ошибка загрузки</div>'; return; }
        const mealLabels = { breakfast:'Завтрак', lunch:'Обед', dinner:'Ужин', snack:'Перекус', night:'Ночное' };
        const rows = Object.entries(d.by_type).map(([k,v]) =>
            `<tr><td>${mealLabels[k]||k}</td><td><strong>${v}</strong></td></tr>`
        ).join('');
        const dryTotal = (d.dry_ration_count||0) + (d.field_count||0);
        const dryCard = dryTotal > 0
            ? `<div style="flex:1;text-align:center;background:#fef9c3;border-radius:10px;padding:12px 8px">
                    <div style="font-size:30px;font-weight:800;color:#92400e">${dryTotal}</div>
                    <div style="font-size:11px;color:#92400e;margin-top:2px">сух. паёк / выездн.</div>
                    ${d.dry_ration_count>0?`<div style="font-size:10px;color:#b45309;margin-top:2px">паёк: ${d.dry_ration_count}</div>`:''}
                    ${d.field_count>0?`<div style="font-size:10px;color:#b45309">выездн.: ${d.field_count}</div>`:''}
               </div>` : '';
        res.innerHTML = `
            <div style="display:flex;gap:12px;margin-bottom:16px">
                <div style="flex:1;text-align:center;background:var(--bg-input,#f8fafc);border-radius:10px;padding:12px 8px">
                    <div style="font-size:30px;font-weight:800;color:var(--blue-700,#003366)">${d.total}</div>
                    <div style="font-size:11px;color:var(--text-3,#64748b);margin-top:2px">приёмов пищи</div>
                </div>
                <div style="flex:1;text-align:center;background:var(--bg-input,#f8fafc);border-radius:10px;padding:12px 8px">
                    <div style="font-size:30px;font-weight:800;color:var(--blue-700,#003366)">${d.days}</div>
                    <div style="font-size:11px;color:var(--text-3,#64748b);margin-top:2px">дней в столовой</div>
                </div>
                ${dryCard}
            </div>
            ${rows ? `<table class="emp-table"><thead><tr><th>Тип приёма</th><th>Кол-во</th></tr></thead><tbody>${rows}</tbody></table>` : '<div class="empty" style="padding:12px">Нет приёмов в столовой за период</div>'}`;

        // Show rations section
        const sec = $('empRationsSection');
        if (sec) {
            sec.style.display = '';
            // Default range for new entry: tomorrow to tomorrow
            const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
            const tStr = tomorrow.toISOString().slice(0,10);
            if ($('empRationDateFrom') && !$('empRationDateFrom').value) $('empRationDateFrom').value = tStr;
            if ($('empRationDateTo')   && !$('empRationDateTo').value)   $('empRationDateTo').value   = tStr;
            loadRations();
        }
    } catch(e) {
        res.innerHTML = '<div class="empty">Ошибка сети</div>';
    }
}

async function loadRations() {
    if (!_empStatsId) return;
    const from = $('empStatsFrom').value;
    const to   = $('empStatsTo').value;
    // Show rations from stats-from to +90 days (future)
    const futureEnd = new Date(); futureEnd.setDate(futureEnd.getDate() + 90);
    const ratTo = futureEnd.toISOString().slice(0,10);
    const list = $('empRationsList');
    const countEl = $('empRationsCount');
    const addBtn = $('empRationAddBtn');
    const today = new Date().toISOString().slice(0,10);
    try {
        const d = await fetch(`api/dry_rations.php?employee_id=${_empStatsId}&from=${from}&to=${ratTo}`).then(r=>r.json());
        if (!d.ok) return;
        const typeLabels = { dry_ration:'Сухой паёк', field:'Выездное питание' };
        // Count only those within stats period for limit display
        const inPeriod = d.items.filter(r => r.ration_date >= from && r.ration_date <= to).length;
        const atLimit = inPeriod >= 4;
        countEl.textContent = `(${inPeriod}/4 в периоде${d.count > inPeriod ? ', всего: '+d.count : ''})`;
        countEl.style.color = atLimit ? '#dc2626' : 'var(--blue-700)';
        if (addBtn) { addBtn.style.opacity = atLimit ? '0.4' : '1'; addBtn.disabled = atLimit; }

        if (!d.items.length) {
            list.innerHTML = '<div style="font-size:12px;color:var(--text-3);margin-bottom:6px">Нет записей</div>';
        } else {
            list.innerHTML = d.items.map(r => {
                const isFuture   = r.ration_date > today;
                const isCancelled = r.status === 'cancelled';
                // colour coding
                let dotColor, dateColor, badge;
                if (isCancelled) {
                    dotColor  = '#dc2626';
                    dateColor = '#dc2626';
                    badge     = '<span style="font-size:10px;background:#fee2e2;color:#dc2626;border-radius:4px;padding:1px 5px;margin-left:4px">отменено — был в столовой</span>';
                } else if (isFuture) {
                    dotColor  = '#b45309';
                    dateColor = '#b45309';
                    badge     = '<span style="font-size:10px;background:#fef3c7;color:#92400e;border-radius:4px;padding:1px 5px;margin-left:4px">запланировано</span>';
                } else {
                    dotColor  = '#16a34a';
                    dateColor = 'inherit';
                    badge     = '<span style="font-size:10px;background:#dcfce7;color:#15803d;border-radius:4px;padding:1px 5px;margin-left:4px">выполнено</span>';
                }
                const canDelete = !isCancelled;
                return `<div style="display:flex;align-items:center;gap:6px;padding:6px 0;border-bottom:1px solid var(--border)">
                    <span style="width:8px;height:8px;border-radius:50%;background:${dotColor};flex-shrink:0"></span>
                    <span style="font-size:13px;font-weight:700;min-width:82px;color:${dateColor}">${r.ration_date.split('-').reverse().join('.')}</span>
                    <span style="font-size:12px;color:var(--text-3)">${typeLabels[r.ration_type]||r.ration_type}</span>
                    ${badge}
                    <span style="flex:1"></span>
                    ${canDelete ? `<button onclick="deleteRation(${r.id})" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:2px 4px" title="Удалить">×</button>` : ''}
                </div>`;
            }).join('');
        }
    } catch(e) {}
}

async function addRation() {
    if (!_empStatsId) return;
    const from     = $('empStatsFrom').value;
    const to       = $('empStatsTo').value;
    const dateFrom = $('empRationDateFrom').value;
    const dateTo   = $('empRationDateTo').value || dateFrom;
    const type     = $('empRationType').value;
    const msgEl    = $('empRationsMsg');

    if (!dateFrom) { msgEl.textContent = 'Укажите дату начала'; msgEl.style.display=''; return; }
    if (dateTo < dateFrom) { msgEl.textContent = 'Дата «по» раньше «с»'; msgEl.style.display=''; return; }
    msgEl.style.display = 'none';

    const d = await fetch('api/dry_rations.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/json' },
        body: JSON.stringify({ employee_id: _empStatsId, ration_date_from: dateFrom, ration_date_to: dateTo, ration_type: type, from, to }),
    }).then(r=>r.json()).catch(()=>({ok:false,error:'Ошибка сети'}));

    if (!d.ok) { msgEl.textContent = d.error || 'Ошибка'; msgEl.style.display=''; return; }
    // Refresh stats too (days count changed)
    loadEmpStats();
}

async function deleteRation(id) {
    const d = await fetch(`api/dry_rations.php?id=${id}`, { method:'DELETE' }).then(r=>r.json()).catch(()=>({ok:false}));
    if (d.ok) loadEmpStats();
}

window.openEmpStats   = openEmpStats;
window.loadEmpStats   = loadEmpStats;
window.addRation      = addRation;
window.deleteRation   = deleteRation;
window.closeAllModals = closeAllModals;

// ── Expose globals for inline onclick ───────────────
window.showManualPass     = openManualModal;
window.openManualFromOrg  = openManualFromOrg;
window.openEditModal      = openEditModal;
window.openDeleteModal    = openDeleteModal;
window.openScheduleModal  = openScheduleModal;
window.toggleScanner      = toggleScanner;
window.openCamera         = openCamera;
window.closeCamera        = closeCamera;
window.closeManualModal   = closeManualModal;
window.closeDeleteModal   = closeDeleteModal;
window.openAddModal       = openAddModal;
window.filterByOrg        = filterByOrg;
window.openOrgModal       = openOrgModal;
