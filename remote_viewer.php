<?php
/**
 * Удалённый просмотр и управление экраном терминала Эвотор через WebRTC.
 *
 * Доступ — только супер-администратору (полный удалённый ввод на чужом
 * терминале — это уровень доступа как минимум не ниже, чем у остальных
 * инструментов в api/remote_access.php, которые тоже отданы только
 * super_admin). Сама передача видео и данных идёт напрямую между браузером
 * и терминалом через coturn (TURN/STUN на ntrip.host) — сервер только
 * пересылает служебные сообщения WebRTC (offer/answer/ice), см.
 * api/remote_access.php и api/offline_sync.php (действия remote_signal*).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    header('Location: index.php');
    exit;
}

$deviceId = trim($_GET['device_id'] ?? '');
$pointName = trim($_GET['point_name'] ?? '');
if ($deviceId === '') { header('Location: index.php'); exit; }
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Удалённый экран — <?= htmlspecialchars($pointName ?: $deviceId, ENT_QUOTES) ?></title>
<?= Csrf::meta() ?>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Onest',sans-serif;background:#0b1220;color:#e2e8f0;height:100vh;display:flex;flex-direction:column}
header{background:#003366;padding:10px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
header h1{font-size:15px;font-weight:700;color:#fff}
header .sub{font-size:12px;color:rgba(255,255,255,.65)}
.status{display:flex;align-items:center;gap:6px;font-size:12px;color:#94a3b8;margin-left:auto}
.dot{width:8px;height:8px;border-radius:50%;background:#64748b}
.dot.connecting{background:#eab308;animation:pulse 1s infinite}
.dot.connected{background:#22c55e}
.dot.failed,.dot.ended{background:#ef4444}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.back{background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:8px;padding:7px 12px;font-family:'Onest',sans-serif;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none}
.back:hover{background:rgba(255,255,255,.25)}
main{flex:1;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;background:#000}
video{max-width:100%;max-height:100%;cursor:crosshair;touch-action:none}
.hint{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);font-size:12px;color:rgba(255,255,255,.5);background:rgba(0,0,0,.4);padding:4px 12px;border-radius:20px}
.placeholder{color:#64748b;font-size:14px;text-align:center;padding:20px}
.placeholder .fa-spinner{font-size:28px;margin-bottom:10px;display:block}
.clipboard-bar{display:flex;gap:8px;padding:10px 14px;background:#0f1a2e;border-top:1px solid rgba(255,255,255,.08)}
.clipboard-bar input{flex:1;background:#1a2740;border:1px solid rgba(255,255,255,.12);border-radius:8px;color:#e2e8f0;padding:8px 12px;font-family:'Onest',sans-serif;font-size:13px}
.clipboard-bar input:focus{outline:none;border-color:#3b82f6}
.cb-btn{background:rgba(255,255,255,.1);color:#e2e8f0;border:none;border-radius:8px;padding:8px 14px;font-family:'Onest',sans-serif;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap}
.cb-btn:hover{background:rgba(255,255,255,.18)}
.cb-btn:disabled{opacity:.4;cursor:not-allowed}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet"></script>
</head>
<body>

<header>
    <div>
        <h1><i class="fas fa-satellite-dish"></i> Удалённый экран</h1>
        <div class="sub"><?= htmlspecialchars($pointName ?: $deviceId, ENT_QUOTES) ?></div>
    </div>
    <div class="status"><span class="dot" id="statusDot"></span><span id="statusText">Запуск сеанса…</span></div>
    <a href="index.php" class="back">← Закрыть сеанс</a>
</header>

<main id="stage">
    <div class="placeholder" id="placeholder"><i class="fas fa-spinner fa-spin"></i>Ждём подключения терминала — обычно до 30 секунд (терминал забирает команду при очередном сеансе связи с сервером).</div>
    <video id="video" autoplay playsinline style="display:none"></video>
    <div class="hint" id="hint" style="display:none">Клик/тап на видео — как на экране терминала</div>
</main>

<div class="clipboard-bar" id="clipboardBar" style="display:none">
    <input type="text" id="clipboardInput" placeholder="Текст для буфера обмена терминала…">
    <button class="cb-btn" id="clipboardSendBtn" title="Отправить в буфер обмена терминала"><i class="fas fa-arrow-right-to-bracket"></i> На терминал</button>
    <button class="cb-btn" id="clipboardGetBtn" title="Забрать текущий буфер обмена терминала (может не сработать — ограничение Android 10+)"><i class="fas fa-arrow-right-from-bracket"></i> С терминала</button>
</div>

<script>
const DEVICE_ID = <?= json_encode($deviceId) ?>;
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

let pc = null, dc = null, sessionId = null, cursor = 0, pollTimer = null, ended = false;

function setStatus(text, cls) {
    document.getElementById('statusText').textContent = text;
    document.getElementById('statusDot').className = 'dot' + (cls ? ' ' + cls : '');
}

async function api(action, body) {
    const opts = body
        ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) }
        : {};
    const r = await fetch(`api/remote_access.php?action=${action}`, opts);
    return r.json();
}

async function start() {
    const res = await api('screen_start', { device_id: DEVICE_ID });
    if (!res.success) { setStatus(res.message || 'Не удалось запустить сеанс', 'failed'); return; }
    sessionId = res.session_id;

    pc = new RTCPeerConnection({ iceServers: res.ice_servers });
    pc.ontrack = (e) => {
        const video = document.getElementById('video');
        video.srcObject = e.streams[0];
        document.getElementById('placeholder').style.display = 'none';
        video.style.display = '';
        document.getElementById('hint').style.display = '';
        setStatus('Подключено', 'connected');
        attachControl(video);
    };
    pc.onicecandidate = (e) => {
        if (e.candidate) api('screen_signal', { session_id: sessionId, type: 'ice', payload: e.candidate.toJSON() });
    };
    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'failed' || pc.connectionState === 'closed') setStatus('Соединение потеряно', 'failed');
    };
    pc.ondatachannel = (e) => {
        dc = e.channel;
        dc.onopen = () => { document.getElementById('clipboardBar').style.display = 'flex'; };
        dc.onmessage = (ev) => {
            let msg; try { msg = JSON.parse(ev.data); } catch (e) { return; }
            if (msg.type === 'clipboard_data') {
                document.getElementById('clipboardInput').value = msg.text || '';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(msg.text || '').catch(() => {});
                }
            }
        };
    };

    setStatus('Ждём терминал…', 'connecting');
    pollTimer = setInterval(poll, 1000);
}

async function poll() {
    if (ended || !sessionId) return;
    let res;
    try {
        const r = await fetch(`api/remote_access.php?action=screen_poll&session_id=${sessionId}&after=${cursor}`);
        res = await r.json();
    } catch (e) { return; }
    if (!res.success) { stop('Сеанс завершён или не найден'); return; }
    if (res.status === 'ended') { stop('Сеанс завершён'); return; }

    for (const sig of res.signals) {
        cursor = Math.max(cursor, sig.id);
        if (sig.type === 'offer') {
            await pc.setRemoteDescription(new RTCSessionDescription(sig.payload));
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            await api('screen_signal', { session_id: sessionId, type: 'answer', payload: { sdp: answer.sdp, type: answer.type } });
        } else if (sig.type === 'ice') {
            try { await pc.addIceCandidate(sig.payload); } catch (e) {}
        } else if (sig.type === 'bye') {
            stop('Терминал завершил сеанс');
        }
    }
}

function stop(reason) {
    if (ended) return;
    ended = true;
    clearInterval(pollTimer);
    setStatus(reason || 'Завершено', 'ended');
    if (pc) { try { pc.close(); } catch (e) {} }
}

function attachControl(video) {
    // Координаты — доля от размера видео (0..1), терминал сам переводит их
    // в пиксели своего реального экрана: разрешение зрителя и терминала
    // может не совпадать (масштабирование видео в окне браузера).
    function relCoords(clientX, clientY) {
        const r = video.getBoundingClientRect();
        return { x: (clientX - r.left) / r.width, y: (clientY - r.top) / r.height };
    }
    function send(type, x, y) {
        if (dc && dc.readyState === 'open') dc.send(JSON.stringify({ type, x, y, t: Date.now() }));
    }
    video.addEventListener('mousedown', (e) => { const { x, y } = relCoords(e.clientX, e.clientY); send('down', x, y); });
    video.addEventListener('mousemove', (e) => { if (e.buttons) { const { x, y } = relCoords(e.clientX, e.clientY); send('move', x, y); } });
    video.addEventListener('mouseup',   (e) => { const { x, y } = relCoords(e.clientX, e.clientY); send('up', x, y); });
    video.addEventListener('touchstart', (e) => { e.preventDefault(); const t = e.touches[0]; const { x, y } = relCoords(t.clientX, t.clientY); send('down', x, y); }, { passive: false });
    video.addEventListener('touchmove',  (e) => { e.preventDefault(); const t = e.touches[0]; const { x, y } = relCoords(t.clientX, t.clientY); send('move', x, y); }, { passive: false });
    video.addEventListener('touchend',   (e) => { e.preventDefault(); const t = e.changedTouches[0]; const { x, y } = relCoords(t.clientX, t.clientY); send('up', x, y); }, { passive: false });
}

function sendControl(msg) {
    if (dc && dc.readyState === 'open') dc.send(JSON.stringify(msg));
}

document.getElementById('clipboardSendBtn').addEventListener('click', () => {
    const text = document.getElementById('clipboardInput').value;
    sendControl({ type: 'clipboard_set', text });
});
document.getElementById('clipboardGetBtn').addEventListener('click', () => {
    sendControl({ type: 'clipboard_get' });
});

window.addEventListener('beforeunload', () => {
    if (sessionId && !ended) navigator.sendBeacon('api/remote_access.php?action=screen_end',
        new Blob([JSON.stringify({ session_id: sessionId })], { type: 'application/json' }));
});

start();
</script>
</body>
</html>
