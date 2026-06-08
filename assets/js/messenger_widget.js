'use strict';
/**
 * NTRIP Messenger Widget — встраивается на страницы ntrip.host.
 * Показывает кнопку с счётчиком непрочитанных, dropdown с чатами,
 * ссылку для открытия мессенджера.
 *
 * Подключается в конце <body>:
 *   <script src="assets/js/messenger_widget.js"></script>
 */

(function() {

const MESSENGER_URL = window.MESSENGER_SERVER_URL || 'https://ntrip.host/messenger';
const BADGE_API     = window.MESSENGER_API_URL    || 'https://ntrip.host/messenger/api/badge.php';
const POLL_INTERVAL = 30000; // 30 s

// Token stored by the QR-login flow in main site
function getToken() {
    return localStorage.getItem('sf_mobile_token') || sessionStorage.getItem('sf_mobile_token') || '';
}
function getAuthToken() {
    return localStorage.getItem('sf_auth_token') || '';
}

// ── Build widget DOM ─────────────────────────────────────

const style = document.createElement('style');
style.textContent = `
#ntripWidget{
  position:fixed;bottom:24px;right:24px;z-index:9990;font-family:'Onest',system-ui,sans-serif;
}
#ntripWidgetBtn{
  width:52px;height:52px;border-radius:50%;background:#2b9cf2;color:#fff;
  border:none;cursor:pointer;box-shadow:0 4px 20px rgba(43,156,242,.45);
  display:flex;align-items:center;justify-content:center;position:relative;
  transition:transform .15s,box-shadow .15s;
}
#ntripWidgetBtn:hover{transform:scale(1.08);box-shadow:0 6px 28px rgba(43,156,242,.55)}
#ntripWidgetBadge{
  position:absolute;top:-4px;right:-4px;
  background:#f87171;color:#fff;border-radius:10px;
  font-size:10px;font-weight:800;padding:1px 5px;min-width:18px;
  text-align:center;display:none;border:2px solid #fff;
}
#ntripWidgetPanel{
  position:absolute;bottom:62px;right:0;width:300px;
  background:#17212b;border:1px solid rgba(255,255,255,.08);
  border-radius:18px;box-shadow:0 12px 40px rgba(0,0,0,.6);
  overflow:hidden;display:none;
  animation:ntripSlideUp .18s ease;
}
@keyframes ntripSlideUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
#ntripWidgetPanel.open{display:block}
.nw-header{
  background:#232e3c;padding:12px 16px;display:flex;align-items:center;gap:10px;
  border-bottom:1px solid rgba(255,255,255,.07);
}
.nw-header-icon{width:32px;height:32px;border-radius:50%;background:#2b9cf2;
  display:flex;align-items:center;justify-content:center;flex-shrink:0}
.nw-header-title{font-size:14px;font-weight:700;color:#e8eaed;flex:1}
.nw-header-sub{font-size:11px;color:rgba(232,234,237,.5)}
.nw-rooms{max-height:240px;overflow-y:auto}
.nw-room{
  padding:10px 16px;display:flex;align-items:center;gap:10px;
  cursor:pointer;border-bottom:1px solid rgba(255,255,255,.04);
  transition:background .1s;text-decoration:none;
}
.nw-room:hover{background:rgba(255,255,255,.05)}
.nw-room-av{
  width:36px;height:36px;border-radius:50%;background:#2b9cf2;
  display:flex;align-items:center;justify-content:center;
  font-size:14px;font-weight:700;color:#fff;flex-shrink:0;
}
.nw-room-info{flex:1;overflow:hidden}
.nw-room-name{font-size:13px;font-weight:600;color:#e8eaed;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nw-room-last{font-size:11px;color:rgba(232,234,237,.45);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nw-badge{background:#2b9cf2;color:#fff;border-radius:10px;font-size:10px;font-weight:800;padding:1px 5px;min-width:16px;text-align:center;flex-shrink:0}
.nw-empty{padding:24px 16px;text-align:center;font-size:13px;color:rgba(232,234,237,.4)}
.nw-footer{
  padding:12px 16px;border-top:1px solid rgba(255,255,255,.07);
  display:flex;gap:8px;
}
.nw-open-btn{
  flex:1;background:#2b9cf2;color:#fff;border:none;border-radius:10px;
  font-size:13px;font-weight:700;font-family:inherit;padding:9px 12px;
  cursor:pointer;text-align:center;text-decoration:none;
  display:flex;align-items:center;justify-content:center;gap:6px;
  transition:opacity .15s;
}
.nw-open-btn:hover{opacity:.88}
.nw-dl-btn{
  background:#232e3c;color:rgba(232,234,237,.7);border:1px solid rgba(255,255,255,.07);
  border-radius:10px;font-size:12px;font-family:inherit;padding:9px 10px;
  cursor:pointer;text-decoration:none;display:flex;align-items:center;
  transition:background .15s;
}
.nw-dl-btn:hover{background:#2b3a4a;color:#e8eaed}
.nw-loader{padding:20px;text-align:center;color:rgba(232,234,237,.4);font-size:12px}
`;
document.head.appendChild(style);

const wrap = document.createElement('div');
wrap.id = 'ntripWidget';
wrap.innerHTML = `
<div id="ntripWidgetPanel">
  <div class="nw-header">
    <div class="nw-header-icon">
      <svg viewBox="0 0 24 24" fill="#fff" width="16" height="16"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
    </div>
    <div>
      <div class="nw-header-title">NTRIP Messenger</div>
      <div class="nw-header-sub" id="nwStatusLine">Загрузка…</div>
    </div>
  </div>
  <div class="nw-rooms" id="nwRoomList"><div class="nw-loader">Загрузка чатов…</div></div>
  <div class="nw-footer">
    <a class="nw-open-btn" id="nwOpenBtn" href="${MESSENGER_URL}" target="_blank">
      <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
      Открыть мессенджер
    </a>
    <a class="nw-dl-btn" href="${MESSENGER_URL}?download=apk" target="_blank" title="Скачать приложение">
      <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
    </a>
  </div>
</div>
<button id="ntripWidgetBtn" title="NTRIP Messenger">
  <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
  <span id="ntripWidgetBadge">0</span>
</button>
`;
document.body.appendChild(wrap);

const panel  = document.getElementById('ntripWidgetPanel');
const btn    = document.getElementById('ntripWidgetBtn');
const badge  = document.getElementById('ntripWidgetBadge');
const status = document.getElementById('nwStatusLine');
const roomList = document.getElementById('nwRoomList');

let panelOpen = false;
let lastUnread = 0;
let pollTimer = null;

// ── Toggle panel ─────────────────────────────────────────

btn.addEventListener('click', function(e) {
    e.stopPropagation();
    panelOpen = !panelOpen;
    panel.classList.toggle('open', panelOpen);
    if (panelOpen) fetchBadge();
});

document.addEventListener('click', function(e) {
    if (panelOpen && !wrap.contains(e.target)) {
        panelOpen = false;
        panel.classList.remove('open');
    }
});

// ── Fetch badge data ─────────────────────────────────────

function fetchBadge() {
    const tok = getAuthToken() || getToken();
    if (!tok) {
        renderNoAuth();
        return;
    }
    const headers = {};
    if (getAuthToken()) headers['X-Auth-Token'] = getAuthToken();
    else headers['X-Mobile-Token'] = getToken();

    fetch(BADGE_API + '?action=unread', { headers })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(data => {
            updateBadge(data.unread || 0);
            renderRooms(data.rooms || []);
            status.textContent = data.unread
                ? `${data.unread} непрочитанных`
                : 'Нет новых сообщений';
        })
        .catch(() => { status.textContent = 'Нет соединения'; });
}

function updateBadge(n) {
    lastUnread = n;
    badge.textContent = n > 99 ? '99+' : n;
    badge.style.display = n > 0 ? '' : 'none';
    // Browser tab badge (if supported)
    if ('setAppBadge' in navigator) navigator.setAppBadge(n).catch(()=>{});
}

function renderNoAuth() {
    roomList.innerHTML = `<div class="nw-empty">
        <div style="margin-bottom:10px">Войдите, чтобы видеть чаты</div>
        <a href="${MESSENGER_URL}" target="_blank" style="color:#2b9cf2;font-size:13px">Открыть мессенджер →</a>
    </div>`;
    status.textContent = 'Не авторизован';
}

const AVATAR_COLORS = ['#1abc9c','#2ecc71','#3498db','#9b59b6','#e74c3c','#e67e22','#2b9cf2','#e91e63'];
function roomColor(id) { return AVATAR_COLORS[id % AVATAR_COLORS.length]; }

function renderRooms(rooms) {
    if (!rooms.length) {
        roomList.innerHTML = '<div class="nw-empty">Нет активных чатов</div>';
        return;
    }
    roomList.innerHTML = rooms.map(r => {
        const initial = (r.name || '?')[0].toUpperCase();
        const color   = roomColor(r.id);
        const badge_  = r.unread > 0 ? `<span class="nw-badge">${r.unread}</span>` : '';
        return `<a class="nw-room" href="${MESSENGER_URL}" target="_blank">
            <div class="nw-room-av" style="background:${color}">${initial}</div>
            <div class="nw-room-info">
              <div class="nw-room-name">${esc(r.name)}</div>
              <div class="nw-room-last">${esc(r.last_msg || '—')}</div>
            </div>
            ${badge_}
        </a>`;
    }).join('');
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Polling ──────────────────────────────────────────────

function startPolling() {
    fetchBadge(); // immediate
    pollTimer = setInterval(fetchBadge, POLL_INTERVAL);
}

// Notify user when new messages arrive (tab in background)
let prevUnread = 0;
setInterval(function() {
    if (lastUnread > prevUnread && document.hidden) {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('NTRIP Messenger', {
                body: `${lastUnread} непрочитанных сообщений`,
                icon: MESSENGER_URL + '/favicon.ico',
                tag: 'ntrip-badge',
                silent: false,
            });
        }
    }
    prevUnread = lastUnread;
}, POLL_INTERVAL);

// ── Start ────────────────────────────────────────────────

startPolling();

})();
