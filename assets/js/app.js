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
    const icons   = { success:'<i class="fas fa-check-circle"></i>', warning:'<i class="fas fa-exclamation-triangle"></i>', error:'<i class="fas fa-times-circle"></i>', info:'<i class="fas fa-info-circle"></i>' };
    const icon    = icons[type] || '•';

    let title = data.message || '';
    let sub   = '';

    if (data.success && data.employee) {
        const emp = data.employee;
        sub = [
            emp.organization,
            data.meal_type ? getMealName(data.meal_type) : '',
            data.price ? '<i class="fas fa-coins"></i> ' + data.price : '',
            data.point ? '<i class="fas fa-map-marker-alt"></i> ' + data.point : '',
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
        if (badge) { badge.className = 'mode-badge'; badge.innerHTML = '<i class="fas fa-crosshairs"></i> Режим сканирования'; }
        if (manBtn) manBtn.style.display = 'none';
        inp.classList.add('scanner-active');
        inp.placeholder = 'Наведите сканер на QR-код…';
    } else {
        pill?.classList.remove('on'); pill?.classList.add('off');
        dot?.classList.add('off');
        if (badge) { badge.className = 'mode-badge manual'; badge.innerHTML = '<i class="fas fa-keyboard"></i> Ручной ввод'; }
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

    if (!window.orgStats.length) { container.innerHTML = ''; return; }

    // Храним порядковый индекс в data-idx, чтобы избежать проблем
    // с HTML-эскейпингом названий организаций при сравнении
    container.innerHTML = window.orgStats.map((o, idx) =>
        '<button class="org-chip" data-idx="' + idx + '">' +
        '<div class="org-chip-name">' + escHtml(o.organization) + '</div>' +
        '<div class="org-chip-count">' + o.cnt + '</div>' +
        '<div class="org-chip-label">сотрудников</div>' +
        '</button>'
    ).join('');

    // Делегирование — один слушатель на контейнере
    container.onclick = function(ev) {
        const btn = ev.target.closest('.org-chip');
        if (!btn) return;
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

function openOrgModal(org) {
    const titleEl = $('orgModalTitle');
    const bodyEl  = $('orgModalBody');
    if (!titleEl || !bodyEl) return;

    titleEl.innerHTML = '<i class="fas fa-users"></i> ' + org;

    // Нормализуем: trim + collapse spaces + decode HTML entities
    function normalizeOrg(s) {
        if (!s) return '';
        // Декодируем HTML-сущности через DOM
        const tmp = document.createElement('textarea');
        tmp.innerHTML = s;
        return tmp.value.trim().replace(/\s+/g, ' ');
    }
    const orgNorm = normalizeOrg(org);

    // Фильтруем и сортируем по ФИО
    const list = (window.allEmployeesData || [])
        .filter(e => normalizeOrg(e.organization) === orgNorm)
        .sort((a, b) => a.full_name.localeCompare(b.full_name, 'ru'));

    if (!list.length) {
        bodyEl.innerHTML = '<div class="empty"><div class="empty-icon"><i class="fas fa-user"></i></div>Нет сотрудников</div>';
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
        const warn = e.expiry_status === 'expired' ? '<i class="fas fa-circle" style="color:#e53935"></i>' : (e.expiry_status === 'warning' ? '<i class="fas fa-circle" style="color:#f59e0b"></i>' : '');
        const exp  = e.qr_expires_at
            ? '<div style="font-size:10px;color:#94a3b8;margin-top:2px">до ' + escHtml(e.qr_expires_at) + '</div>' : '';
        const nameJson = JSON.stringify(e.full_name).replace(/[<>&]/g, function(s){return s==='<'?'\u003c':s==='>'?'\u003e':'\u0026';});

        const safeName = escHtml(e.full_name).replace(/'/g, "\\'");
        let actions = '<button class="btn-sm" title="Статистика питания"'
            + ' data-stats-id="' + e.id + '" data-stats-name="' + escHtml(e.full_name) + '"><i class="fas fa-chart-bar"></i></button>'
            + '<button class="btn-sm green" title="Пропустить вручную"'
            + ' onclick="openManualFromOrg(' + e.id + ',\'' + safeName + '\')"><i class="fas fa-sign-out-alt"></i></button>'
            + '<a class="btn-sm" href="print_qr.php?id=' + e.id + '" target="_blank" title="Печать QR"><i class="fas fa-print"></i></a>';

        if (window.isAdmin) {
            actions += '<button class="btn-sm" title="Редактировать"'
                + ' onclick="closeModal(&quot;orgModal&quot;);openEditModal(' + e.id + ')"><i class="fas fa-pencil-alt"></i></button>';
        }
        if (window.isSuperAdmin) {
            actions += '<button class="btn-sm danger" title="Удалить"'
                + ' onclick="closeModal(&quot;orgModal&quot;);openDeleteModal(' + e.id + ',' + nameJson + ')"><i class="fas fa-trash"></i></button>';
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
    const lq = q.toLowerCase();
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
        const expiryWarn = e.expiry_status === 'expired' ? '<i class="fas fa-circle" style="color:#e53935"></i>' : (e.expiry_status === 'warning' ? '<i class="fas fa-circle" style="color:#f59e0b"></i>' : '');

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
                    <button class="btn-sm" title="Статистика питания" data-stats-id="${e.id}" data-stats-name="${escHtml(e.full_name)}"><i class='fas fa-chart-bar'></i></button>
                    <button class="btn-sm green" title="Пропустить вручную"
                        onclick="openManualModal(${e.id},'${escHtml(e.full_name).replace(/'/g,"&#39;")}');event.stopPropagation()"><i class='fas fa-sign-out-alt'></i></button>
                    <a class="btn-sm" href="print_qr.php?id=${e.id}" target="_blank" title="Печать QR" onclick="event.stopPropagation()"><i class='fas fa-print'></i></a>
                    ${window.isAdmin ? `
                        <button class="btn-sm" title="Редактировать" onclick="openEditModal(${e.id});event.stopPropagation()"><i class='fas fa-pencil-alt'></i></button>
                        ${window.isSuperAdmin ? `<button class="btn-sm danger" title="Удалить" onclick="openDeleteModal(${e.id},'${escHtml(e.full_name).replace(/'/g,"&#39;")}');event.stopPropagation()"><i class='fas fa-trash'></i></button>` : ''}
                    ` : ''}
                </div>
            </td>
        </tr>`;
    }).join('');
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
    $('empModalTitle').innerHTML = '<i class="fas fa-plus"></i> Добавление сотрудника';
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
        $('empModalTitle').innerHTML = '<i class="fas fa-pencil-alt"></i> Редактирование';
        $('editId').value       = emp.id;
        $('empFullName').value  = emp.full_name || '';
        $('empBirthDate').value = emp.birth_date || '';
        $('empOrg').value       = emp.organization || '';
        $('empDept').value      = emp.department || '';
        $('empPos').value       = emp.position || '';
        $('empVjg').value       = emp.vjg_type || '';
        $('empPrice').value     = emp.price || '';
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
            price:           $('empPrice').value  || 0,
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

    // VJG → auto-fill price
    const vjgSel = $('empVjg');
    if (vjgSel) {
        vjgSel.addEventListener('change', () => {
            const opt = vjgSel.options[vjgSel.selectedIndex];
            if (opt && opt.dataset.price) $('empPrice').value = opt.dataset.price;
        });
    }
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
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-hourglass-half"></i> Удаляю…'; }

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
        <td><button type="button" class="btn-sm danger" onclick="this.closest('tr').remove()" title="Удалить"><i class='fas fa-trash'></i></button></td>
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
            if (msgEl) { msgEl.className='msg success'; msgEl.innerHTML='<i class="fas fa-check-circle"></i> Расписание сохранено!'; }
            setTimeout(() => closeModal('scheduleModal'), 1500);
        } else {
            if (msgEl) { msgEl.className='msg error'; msgEl.innerHTML='<i class="fas fa-times-circle"></i> '+data.message; }
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
        <td><button type="button" class="btn-sm danger" onclick="this.closest('tr').remove()" title="Удалить строку"><i class='fas fa-trash'></i></button></td>
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
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-hourglass-half"></i> Сохраняю…'; }

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
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Сохранить'; }
        if (!msg) return;
        if (data && data.success) {
            msg.className = 'msg success';
            msg.innerHTML = '<i class="fas fa-check-circle"></i> ' + (data.message || 'Расписание сохранено');
            setTimeout(() => { if (msg) msg.className = ''; }, 3000);
        } else {
            msg.className = 'msg error';
            msg.innerHTML = '<i class="fas fa-times-circle"></i> ' + (data && data.message ? data.message : 'Неизвестная ошибка');
        }
    })
    .catch(err => {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Сохранить'; }
        if (msg) { msg.className='msg error'; msg.innerHTML='<i class="fas fa-times-circle"></i> Ошибка соединения: '+err.message; }
    });
}


// ── Excel Export ──────────────────────────────────────
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
    // Close any open modal (orgModal, empTableWrap parent, etc.) first
    closeAllModals();
    $('empStatsName').textContent = name;
    // Default dates: last 30 days
    const to   = new Date();
    const from = new Date(); from.setDate(from.getDate() - 30);
    $('empStatsFrom').value = from.toISOString().slice(0,10);
    $('empStatsTo').value   = to.toISOString().slice(0,10);
    $('empStatsResult').innerHTML = '';
    openModal('empStatsModal');
    loadEmpStats();
}

async function loadEmpStats() {
    if (!_empStatsId) return;
    const from = $('empStatsFrom').value;
    const to   = $('empStatsTo').value;
    const res  = $('empStatsResult');
    res.innerHTML = '<div style="text-align:center;color:var(--text-3);padding:20px"><i class="fas fa-spinner fa-spin"></i> Загрузка...</div>';
    try {
        const d = await fetch(`api/employee_stats.php?id=${_empStatsId}&from=${from}&to=${to}`).then(r=>r.json());
        if (!d.ok) { res.innerHTML = '<div class="empty">Ошибка загрузки</div>'; return; }
        const mealLabels = { breakfast:'Завтрак', lunch:'Обед', dinner:'Ужин', snack:'Перекус' };
        const rows = Object.entries(d.by_type).map(([k,v]) =>
            `<tr><td>${mealLabels[k]||k}</td><td><strong>${v}</strong></td></tr>`
        ).join('');
        res.innerHTML = `
            <div style="text-align:center;margin-bottom:16px">
                <div style="font-size:32px;font-weight:800;color:var(--blue-700)">${d.total}</div>
                <div style="font-size:12px;color:var(--text-3)">приёмов пищи за период</div>
            </div>
            ${rows ? `<table class="emp-table"><thead><tr><th>Тип</th><th>Кол-во</th></tr></thead><tbody>${rows}</tbody></table>` : '<div class="empty" style="padding:12px">Нет данных за период</div>'}`;
    } catch(e) {
        res.innerHTML = '<div class="empty">Ошибка сети</div>';
    }
}
window.openEmpStats   = openEmpStats;
window.loadEmpStats   = loadEmpStats;
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
