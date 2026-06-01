<?php
/**
 * =====================================================
 *  CANTEEN MESSENGER — Telegram-like chat
 * =====================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Accept both main admin session and chat-specific session
if (!empty($_SESSION['chat_uid'])) {
    $uid    = (int)$_SESSION['chat_uid'];
    $uname  = $_SESSION['chat_uname']    ?? 'User';
    $urole  = $_SESSION['chat_urole']    ?? 'member';
    $isSA   = $urole === 'super_admin';
    $isAdminSession = !empty($_SESSION['chat_is_admin']);
} elseif (!empty($_SESSION['user_id']) && !empty($_SESSION['is_admin'])) {
    $uid    = (int)$_SESSION['user_id'];
    $uname  = $_SESSION['user_name']  ?? 'Admin';
    $urole  = $_SESSION['role']       ?? 'admin';
    $isSA   = $urole === 'super_admin';
    $isAdminSession = true;
} else {
    header('Location: chat_login.php'); exit;
}

$csrf = Csrf::getToken();

// Все пользователи с доступом в чат (для «Добавить участника»)
// chat_access column may not exist yet — fall back to role-based query
try {
    $allAdmins = $pdo->query(
        "SELECT id, full_name, role FROM employees
         WHERE is_active=1 AND (role IN ('admin','super_admin') OR chat_access=1)
         ORDER BY full_name"
    )->fetchAll();
} catch (PDOException $e) {
    $allAdmins = $pdo->query(
        "SELECT id, full_name, role FROM employees
         WHERE is_active=1 AND role IN ('admin','super_admin')
         ORDER BY full_name"
    )->fetchAll();
}
// Run DB migrations (adds chat_access, chat_username, chat_password columns if missing)
try { $pdo->exec("ALTER TABLE employees ADD COLUMN chat_access TINYINT(1) NOT NULL DEFAULT 0"); } catch(PDOException $e){}
try { $pdo->exec("ALTER TABLE employees ADD COLUMN chat_username VARCHAR(100) DEFAULT NULL"); } catch(PDOException $e){}
try { $pdo->exec("ALTER TABLE employees ADD COLUMN chat_password VARCHAR(255) DEFAULT NULL"); } catch(PDOException $e){}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&display=swap" rel="stylesheet">
<meta name="theme-color" content="#002756">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Мессенджер">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/img/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="favicon.ico">
<title>Мессенджер — <?= htmlspecialchars(APP_NAME) ?></title>
<?= Csrf::meta() ?>
<style>
/* ════════════════════════════════════════════════════
   BASE
════════════════════════════════════════════════════ */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{height:100%;height:-webkit-fill-available;background:#17212b}
body{
  height:100%;height:-webkit-fill-available;
  overflow:hidden;
  font-family:'Onest','Segoe UI',system-ui,-apple-system,sans-serif;font-size:14px;
  background:#17212b;
}
:root{
  --s:#17212b;--s2:#232e3c;--s3:#2b5278;--s4:#1c2733;
  --bg:#232e3c;--surface:#2b3a4a;--accent:#2b9cf2;
  --blue:#2b9cf2;--blue2:#1a8de0;--green:#4dcd5e;
  --red:#e53935;--yellow:#fdd835;
  --t1:#fff;--t2:rgba(255,255,255,.7);--t3:rgba(255,255,255,.4);--t4:rgba(255,255,255,.2);
  --text-2:rgba(255,255,255,.6);
  --border:rgba(255,255,255,.06);
  --msg-in:#182533;--msg-in-t:#fff;
  --msg-out:#2b5278;--msg-out-t:#fff;
  --hover:rgba(255,255,255,.05);--active:rgba(43,146,242,.18);
  --r:10px;--r2:18px;
}

/* ════════════════════════════════════════════════════
   LAYOUT
════════════════════════════════════════════════════ */
.app{
  display:flex;
  height:100%;
  background:var(--s);
  min-height:0;
}

/* ─── SIDEBAR ─── */
.sidebar{
  width:320px;min-width:280px;max-width:380px;
  display:flex;flex-direction:column;
  background:var(--s2);border-right:1px solid var(--border);
  flex-shrink:0;position:relative;
}
.sidebar-hdr{
  padding:10px 12px;display:flex;align-items:center;gap:6px;
  border-bottom:1px solid var(--border);flex-shrink:0;
}
.hdr-back{
  color:var(--blue);background:none;border:none;font-size:20px;
  cursor:pointer;padding:4px;border-radius:6px;flex-shrink:0;
  text-decoration:none;display:flex;align-items:center;
}
.hdr-back:hover{background:var(--hover)}
.search-wrap{
  flex:1;display:flex;align-items:center;gap:8px;
  background:var(--s);border-radius:20px;padding:7px 12px;
}
.search-wrap input{
  flex:1;background:none;border:none;outline:none;
  color:var(--t1);font-size:14px;
}
.search-wrap input::placeholder{color:var(--t3)}
.search-icon{color:var(--t3);font-size:15px}

.new-btn{
  width:30px;height:30px;border-radius:50%;background:var(--blue);
  border:none;color:#fff;font-size:14px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  transition:background .2s;
  touch-action:manipulation;-webkit-tap-highlight-color:transparent;
}
.new-btn:hover,.new-btn:active{background:var(--blue2)}

/* Room list */
.room-list{flex:1;overflow-y:auto}
.room-list::-webkit-scrollbar{width:3px}
.room-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}

.room-item{
  display:flex;align-items:center;gap:12px;
  padding:10px 14px;cursor:pointer;transition:background .12s;
  border-bottom:1px solid var(--border);position:relative;
  -webkit-tap-highlight-color:transparent;touch-action:manipulation;
}
.room-item:hover{background:var(--hover)}
.room-item.active{background:var(--active)}

.room-avatar{
  width:48px;height:48px;border-radius:50%;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:18px;font-weight:700;color:#fff;position:relative;
}
.room-avatar .online-dot{
  position:absolute;bottom:1px;right:1px;
  width:10px;height:10px;border-radius:50%;
  background:var(--green);border:2px solid var(--s2);
}
.room-body{flex:1;min-width:0}
.room-name{font-weight:400;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.room-preview{font-size:13px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.room-preview .sender{color:var(--t3);margin-right:3px}
.room-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.room-time{font-size:11px;color:var(--t3)}
.unread-badge{
  background:var(--blue);color:#fff;
  font-size:11px;font-weight:700;
  min-width:20px;height:20px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;padding:0 5px;
}
.room-type-icon{font-size:13px;color:var(--t3);margin-right:2px}

/* ─── MAIN AREA ─── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative}

/* Top bar */
.chat-topbar{
  background:var(--s2);border-bottom:1px solid var(--border);
  padding:10px 16px;display:flex;align-items:center;gap:12px;flex-shrink:0;
}
.topbar-avatar{
  width:40px;height:40px;border-radius:50%;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:15px;font-weight:700;color:#fff;
}
.topbar-info{flex:1;min-width:0}
.topbar-name{font-weight:500;color:var(--t1);font-size:15px}
.topbar-sub{font-size:12px;color:var(--t3);margin-top:1px;transition:color .3s}
.topbar-sub .online-label{color:var(--green);font-weight:500}
.topbar-actions{display:flex;gap:2px;align-items:center}
.topbar-btn{
  width:40px;height:40px;border-radius:50%;background:none;border:none;
  color:var(--t2);font-size:18px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:background .15s;
  -webkit-tap-highlight-color:transparent;touch-action:manipulation;
  flex-shrink:0;
}
.topbar-btn:hover,.topbar-btn:active{background:var(--hover);color:var(--t1)}

/* Messages */
.messages-area{
  flex:1;overflow-y:auto;padding:12px 16px;
  display:flex;flex-direction:column;
}
.messages-area::-webkit-scrollbar{width:5px}
.messages-area::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:3px}

/* Load more */
.load-more-btn{
  align-self:center;margin-bottom:12px;
  background:var(--s3);color:var(--t2);
  border:none;padding:6px 20px;border-radius:16px;
  font-size:13px;cursor:pointer;transition:background .2s;
}
.load-more-btn:hover{background:var(--blue)}
.load-more-btn:disabled{opacity:.4;cursor:not-allowed}

.day-sep{
  text-align:center;margin:12px 0;
  color:var(--t3);font-size:12px;font-weight:600;
  display:flex;align-items:center;gap:10px;
}
.day-sep::before,.day-sep::after{content:'';flex:1;height:1px;background:var(--border)}

.msg-wrap{display:flex;margin-bottom:2px;gap:8px;align-items:flex-end}
.msg-wrap.own{flex-direction:row-reverse}
.msg-wrap.system{justify-content:center}

.msg-av{width:32px;height:32px;border-radius:50%;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:12px;font-weight:700;color:#fff}
.msg-av-gap{width:32px;flex-shrink:0}

.msg-col{max-width:65%;display:flex;flex-direction:column}
.msg-wrap.own .msg-col{align-items:flex-end}

.msg-sender{font-size:12px;font-weight:500;color:var(--blue);margin-bottom:3px;padding-left:12px}
.msg-wrap.own .msg-sender{display:none}

/* Reply strip */
.msg-reply{
  background:rgba(43,146,242,.12);border-left:3px solid var(--blue);
  border-radius:var(--r) var(--r) 0 0;
  padding:6px 10px 8px;font-size:12px;
  margin-bottom:-4px;
}
.msg-reply-sender{color:var(--blue);font-weight:700;margin-bottom:2px}
.msg-reply-text{color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:300px}

.msg-bub{
  background:var(--msg-in);color:var(--msg-in-t);
  padding:8px 12px;border-radius:var(--r2) var(--r2) var(--r2) 4px;
  word-break:break-word;line-height:1.5;position:relative;
}
.msg-wrap.own .msg-bub{
  background:var(--msg-out);
  border-radius:var(--r2) var(--r2) 4px var(--r2);
}
.msg-deleted{color:var(--t3);font-style:italic;font-size:13px}
.msg-system-bub{
  background:rgba(0,0,0,.3);color:var(--t3);
  font-size:12px;padding:4px 14px;border-radius:12px;
}

.msg-text{white-space:pre-wrap}
.msg-fwd-label { font-size:11px; color:#64748b; font-style:italic; margin-bottom:3px; padding:2px 6px; background:rgba(0,0,0,.04); border-radius:4px; }

/* File bubbles */
.msg-img{
  max-width:280px;max-height:220px;border-radius:var(--r);
  cursor:pointer;display:block;object-fit:cover;
  transition:opacity .2s;
}
.msg-img:hover{opacity:.85}
.msg-file{
  display:flex;align-items:center;gap:10px;
  padding:4px 4px;min-width:200px;cursor:pointer;
}
.file-icon{font-size:28px;flex-shrink:0}
.file-info{min-width:0}
.file-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
.file-size{font-size:12px;color:var(--t3);margin-top:2px}
.msg-video{max-width:300px;border-radius:var(--r);display:block}
.msg-audio{width:260px;border-radius:var(--r)}

.msg-footer{
  display:flex;align-items:center;gap:6px;margin-top:4px;
  padding-right:4px;justify-content:flex-end;
}
.msg-time-txt{font-size:11px;color:var(--t3)}
.msg-wrap.own .msg-time-txt{color:rgba(255,255,255,.45)}

/* Context menu */
.ctx-menu{
  position:fixed;z-index:2000;background:var(--s2);
  border:1px solid var(--border);border-radius:10px;
  box-shadow:0 8px 30px rgba(0,0,0,.4);min-width:150px;max-width:200px;
  overflow:hidden;animation:fadeIn .12s ease;
}
.ctx-item{
  padding:11px 16px;cursor:pointer;color:var(--t1);
  font-size:13.5px;font-weight:500;
  transition:background .1s;white-space:nowrap;
}
.ctx-item:hover{background:var(--hover)}
.ctx-item + .ctx-item{border-top:1px solid rgba(255,255,255,.05)}
.ctx-item.danger{color:#ff6b6b}
.ctx-sep{height:1px;background:var(--border);margin:2px 0}

/* Reply bar */
.reply-bar{
  background:var(--s3);border-top:1px solid var(--border);
  padding:8px 16px;display:none;align-items:center;gap:10px;
}
.reply-bar.show{display:flex}
.reply-bar-content{flex:1;min-width:0}
.reply-bar-sender{font-size:12px;font-weight:700;color:var(--blue)}
.reply-bar-text{font-size:13px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.reply-close{background:none;border:none;color:var(--t3);font-size:18px;cursor:pointer;padding:2px}

/* Input area */
.input-area{
  background:var(--s2);border-top:1px solid var(--border);
  padding:10px 14px calc(10px + env(safe-area-inset-bottom));
  display:flex;align-items:flex-end;gap:10px;flex-shrink:0;
}
.attach-btn,.send-btn-main{
  width:40px;height:40px;border-radius:50%;border:none;
  display:flex;align-items:center;justify-content:center;
  font-size:20px;cursor:pointer;flex-shrink:0;transition:all .2s;
}
.attach-btn{background:var(--s);color:var(--t2)}
.attach-btn:hover{background:var(--hover);color:var(--t1)}
.send-btn-main{background:var(--blue);color:#fff}
.send-btn-main:hover{background:var(--blue2)}
.send-btn-main:disabled{opacity:.4;cursor:not-allowed}
.input-box{
  flex:1;min-height:40px;max-height:160px;overflow-y:auto;
  background:var(--s);border:none;border-radius:20px;
  padding:10px 16px;color:var(--t1);font-size:16px;/* 16px prevents iOS zoom */
  resize:none;outline:none;line-height:1.5;font-family:inherit;
  -webkit-user-select:text;user-select:text;
  touch-action:manipulation;
}
.input-box:empty::before{content:attr(data-placeholder);color:var(--t3);pointer-events:none}
.attach-btn{touch-action:manipulation;-webkit-tap-highlight-color:transparent}
.send-btn-main{touch-action:manipulation;-webkit-tap-highlight-color:transparent}

/* Upload preview */
.upload-preview{
  background:var(--s3);border-top:1px solid var(--border);
  padding:10px 16px;display:none;flex-wrap:wrap;gap:10px;
}
.upload-preview.show{display:flex}
.preview-item{
  position:relative;background:var(--s2);border-radius:var(--r);
  padding:8px 12px;display:flex;align-items:center;gap:8px;max-width:220px;
}
.preview-thumb{width:40px;height:40px;border-radius:6px;object-fit:cover}
.preview-name{font-size:13px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px}
.preview-size{font-size:11px;color:var(--t3)}
.preview-remove{
  position:absolute;top:-6px;right:-6px;width:18px;height:18px;
  background:var(--red);border:none;border-radius:50%;color:#fff;
  font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;
}
.progress-bar{
  height:3px;background:var(--blue);border-radius:2px;
  width:0;transition:width .3s;margin-top:6px;
}

/* Empty state */
.chat-empty{
  flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  color:var(--t3);gap:16px;
}
.chat-empty-icon{font-size:60px;opacity:.3}
.chat-empty h3{font-size:18px;color:var(--t2)}
.chat-empty p{font-size:14px;text-align:center;max-width:280px}

/* No room selected */
.no-room{flex:1;display:flex;align-items:center;justify-content:center;background:var(--s)}
.no-room-inner{text-align:center;color:var(--t3)}
.no-room-inner .icon{font-size:64px;opacity:.2;display:block;margin-bottom:16px}
.no-room-inner h3{font-size:18px;color:var(--t2);margin-bottom:8px}

/* ════ MODALS ════ */
.overlay{
  position:fixed;inset:0;z-index:1000;
  background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
  display:none;align-items:center;justify-content:center;padding:16px;
}
.overlay.open{display:flex}
.modal{
  background:var(--s2);border-radius:14px;width:100%;max-width:460px;
  box-shadow:0 20px 60px rgba(0,0,0,.5);animation:popIn .2s ease;overflow:hidden;
}
@keyframes popIn{from{transform:scale(.92);opacity:0}to{transform:scale(1);opacity:1}}
.modal-hdr{
  padding:12px 16px 10px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
}
.modal-title{font-size:15px;font-weight:500;color:var(--t1)}
.modal-close{background:none;border:none;color:var(--t3);font-size:20px;cursor:pointer;padding:0 4px}
.modal-close:hover{color:var(--t1)}
.modal-body{padding:14px 16px}
.modal-footer{padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}

.form-field{margin-bottom:16px}
.form-field label{display:block;font-size:12px;font-weight:400;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.form-field input,.form-field textarea,.form-field select{
  width:100%;background:var(--s);border:1px solid var(--border);border-radius:var(--r);
  color:var(--t1);padding:10px 14px;font-family:inherit;font-size:14px;outline:none;
  transition:border-color .2s;
}
.form-field input:focus,.form-field textarea:focus{border-color:var(--blue)}
.form-field input::placeholder,.form-field textarea::placeholder{color:var(--t3)}
.form-field textarea{resize:vertical;min-height:80px}
.color-pills{display:flex;gap:8px;flex-wrap:wrap}
.color-pill{
  width:28px;height:28px;border-radius:50%;cursor:pointer;border:3px solid transparent;transition:border .15s;
}
.color-pill.sel{border-color:#fff}

.member-select-list{max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r);margin-top:8px}
.member-select-item{
  display:flex;align-items:center;gap:10px;padding:10px 12px;
  cursor:pointer;border-bottom:1px solid var(--border);transition:background .12s;
}
.member-select-item:last-child{border-bottom:none}
.member-select-item:hover{background:var(--hover)}
.member-select-item input[type=checkbox]{accent-color:var(--blue);width:16px;height:16px;cursor:pointer}
.member-avatar-sm{
  width:32px;height:32px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:13px;font-weight:700;color:#fff;flex-shrink:0;
}
.member-name-txt{flex:1;color:var(--t1);font-size:14px}
.member-role-txt{font-size:11px;color:var(--t3)}

.btn{
  padding:8px 16px;border-radius:var(--r);border:none;font-family:inherit;
  font-size:13px;font-weight:500;cursor:pointer;transition:all .2s;
}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:var(--blue2)}
.btn-ghost{background:var(--s);color:var(--t2)}
.btn-ghost:hover{background:var(--hover)}
.btn-danger{background:var(--red);color:#fff}
.btn:disabled{opacity:.4;cursor:not-allowed}

/* Members panel */
.members-panel{
  position:absolute;top:0;right:0;bottom:0;width:300px;
  background:var(--s2);border-left:1px solid var(--border);
  display:none;flex-direction:column;z-index:100;
}
.members-panel.open{display:flex}
.members-panel-hdr{
  padding:14px 16px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
}
.members-panel-hdr h3{font-weight:700;color:var(--t1);font-size:15px}
.members-list{flex:1;overflow-y:auto;padding:8px}
.member-row{
  display:flex;align-items:center;gap:10px;padding:8px 10px;
  border-radius:var(--r);position:relative;
}
.member-row:hover{background:var(--hover)}
.member-info{flex:1;min-width:0}
.member-nm{font-size:14px;color:var(--t1);font-weight:500}
.member-rl{font-size:11px;color:var(--t3)}
.member-status{width:8px;height:8px;border-radius:50%;background:var(--t4);flex-shrink:0}
.member-status.online{background:var(--green)}

/* Image viewer */
.img-viewer{
  position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,.9);
  display:none;align-items:center;justify-content:center;flex-direction:column;gap:14px;
}
.img-viewer.open{display:flex}
.img-viewer img{max-width:90vw;max-height:85vh;border-radius:var(--r);object-fit:contain}
.img-viewer-close{
  position:absolute;top:16px;right:20px;
  background:rgba(255,255,255,.15);border:none;border-radius:50%;
  width:40px;height:40px;font-size:20px;color:#fff;cursor:pointer;
}
.img-viewer-name{color:var(--t3);font-size:13px}

/* ════ CALL ════ */
.incoming-overlay{
  position:fixed;inset:0;z-index:5000;
  background:rgba(0,0,0,.7);backdrop-filter:blur(10px);
  display:none;align-items:center;justify-content:center;
}
.incoming-overlay.open{display:flex}
.incoming-box{
  background:var(--s2);border-radius:20px;padding:48px 60px;
  text-align:center;box-shadow:0 30px 80px rgba(0,0,0,.6);
}
.inc-av{
  width:80px;height:80px;border-radius:50%;margin:0 auto 18px;
  display:flex;align-items:center;justify-content:center;
  font-size:28px;font-weight:800;color:#fff;
  animation:ring 1.2s ease-in-out infinite;
}
@keyframes ring{0%,100%{box-shadow:0 0 0 0 rgba(43,146,242,.4)}50%{box-shadow:0 0 0 18px rgba(43,146,242,0)}}
.inc-name{font-size:22px;font-weight:800;color:var(--t1);margin-bottom:6px}
.inc-type{font-size:14px;color:var(--t3);margin-bottom:36px}
.inc-btns{display:flex;gap:24px;justify-content:center}
.inc-accept,.inc-reject{
  width:66px;height:66px;border-radius:50%;border:none;cursor:pointer;
  font-size:28px;display:flex;align-items:center;justify-content:center;
  transition:transform .2s;
}
.inc-accept{background:var(--green);box-shadow:0 4px 20px rgba(77,205,94,.4)}
.inc-reject{background:var(--red);box-shadow:0 4px 20px rgba(229,57,53,.4)}
.inc-accept:hover,.inc-reject:hover{transform:scale(1.12)}

.call-win{
  position:fixed;bottom:24px;right:24px;z-index:4000;
  width:360px;background:#0d1b2a;border-radius:16px;
  box-shadow:0 20px 60px rgba(0,0,0,.6);
  display:none;overflow:hidden;
}
.call-win.open{display:block;animation:slideUp .25s ease}
@keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.call-vids{position:relative;background:#000;aspect-ratio:16/9}
.vid-remote{width:100%;height:100%;object-fit:cover;display:block}
.vid-local{
  position:absolute;bottom:10px;right:10px;
  width:88px;height:66px;border-radius:8px;
  object-fit:cover;border:2px solid rgba(255,255,255,.25);background:#111;
  cursor:pointer;
}
/* Mobile: fullscreen call */
@media(max-width:680px){
  .call-win{
    position:fixed;inset:0;bottom:0;right:0;
    width:100vw !important;height:100vh !important;
    height:-webkit-fill-available !important;
    border-radius:0;
    z-index:9000;
    display:none;flex-direction:column;
  }
  .call-win.open{display:flex}
  .call-vids{
    flex:1;aspect-ratio:unset;min-height:0;
    position:relative;
  }
  .vid-remote{
    position:absolute;inset:0;
    width:100%;height:100%;
    object-fit:cover;
  }
  .vid-local{
    position:absolute;
    bottom:calc(80px + env(safe-area-inset-bottom));
    right:16px;
    width:90px;height:120px; /* portrait PiP */
    border-radius:12px;
    border:2px solid rgba(255,255,255,.4);
    box-shadow:0 4px 16px rgba(0,0,0,.4);
    z-index:10;
  }
  .call-info{padding:calc(12px + env(safe-area-inset-top)) 16px 8px}
  .call-ctrls{
    padding:16px 24px;
    padding-bottom:calc(16px + env(safe-area-inset-bottom));
    background:rgba(0,0,0,.6);
    backdrop-filter:blur(10px);
    flex-shrink:0;
  }
  .ccbtn{width:52px;height:52px;font-size:22px}
  .ccbtn.end{width:60px;height:60px;font-size:26px}
}
.call-no-vid{
  width:100%;height:100%;display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:12px;color:var(--t3);font-size:14px;
}
.call-no-av{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#fff}
.call-info{padding:8px 14px 4px;display:flex;align-items:center;gap:8px}
.call-peer-nm{flex:1;font-size:14px;font-weight:700;color:var(--t2)}
.call-tmr{font-size:12px;color:var(--t3);font-variant-numeric:tabular-nums}
.call-ctrls{display:flex;align-items:center;justify-content:center;gap:10px;padding:12px 14px}
.ccbtn{
  width:42px;height:42px;border-radius:50%;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;font-size:18px;
  background:rgba(255,255,255,.1);color:#fff;transition:all .2s;
}
.ccbtn:hover{background:rgba(255,255,255,.2)}
.ccbtn.muted{background:rgba(229,57,53,.3)}
.ccbtn.end{background:var(--red);width:48px;height:48px;font-size:22px;box-shadow:0 4px 14px rgba(229,57,53,.4)}
.ccbtn.end:hover{background:#c62828}
.screen-badge{
  position:absolute;top:8px;left:8px;background:rgba(0,0,0,.5);
  color:#fff;font-size:11px;padding:3px 8px;border-radius:6px;display:none;
}
.screen-badge.on{display:block}

/* ════ TOAST ════ */
.toast{
  position:fixed;top:20px;left:50%;transform:translateX(-50%);
  z-index:9000;background:var(--s2);color:var(--t1);
  border:1px solid var(--border);border-radius:var(--r);
  padding:12px 20px;font-size:14px;font-weight:600;
  box-shadow:0 8px 30px rgba(0,0,0,.4);
  display:none;align-items:center;gap:10px;white-space:nowrap;
}
.toast.show{display:flex;animation:slideDown .25s ease}
@keyframes slideDown{from{transform:translateX(-50%) translateY(-12px);opacity:0}to{transform:translateX(-50%) translateY(0);opacity:1}}

/* ════ ROOM SETTINGS ════ */
.rs-color-pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.rs-color-pill{width:28px;height:28px;border-radius:50%;cursor:pointer;border:3px solid transparent;transition:border .15s;flex-shrink:0}
.rs-color-pill.sel{border-color:#fff}
.rs-danger-zone{margin-top:20px;padding-top:16px;border-top:1px solid rgba(229,57,53,.3)}
.rs-danger-zone h4{font-size:12px;font-weight:700;color:#e53935;text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px}

/* ════ PINNED MESSAGE ════ */
.pinned-bar{
  background:rgba(43,146,242,.1);border-bottom:1px solid rgba(43,146,242,.2);
  padding:8px 16px;display:none;align-items:center;gap:10px;cursor:pointer;flex-shrink:0;
}
.pinned-bar.show{display:flex}
.pinned-icon{color:var(--blue);font-size:14px;flex-shrink:0}
.pinned-content{flex:1;min-width:0}
.pinned-label{font-size:11px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:.4px}
.pinned-text{font-size:13px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pinned-close{background:none;border:none;color:var(--t3);font-size:16px;cursor:pointer;padding:2px 4px;flex-shrink:0}
.pinned-close:hover{color:var(--t1)}

/* ════ INLINE EDIT ════ */
.msg-edit-wrap{display:flex;flex-direction:column;gap:6px;min-width:200px}
.msg-edit-input{
  background:var(--s);border:1px solid var(--blue);border-radius:var(--r);
  color:var(--t1);padding:8px 10px;font-family:inherit;font-size:14px;
  outline:none;resize:none;min-height:60px;
}
.msg-edit-btns{display:flex;gap:6px;justify-content:flex-end}
.msg-edit-btns button{padding:4px 12px;border-radius:6px;border:none;font-size:12px;font-weight:600;cursor:pointer}
.edit-save{background:var(--blue);color:#fff}
.edit-cancel{background:var(--s3);color:var(--t2)}

/* ════ MEMBER ACTIONS ════ */
.member-actions{display:flex;gap:4px;margin-left:auto;flex-shrink:0}
.mbtn{background:none;border:none;color:var(--t3);font-size:13px;cursor:pointer;padding:4px 6px;border-radius:6px;transition:all .15s}
.mbtn:hover{background:var(--hover);color:var(--t1)}
.mbtn.danger:hover{background:rgba(229,57,53,.2);color:#e57373}

/* ════ EDITED LABEL ════ */
.msg-edited{font-size:10px;color:var(--t3);font-style:italic;margin-left:4px}

/* ════ ANIMATIONS ════ */
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.fade-in{animation:fadeIn .2s ease}

/* ════ RESPONSIVE ════ */
.mob-back-btn{
  display:none;align-items:center;justify-content:center;
  width:40px;height:40px;border:none;background:none;
  color:var(--t2);font-size:22px;cursor:pointer;flex-shrink:0;
  -webkit-tap-highlight-color:transparent;
}

@media(max-width:680px){
  /* Sidebar slides in from left, full-height */
  .sidebar{
    position:fixed;left:0;top:0;bottom:0;
    width:100vw !important;max-width:100vw !important;
    z-index:300;
    transform:translateX(-100%);
    transition:transform .28s cubic-bezier(.4,0,.2,1);
    padding-bottom:env(safe-area-inset-bottom);
  }
  .sidebar.mob-open{transform:translateX(0)}

  /* Main fills full screen */
  .main{
    width:100vw;
    position:fixed;
    top:0;left:0;right:0;
    bottom:0;
    display:flex;flex-direction:column;
  }

  /* Chat view fills remaining height after topbar */
  #chatView{
    flex:1;
    display:flex !important;
    flex-direction:column;
    min-height:0;
  }

  /* Messages area scrollable */
  .messages-area{
    flex:1 !important;
    overflow-y:auto !important;
    -webkit-overflow-scrolling:touch;
    min-height:0;
  }

  /* Input area — flush to bottom with safe area */
  .input-area{
    flex-shrink:0;
    position:static;
    padding-bottom:calc(10px + env(safe-area-inset-bottom));
    background:var(--s2);
  }

  /* Topbar — safe area at top */
  .chat-topbar{
    padding-top:env(safe-area-inset-top);
    flex-shrink:0;
  }

  /* Show back button */
  .mob-back-btn{display:flex !important}

  /* Members panel full width */
  .members-panel{
    position:fixed;right:0;top:0;bottom:0;
    width:100vw;z-index:250;
  }

  /* Topbar actions — ensure all buttons visible, smaller */
  .topbar-actions{
    gap:4px;
  }
  .topbar-btn{
    width:34px;height:34px;font-size:16px;
    -webkit-tap-highlight-color:transparent;
  }

  /* Call buttons always visible on mobile */
  #btnAudioCall,#btnVideoCall{display:flex !important}

  /* Sidebar header — compact */
  .sidebar-hdr{
    padding:10px 10px 8px;
    padding-top:calc(10px + env(safe-area-inset-top));
    gap:8px;
  }

  /* No-room placeholder */
  .no-room{display:none}

  /* Overlay modals — full screen on mobile */
  .overlay .modal{
    position:fixed;
    bottom:0;left:0;right:0;
    border-radius:18px 18px 0 0;
    max-width:100% !important;
    margin:0;
    max-height:92vh;
    overflow-y:auto;
    padding-bottom:env(safe-area-inset-bottom);
  }
  .overlay{align-items:flex-end}

  /* Room context — show on tap (handled via JS touch) */
  .room-ctx-btn{display:none}
  .room-avatar-wrap.ctx-open .room-ctx-btn{display:flex !important}
}
/* Room meta — badge under time */
.room-meta{display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0;min-width:44px}
/* Context menu on avatar */
.room-avatar-wrap{position:relative;flex-shrink:0;cursor:pointer}
.room-ctx-btn{position:absolute;inset:0;width:100%;height:100%;border:none;background:rgba(0,0,0,.45);
  color:#fff;font-size:13px;border-radius:50%;cursor:pointer;display:none;align-items:center;
  justify-content:center;transition:opacity .15s}
.room-item:hover .room-ctx-btn{display:flex}
/* Message status icons */
.msg-status{font-size:11px;margin-left:4px;opacity:.7}
.msg-status.read{color:#4fc3f7;opacity:1}
/* Chat users manager modal */
.cu-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)}
.cu-row:last-child{border-bottom:none}
.cu-avatar{width:36px;height:36px;border-radius:50%;background:var(--blue-700,#003366);
  color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0}
.cu-info{flex:1;min-width:0}
.cu-name{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--t1)}
.cu-meta{font-size:11px;color:var(--t3)}
.cu-actions{display:flex;gap:6px;flex-shrink:0}
.cu-toggle{width:36px;height:20px;border-radius:10px;border:none;cursor:pointer;
  position:relative;transition:background .2s;flex-shrink:0}
.cu-toggle.on{background:#22c55e}.cu-toggle.off{background:var(--border)}
.cu-toggle::after{content:'';position:absolute;top:3px;width:14px;height:14px;
  border-radius:50%;background:#fff;transition:left .2s}
.cu-toggle.on::after{left:19px}.cu-toggle.off::after{left:3px}
.cu-edit-form{background:var(--s);border-radius:8px;padding:12px;margin-top:8px;display:none;color:var(--t1)}
.cu-edit-form.open{display:block}
.ap-tab-btn{background:none;border:none;color:var(--t2);padding:10px 14px;font-size:13px;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
.ap-tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
.ap-tab-btn:hover{color:var(--t1)}
.cu-field{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.cu-field label{font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase}
.cu-field input{padding:8px 10px;border:1px solid var(--border);border-radius:6px;
  font-size:13px;background:var(--s2);color:var(--t1)}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<div class="app">

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-hdr">
    <div class="search-wrap">
      <span class="search-icon"><i class="fas fa-search"></i></span>
      <input id="searchInput" type="text" placeholder="Поиск…">
    </div>
    <button class="new-btn" id="newChatBtn" title="Новый чат / группа / канал"><i class="fas fa-pencil-alt"></i></button>
    <?php if ($isAdminSession): ?>
    <button class="new-btn" onclick="openAdminPanel()" title="Панель администратора" style="margin-left:4px"><i class="fas fa-shield-alt"></i></button>
    <?php endif; ?>
    <button class="new-btn" onclick="openProfile()" title="Мой профиль" style="margin-left:4px"><i class="fas fa-user-circle"></i></button>
  </div>
  <div class="room-list" id="roomList">
    <div style="padding:20px;text-align:center;color:var(--t3)">Загрузка…</div>
  </div>
</aside>

<!-- ═══ MAIN ═══ -->
<div class="main" id="main">

  <!-- No room placeholder -->
  <div class="no-room" id="noRoom">
    <div class="no-room-inner">
      <span class="icon"><i class="fas fa-comments"></i></span>
      <h3>Выберите чат</h3>
      <p style="color:var(--t3);font-size:14px">Откройте существующую беседу или создайте новую</p>
    </div>
  </div>

  <!-- Chat view (hidden until room selected) -->
  <div id="chatView" style="display:none;flex:1;flex-direction:column;overflow:hidden;position:relative">

    <!-- Topbar -->
    <div class="chat-topbar" id="chatTopbar">
      <button class="mob-back-btn" onclick="closeMobileChat()">←</button>
      <div class="topbar-avatar" id="tbAvatar"></div>
      <div class="topbar-info">
        <div class="topbar-name" id="tbName"></div>
        <div class="topbar-sub"  id="tbSub"></div>
      </div>
      <div class="topbar-actions">
        <button class="topbar-btn" id="btnAudioCall" title="Аудиозвонок" onclick="initiateCall(false)"><i class="fas fa-microphone"></i></button>
        <button class="topbar-btn" id="btnVideoCall" title="Видеозвонок" onclick="initiateCall(true)"><i class="fas fa-video"></i></button>
        <button class="topbar-btn" title="Участники" onclick="toggleMembersPanel()"><i class="fas fa-users"></i></button>
        <button class="topbar-btn" id="btnRoomSettings" title="Настройки комнаты" onclick="openRoomSettings()" style="display:none"><i class="fas fa-cog"></i></button>
        <button class="topbar-btn" id="btnLeave" title="Покинуть" onclick="leaveRoom()" style="display:none"><i class="fas fa-sign-out-alt"></i></button>
      </div>
    </div>

    <!-- Pinned message bar -->
    <div class="pinned-bar" id="pinnedBar" onclick="scrollToPinned()">
      <span class="pinned-icon"><i class="fas fa-thumbtack"></i></span>
      <div class="pinned-content">
        <div class="pinned-label">Закреплено</div>
        <div class="pinned-text" id="pinnedText"></div>
      </div>
      <button class="pinned-close" id="pinnedUnpinBtn" title="Открепить" onclick="event.stopPropagation();unpinMsg()"><i class="fas fa-times"></i></button>
    </div>

    <!-- Empty state (снаружи messagesArea, чтобы innerHTML='' не уничтожал его) -->
    <div id="msgEmpty" class="chat-empty" style="display:flex">
      <div class="chat-empty-icon"><i class="fas fa-comment"></i></div>
      <h3>Сообщений нет</h3>
      <p>Напишите первое сообщение!</p>
    </div>

    <!-- Messages -->
    <div class="messages-area" id="messagesArea" style="display:none"></div>

    <!-- Members panel -->
    <div class="members-panel" id="membersPanel">
      <div class="members-panel-hdr">
        <h3>Участники</h3>
        <button class="modal-close" onclick="toggleMembersPanel()"><i class="fas fa-times"></i></button>
      </div>
      <div style="padding:10px 12px;border-bottom:1px solid var(--border)">
        <button class="btn btn-ghost" style="width:100%;font-size:13px" onclick="openAddMemberModal()"><i class="fas fa-plus"></i> Добавить участника</button>
      </div>
      <div class="members-list" id="membersList"></div>
    </div>

    <!-- Reply bar -->
    <div class="reply-bar" id="replyBar">
      <span style="color:var(--blue);font-size:18px"><i class="fas fa-reply"></i></span>
      <div class="reply-bar-content">
        <div class="reply-bar-sender" id="replyBarSender"></div>
        <div class="reply-bar-text"   id="replyBarText"></div>
      </div>
      <button class="reply-close" onclick="cancelReply()"><i class="fas fa-times"></i></button>
    </div>

    <!-- Upload preview -->
    <div class="upload-preview" id="uploadPreview"></div>

    <!-- Input -->
    <div class="input-area">
      <input type="file" id="fileInput" multiple style="display:none"
             accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.txt,.csv">
      <button class="attach-btn" onclick="document.getElementById('fileInput').click()" title="Прикрепить файл"><i class="fas fa-paperclip"></i></button>
      <div class="input-box" id="msgInput" contenteditable="true" data-placeholder="Написать сообщение…"></div>
      <button class="send-btn-main" id="sendBtn" onclick="sendMessage()" title="Отправить (Enter)"><i class="fas fa-paper-plane"></i></button>
    </div>
  </div><!-- #chatView -->

</div><!-- .main -->
</div><!-- .app -->

<!-- ═══ MODALS ═══ -->

<!-- Room Settings -->
<div class="overlay" id="roomSettingsOverlay">
  <div class="modal" style="max-width:440px">
    <div class="modal-hdr">
      <div class="modal-title"><i class="fas fa-cog"></i> Настройки комнаты</div>
      <button class="modal-close" onclick="closeOverlay('roomSettingsOverlay')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-field">
        <label>Название</label>
        <input type="text" id="rsName" maxlength="100" placeholder="Название группы / канала">
      </div>
      <div class="form-field">
        <label>Описание</label>
        <textarea id="rsDesc" rows="2" maxlength="300" placeholder="Описание (необязательно)"></textarea>
      </div>
      <div class="form-field">
        <label>Цвет аватара</label>
        <div class="rs-color-pills" id="rsColorPills"></div>
      </div>
      <div class="rs-danger-zone" id="rsDangerZone" style="display:none">
        <h4><i class="fas fa-exclamation-triangle"></i> Опасная зона</h4>
        <button class="btn btn-danger" style="width:100%" onclick="deleteRoom()"><i class="fas fa-trash"></i> Удалить комнату навсегда</button>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeOverlay('roomSettingsOverlay')">Отмена</button>
      <button class="btn btn-primary" onclick="saveRoomSettings()"><i class="fas fa-save"></i> Сохранить</button>
    </div>
  </div>
</div>

<!-- New chat selector -->
<div class="overlay" id="newChatOverlay">
  <div class="modal" style="max-width:360px">
    <div class="modal-hdr">
      <div class="modal-title">Новый чат</div>
      <button class="modal-close" onclick="closeOverlay('newChatOverlay')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
      <button class="btn btn-ghost" style="justify-content:flex-start;gap:12px;padding:14px 16px;font-size:15px" onclick="closeOverlay('newChatOverlay');openNewGroupModal('group')"><i class="fas fa-users"></i>
        Создать группу
      </button>
      <button class="btn btn-ghost" style="justify-content:flex-start;gap:12px;padding:14px 16px;font-size:15px" onclick="closeOverlay('newChatOverlay');openNewGroupModal('channel')"><i class="fas fa-bullhorn"></i>
        Создать канал
      </button>
      <div style="border-top:1px solid var(--border);padding-top:10px;color:var(--t3);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Личное сообщение</div>
      <button class="btn btn-ghost" style="justify-content:flex-start;gap:12px;padding:14px 16px;font-size:15px" onclick="openUserSearch()"><i class="fas fa-search"></i>
        Найти пользователя
      </button>
      <div id="directUserList" style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r)"></div>
      <div style="border-top:1px solid var(--border);padding-top:10px;color:var(--t3);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Группы и каналы</div>
      <button class="btn btn-ghost" style="justify-content:flex-start;gap:12px;padding:14px 16px;font-size:15px" onclick="openPublicRooms()"><i class="fas fa-globe"></i>
        Публичные группы/каналы
      </button>
    </div>
  </div>
</div>

<!-- Public rooms overlay -->
<div class="overlay" id="publicRoomsOverlay">
  <div class="modal" style="max-width:480px">
    <div class="modal-hdr">
      <div class="modal-title"><i class="fas fa-globe"></i> Публичные комнаты</div>
      <button class="modal-close" onclick="closeOverlay('publicRoomsOverlay')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
      <input type="text" placeholder="Поиск по названию…" style="padding:10px 12px;border:1px solid var(--border);border-radius:var(--r);font-size:14px;outline:none"
        oninput="loadPublicRooms(this.value)">
      <div id="publicRoomList" style="max-height:400px;overflow-y:auto"></div>
    </div>
  </div>
</div>

<!-- User search overlay -->
<div class="overlay" id="userSearchOverlay">
  <div class="modal" style="max-width:400px">
    <div class="modal-hdr">
      <div class="modal-title"><i class="fas fa-search"></i> Найти пользователя</div>
      <button class="modal-close" onclick="closeOverlay('userSearchOverlay')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
      <input type="text" id="userSearchInput" placeholder="Введите имя или логин…" style="padding:10px 12px;border:1px solid var(--border);border-radius:var(--r);font-size:14px;outline:none"
        oninput="searchUsers()">
      <div id="userSearchResults" style="max-height:380px;overflow-y:auto">
        <div style="padding:12px;color:var(--t3);font-size:13px">Введите минимум 2 символа</div>
      </div>
    </div>
  </div>
</div>

<!-- Create group/channel -->
<div class="overlay" id="createRoomOverlay">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title" id="createRoomTitle">Создать группу</div>
      <button class="modal-close" onclick="closeOverlay('createRoomOverlay')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="createRoomType" value="group">
      <div class="form-field">
        <label>Название</label>
        <input type="text" id="crName" placeholder="Название группы…" maxlength="100">
      </div>
      <div class="form-field">
        <label>Описание (необязательно)</label>
        <textarea id="crDesc" placeholder="О чём этот чат…" rows="2"></textarea>
      </div>
      <div class="form-field">
        <label>Цвет</label>
        <div class="color-pills" id="colorPills"></div>
      </div>
      <div class="form-field">
        <label>Добавить участников</label>
        <div class="member-select-list" id="memberSelectList"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeOverlay('createRoomOverlay')">Отмена</button>
      <button class="btn btn-primary" onclick="createRoom()">Создать</button>
    </div>
  </div>
</div>

<!-- Add member -->
<div class="overlay" id="addMemberOverlay">
  <div class="modal" style="max-width:380px">
    <div class="modal-hdr">
      <div class="modal-title">Добавить участника</div>
      <button class="modal-close" onclick="closeOverlay('addMemberOverlay')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="member-select-list" id="addMemberList"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeOverlay('addMemberOverlay')">Отмена</button>
      <button class="btn btn-primary" onclick="addSelectedMembers()">Добавить</button>
    </div>
  </div>
</div>

<!-- Image viewer -->
<div class="img-viewer" id="imgViewer" onclick="closeImgViewer()">
  <button class="img-viewer-close" onclick="closeImgViewer()"><i class="fas fa-times"></i></button>
  <img id="imgViewerSrc" src="" alt="">
  <div class="img-viewer-name" id="imgViewerName"></div>
</div>

<!-- Incoming call -->
<div class="incoming-overlay" id="incomingOverlay">
  <div class="incoming-box">
    <div class="inc-av" id="incAv"></div>
    <div class="inc-name" id="incName">Звонок</div>
    <div class="inc-type" id="incType">Видеозвонок</div>
    <div class="inc-btns">
      <button class="inc-accept" onclick="acceptCall()"><i class="fas fa-check"></i></button>
      <button class="inc-reject" onclick="rejectCall()"><i class="fas fa-phone-slash"></i></button>
    </div>
  </div>
</div>

<!-- Active call window -->
<div class="call-win" id="callWin">
  <div class="call-vids" id="callVids">
    <video class="vid-remote" id="vidRemote" autoplay playsinline></video>
    <video class="vid-local"  id="vidLocal"  autoplay playsinline muted></video>
    <div class="call-no-vid" id="callNoVid" style="display:none">
      <div class="call-no-av" id="callPeerAv"></div>
      <span>Видео отключено</span>
    </div>
    <div class="screen-badge" id="screenBadge"><i class="fas fa-desktop"></i> Демонстрация</div>
  </div>
  <div class="call-info">
    <span class="call-peer-nm" id="callPeerNm"></span>
    <span class="call-tmr" id="callTmr">0:00</span>
  </div>
  <div class="call-ctrls">
    <button class="ccbtn" id="ccMute"   onclick="toggleMute()"   title="Микрофон"><i class="fas fa-microphone"></i></button>
    <button class="ccbtn" id="ccCam"    onclick="toggleCam()"    title="Камера"><i class="fas fa-video"></i></button>
    <button class="ccbtn" id="ccScreen" onclick="toggleScreen()" title="Экран"><i class="fas fa-desktop"></i></button>
    <button class="ccbtn end"           onclick="hangUp()"        title="Завершить"><i class="fas fa-phone-slash"></i></button>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <span id="toastIco"></span><span id="toastMsg"></span>
</div>

<!-- Admin Panel (admin only) -->
<div class="overlay" id="adminPanelOverlay">
  <div class="modal" style="max-width:560px">
    <div class="modal-hdr">
      <div class="modal-title"><i class="fas fa-shield-alt"></i> Панель администратора</div>
      <button class="modal-close" onclick="closeOverlay('adminPanelOverlay')"><i class="fas fa-times"></i></button>
    </div>
    <!-- Tab nav -->
    <div style="display:flex;border-bottom:1px solid var(--border);padding:0 16px;gap:4px">
      <button class="ap-tab-btn active" data-tab="apUsers" onclick="switchApTab('apUsers',this)"><i class="fas fa-users"></i> Пользователи</button>
      <button class="ap-tab-btn" data-tab="apRooms" onclick="switchApTab('apRooms',this)"><i class="fas fa-comments"></i> Комнаты</button>
      <button class="ap-tab-btn" data-tab="apStats" onclick="switchApTab('apStats',this)"><i class="fas fa-chart-bar"></i> Статистика</button>
    </div>
    <!-- Tab: Users -->
    <div id="apUsers" class="ap-tab-pane" style="display:flex;flex-direction:column">
      <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;gap:8px">
        <input type="text" id="cuSearch" placeholder="Поиск сотрудника…" oninput="renderCuList()"
          style="flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg);color:var(--t1)">
      </div>
      <div id="cuList" style="max-height:420px;overflow-y:auto;padding:0 16px"></div>
    </div>
    <!-- Tab: Rooms -->
    <div id="apRooms" class="ap-tab-pane" style="display:none;flex-direction:column">
      <div id="apRoomList" style="max-height:450px;overflow-y:auto;padding:0 16px"></div>
    </div>
    <!-- Tab: Stats -->
    <div id="apStats" class="ap-tab-pane" style="display:none;flex-direction:column">
      <div id="apStatsContent" style="padding:20px 16px"></div>
    </div>
  </div>
</div>

<!-- Room context dropdown -->
<div id="roomCtxDropdown" style="display:none;position:fixed;z-index:9999;
  background:rgba(29,42,56,.97);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border:1px solid rgba(255,255,255,.12);border-radius:10px;
  box-shadow:0 8px 32px rgba(0,0,0,.5);min-width:190px;overflow:hidden">
</div>

<!-- ═══ JS DATA ═══ -->
<script>
const ME = {
  id:   <?= $uid ?>,
  name: <?= json_encode($uname) ?>,
  role: <?= json_encode($urole) ?>,
  csrf: <?= json_encode($csrf) ?>,
  isAdmin: <?= json_encode($isAdminSession) ?>,
};
const ALL_ADMINS = <?= json_encode(array_map(fn($a) => [
  'id'   => (int)$a['id'],
  'name' => $a['full_name'],
  'role' => $a['role'],
], $allAdmins)) ?>;
</script>
<script>
/* ════════════════════════════════════════════════════
   HELPERS
════════════════════════════════════════════════════ */
const $id = id => document.getElementById(id);

function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }
function fmtSize(b){ return b>1e6 ? (b/1e6).toFixed(1)+' МБ' : b>1024 ? (b/1024).toFixed(0)+' КБ' : b+' Б'; }
function fmtTime(ts){ const d=new Date(ts.replace?ts.replace(' ','T'):ts); return d.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'}); }
function fmtDate(ts){ const d=new Date(ts.replace?ts.replace(' ','T'):ts); const t=new Date(); return d.toDateString()===t.toDateString()?'Сегодня':d.toLocaleDateString('ru-RU',{day:'numeric',month:'long'}); }
function pad2(n){ return String(n).padStart(2,'0'); }

const COLORS = ['#003366','#0055a5','#15803d','#7c3aed','#b45309','#0e7490','#be185d','#9d174d','#065f46','#1e3a5f'];
function avatarColor(name){ let h=0; for(let c of String(name)) h=(c.charCodeAt(0)+((h<<5)-h))|0; return COLORS[Math.abs(h)%COLORS.length]; }
function avatarInitial(name){ return String(name).trim().split(/\s+/).slice(0,2).map(w=>w[0]||'').join('').toUpperCase()||'?'; }

function api(action, params={}){
  return fetch('api/chat.php?action='+encodeURIComponent(action)+'&'+new URLSearchParams(params),
    {headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json());
}
function apiPost(action, body={}){
  return fetch('api/chat.php?action='+encodeURIComponent(action),{
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-Token':ME.csrf},
    body:JSON.stringify(body)
  }).then(r=>r.json());
}

let _toast=null;
function showToast(msg,ico='<i class="fas fa-info-circle"></i>',dur=3500){
  $id('toastIco').innerHTML=ico; $id('toastMsg').textContent=msg;
  const el=$id('toast'); el.classList.add('show');
  clearTimeout(_toast); _toast=setTimeout(()=>el.classList.remove('show'),dur);
}

function openOverlay(id){ $id(id).classList.add('open'); }
function closeOverlay(id){ $id(id).classList.remove('open'); }
document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.overlay.open,.img-viewer.open').forEach(el=>el.classList.remove('open')); });

/* ════════════════════════════════════════════════════
   STATE
════════════════════════════════════════════════════ */
let rooms        = [];
let currentRoom  = null;
let lastMsgId    = 0;
let _pollMsgLock = false;  // prevents concurrent pollMessages() calls
let currentMembers = [];
let _othersReadId = 0;
let replyToId    = null;
let replyToText  = '';
let replyToSender= '';
let uploadQueue  = []; // [{file, fileId, uploading}]
let membersPanel = false;
let onlineSet    = new Set();
let _prevDate    = '';
let _prevSender  = null;
let allMsgsLoaded= false;
let loadingMore  = false;
let _initialLoading = false;
let _myRoomRole     = 'member';
let _pinnedMsgId    = null;

/* ════════════════════════════════════════════════════
   ROOM LIST
════════════════════════════════════════════════════ */
function typeIcon(type){ return type==='channel'?'<i class="fas fa-bullhorn"></i>':type==='direct'?'<i class="fas fa-comments"></i>':'<i class="fas fa-users"></i>'; }

function updateTopbarStatus(){
  if(!currentRoom) return;
  const tbSubEl = $id('tbSub');
  if(!tbSubEl) return;
  if(currentRoom.type === 'direct'){
    const isOnline = onlineSet.has(currentRoom._peer_id);
    tbSubEl.innerHTML = isOnline
      ? '<span class="online-label"><i class="fas fa-circle" style="font-size:7px;vertical-align:middle;margin-right:4px"></i>В сети</span>'
      : 'не в сети';
  } else if(currentRoom.type === 'channel'){
    tbSubEl.innerHTML = '<i class="fas fa-bullhorn"></i> Канал';
  } else {
    tbSubEl.innerHTML = '<i class="fas fa-users"></i> Группа';
  }
}

function renderRoomList(filter=''){
  const lf = filter.toLowerCase();
  const list = rooms.filter(r=> !lf || (r.name||'').toLowerCase().includes(lf));
  if(!list.length){
    $id('roomList').innerHTML='<div style="padding:20px;text-align:center;color:var(--t3)">Нет бесед</div>';
    return;
  }
  $id('roomList').innerHTML = list.map(r=>{
    const name = r.name || 'Личный чат';
    const col  = r.avatar_color || avatarColor(name);
    const init = r.type==='channel'?'<i class="fas fa-bullhorn"></i>':r.type==='group'?'<i class="fas fa-users"></i>':avatarInitial(name);
    const isOnline = r.type==='direct' && onlineSet.has(r._peer_id);
    const unread = r.unread > 0 ? `<span class="unread-badge">${r.unread>99?'99+':r.unread}</span>` : '';
    const prev = r.last_msg_prev ? `<span class="sender">${esc(r.last_sender||'')}:</span>${esc(r.last_msg_prev)}` : '<em style="color:var(--t3)">Нет сообщений</em>';
    const ts   = r.last_msg_at ? fmtTime(r.last_msg_at) : '';
    const activeClass = currentRoom?.id===r.id ? 'active' : '';
    return `<div class="room-item ${activeClass}" onclick="openRoom(${r.id})">
      <div class="room-avatar-wrap">
        <div class="room-avatar" style="background:${col}">
          ${r.type==='direct'?`<span style="font-size:16px">${esc(init)}</span>`:init}
          ${isOnline?'<span class="online-dot"></span>':''}
        </div>
        <button class="room-ctx-btn" title="Действия" onclick="roomCtxMenu(event,${r.id})"><i class="fas fa-ellipsis-v"></i></button>
      </div>
      <div class="room-body">
        <div class="room-name">${r.type!=='group'?`<span class="room-type-icon">${typeIcon(r.type)}</span>`:''}${esc(name)}</div>
        <div class="room-preview">${prev}</div>
      </div>
      <div class="room-meta">
        <span class="room-time">${ts}</span>
        ${unread}
      </div>
    </div>`;
  }).join('');
}

async function loadRooms(){
  const d = await api('rooms');
  rooms = d.rooms || [];
  renderRoomList($id('searchInput').value);
}

$id('searchInput').addEventListener('input', ()=> renderRoomList($id('searchInput').value));

/* ════════════════════════════════════════════════════
   OPEN ROOM
════════════════════════════════════════════════════ */
async function openRoom(id){
  // Ищем комнату (id может быть числом или строкой из onclick)
  const room = rooms.find(r => r.id === id || r.id === +id);
  if (!room) { console.warn('[chat] room not found:', id); return; }

  currentRoom      = room;
  lastMsgId        = 0;
  _prevDate        = '';
  _prevSender      = null;
  allMsgsLoaded    = false;
  loadingMore      = false;
  _initialLoading  = true;

  // ── Переключаем UI ───────────────────────────────────
  const noRoomEl   = $id('noRoom');
  const chatViewEl = $id('chatView');
  if (noRoomEl)   noRoomEl.style.display   = 'none';
  if (chatViewEl) chatViewEl.style.display = 'flex';
  if (!chatViewEl) { console.error('[chat] #chatView not found in DOM'); }
  // On mobile — hide sidebar, show chat
  if (isMobile()) {
    $id('sidebar')?.classList.remove('mob-open');
  }

  // ── Топбар ───────────────────────────────────────────
  const name = room.name || 'Личный чат';
  const col  = room.avatar_color || avatarColor(name);
  const init = room.type === 'direct' ? avatarInitial(name) :
               room.type === 'channel' ? '<i class="fas fa-bullhorn"></i>' : '<i class="fas fa-users"></i>';

  const tbAvEl   = $id('tbAvatar');
  const tbNmEl   = $id('tbName');
  const tbSubEl  = $id('tbSub');
  const leaveBtn = $id('btnLeave');
  if (tbAvEl)  { tbAvEl.style.background = col; tbAvEl.innerHTML = init; }
  if (tbNmEl)  tbNmEl.textContent  = name;
  updateTopbarStatus();
  if (leaveBtn) leaveBtn.style.display = room.type !== 'direct' ? '' : 'none';

  // ── Сбрасываем состояние ─────────────────────────────
  membersPanel = false;
  $id('membersPanel')?.classList.remove('open');

  renderRoomList($id('searchInput')?.value ?? '');
  cancelReply();
  clearUploadQueue();

  // Сбрасываем ленту — loadMessages(true) покажет «нет сообщений» или саму ленту
  const msgsArea = $id('messagesArea');
  if(msgsArea) msgsArea.innerHTML = '';
  setEmpty(true);   // показываем заглушку пока идёт загрузка

  // ── Загружаем данные ─────────────────────────────────
  await loadMessages(true);
  _initialLoading = false;
  await Promise.all([loadMembers(), loadPinned()]);

  // Settings button visibility: show for non-direct rooms
  const settingsBtn = $id('btnRoomSettings');
  if (settingsBtn) settingsBtn.style.display = room.type !== 'direct' ? '' : 'none';

  // Мобильный: прячем сайдбар
  $id('sidebar')?.classList.remove('mob-open');

  // Отмечаем прочитанным
  if (lastMsgId) markRead(lastMsgId);
}

/* ════════════════════════════════════════════════════
   MESSAGES
════════════════════════════════════════════════════ */
const FILE_API = 'api/chat.php?action=file&id=';

function renderMsg(m, showSender){
  const own  = m.sender_id === ME.id;
  const sys  = m.msg_type === 'system' || m.sender_id === 0;
  const col  = avatarColor(m.sender_name);
  const init = avatarInitial(m.sender_name);

  // Day separator
  let html = '';
  const dateStr = fmtDate(m.created_at);
  if(dateStr !== _prevDate){ _prevDate=dateStr; html+=`<div class="day-sep">${esc(dateStr)}</div>`; }

  if(sys){
    return html + `<div class="msg-wrap system"><div class="msg-system-bub">${esc(m.body||'')}</div></div>`;
  }

  const showAv = !own && showSender;
  html += `<div class="msg-wrap ${own?'own':''}" data-mid="${m.id}" oncontextmenu="ctxMenu(event,${m.id},${own?1:0})">`;

  if(!own){
    html += showAv
      ? `<div class="msg-av" style="background:${col}">${esc(init)}</div>`
      : `<div class="msg-av-gap"></div>`;
  }

  html += `<div class="msg-col">`;

  if(showSender && !own)
    html += `<div class="msg-sender">${esc(m.sender_name)}</div>`;

  // Reply strip
  if(m.reply_to && m.reply_sender){
    html += `<div class="msg-reply">
      <div class="msg-reply-sender">${esc(m.reply_sender)}</div>
      <div class="msg-reply-text">${esc(m.reply_preview||'')}</div>
    </div>`;
  }

  html += `<div class="msg-bub">`;
  if(m.is_deleted){
    html += `<span class="msg-deleted"><i class="fas fa-ban"></i> Сообщение удалено</span>`;
  } else if(m.msg_type==='image' && m.file_id){
    html += `<img class="msg-img" src="${FILE_API}${m.file_id}" alt="${esc(m.orig_name||'фото')}"
              onclick="openImgViewer(${m.file_id},'${esc(m.orig_name||'')}')" loading="lazy">`;
    if(m.body) html += `<div class="msg-text" style="margin-top:6px">${esc(m.body).replace(/\n/g,'<br>')}</div>`;
  } else if(m.msg_type==='video' && m.file_id){
    html += `<video class="msg-video" controls preload="metadata"><source src="${FILE_API}${m.file_id}" type="${esc(m.mime_type||'video/mp4')}"></video>`;
    if(m.body) html += `<div class="msg-text" style="margin-top:6px">${esc(m.body).replace(/\n/g,'<br>')}</div>`;
  } else if(m.msg_type==='audio' && m.file_id){
    html += `<audio class="msg-audio" controls><source src="${FILE_API}${m.file_id}" type="${esc(m.mime_type||'audio/mpeg')}"></audio>`;
    if(m.body) html += `<div class="msg-text" style="margin-top:6px">${esc(m.body).replace(/\n/g,'<br>')}</div>`;
  } else if(m.msg_type==='file' && m.file_id){
    const icon = fileIcon(m.mime_type||'');
    html += `<a class="msg-file" href="${FILE_API}${m.file_id}" target="_blank" style="color:inherit;text-decoration:none">
      <span class="file-icon">${icon}</span>
      <div class="file-info">
        <div class="file-name">${esc(m.orig_name||'Файл')}</div>
        <div class="file-size">${fmtSize(m.file_size||0)}</div>
      </div>
    </a>`;
    if(m.body) html += `<div class="msg-text" style="margin-top:6px">${esc(m.body).replace(/\n/g,'<br>')}</div>`;
  } else {
    let displayBody = m.body || '';
    let fwdHeader = '';
    if(displayBody.startsWith('⟫ Переслано')){
      const nl = displayBody.indexOf('\n');
      fwdHeader = nl > -1 ? displayBody.slice(0, nl) : displayBody;
      displayBody = nl > -1 ? displayBody.slice(nl+1) : '';
    }
    if(fwdHeader) html += `<div class="msg-fwd-label">${esc(fwdHeader)}</div>`;
    if(displayBody) html += `<div class="msg-text">${esc(displayBody).replace(/\n/g,'<br>')}</div>`;
  }
  html += `</div>`; // msg-bub

  let statusIcon = '';
  if (own && !m.is_deleted) {
    const isRead = m.id <= _othersReadId;
    statusIcon = isRead
      ? `<span class="msg-status read" title="Прочитано"><i class="fas fa-check-double"></i></span>`
      : `<span class="msg-status" title="Доставлено"><i class="fas fa-check"></i></span>`;
  }
  html += `<div class="msg-footer"><span class="msg-time-txt">${fmtTime(m.created_at)}</span>${statusIcon}</div>`;
  html += `</div></div>`;
  return html;
}

function updateMsgStatuses(){
  document.querySelectorAll('.msg-wrap.own[data-mid]').forEach(el => {
    const mid = parseInt(el.dataset.mid);
    const statusEl = el.querySelector('.msg-status');
    if (!statusEl) return;
    const isRead = mid <= _othersReadId;
    statusEl.className = 'msg-status' + (isRead ? ' read' : '');
    statusEl.title = isRead ? 'Прочитано' : 'Доставлено';
    statusEl.innerHTML = isRead
      ? '<i class="fas fa-check-double"></i>'
      : '<i class="fas fa-check"></i>';
  });
}

function fileIcon(mime){
  if(mime.startsWith('image/')) return '<i class="fas fa-image"></i>';
  if(mime.startsWith('video/')) return '<i class="fas fa-film"></i>';
  if(mime.startsWith('audio/')) return '<i class="fas fa-music"></i>';
  if(mime.includes('pdf')) return '<i class="fas fa-file-pdf"></i>';
  if(mime.includes('word')||mime.includes('document')) return '<i class="fas fa-file-word"></i>';
  if(mime.includes('excel')||mime.includes('sheet')) return '<i class="fas fa-file-excel"></i>';
  if(mime.includes('powerpoint')||mime.includes('presentation')) return '<i class="fas fa-file-powerpoint"></i>';
  if(mime.includes('zip')||mime.includes('rar')||mime.includes('7z')) return '<i class="fas fa-file-archive"></i>';
  return '<i class="fas fa-folder"></i>';
}

/** Показать/скрыть пустое состояние и ленту сообщений */
function setEmpty(empty){
  const em = $id('msgEmpty');
  const ar = $id('messagesArea');
  if(em) em.style.display = empty ? 'flex' : 'none';
  if(ar) ar.style.display = empty ? 'none' : 'block';
}

async function loadMessages(initial=false){
  if(!currentRoom) return;
  const area = $id('messagesArea');
  if(!area) return;

  if(initial){
    area.innerHTML = '';
    setEmpty(true);           // показываем «нет сообщений» пока грузим
    _prevDate = ''; _prevSender = null;
  }

  const d    = await api('messages', {room_id: currentRoom.id, after: initial ? 0 : lastMsgId, limit: 50});
  const msgs = d.messages || [];
  if (d.others_read_id !== undefined) _othersReadId = d.others_read_id;

  if(!msgs.length){
    if(initial) setEmpty(true);
    return;
  }

  setEmpty(false);   // есть сообщения — прячем заглушку, показываем ленту

  let html = '';
  let prev = _prevSender;
  msgs.forEach(m => {
    const showSender = m.sender_id !== prev || m.msg_type === 'system';
    html += renderMsg(m, showSender);
    prev = m.sender_id;
    lastMsgId = Math.max(lastMsgId, m.id);
  });
  _prevSender = prev;

  area.insertAdjacentHTML('beforeend', html);
  if(initial) scrollBottom('instant');
  else        scrollBottom('smooth');
}

async function loadOlder(){
  if(!currentRoom || allMsgsLoaded || loadingMore) return;
  loadingMore = true;
  const area = $id('messagesArea');
  const firstId = parseInt(area.querySelector('[data-mid]')?.dataset.mid||'0');
  if(!firstId){ loadingMore=false; return; }

  const d = await api('messages',{room_id:currentRoom.id, before:firstId, limit:40});
  const msgs = d.messages||[];
  loadingMore = false;
  if(!msgs.length){ allMsgsLoaded=true; $id('loadMoreBtn')?.remove(); return; }

  const scrollH = area.scrollHeight;
  let html=''; let savedDate=_prevDate; let savedSender=_prevSender;
  _prevDate=''; _prevSender=null;
  msgs.forEach(m=>{ html+=renderMsg(m,true); });
  _prevDate=savedDate; _prevSender=savedSender;

  area.insertAdjacentHTML('afterbegin', html);
  area.scrollTop = area.scrollHeight - scrollH;
}

// Load more button at top
function ensureLoadMoreBtn(){
  if($id('loadMoreBtn')) return;
  const btn = document.createElement('button');
  btn.className='load-more-btn'; btn.id='loadMoreBtn'; btn.textContent='Загрузить ещё';
  btn.onclick = loadOlder;
  $id('messagesArea').prepend(btn);
}

function scrollBottom(behavior='smooth'){
  const a=$id('messagesArea');
  if(a) a.scrollTo({top:a.scrollHeight,behavior});
}

// Auto-scroll & load-more on scroll top
$id('messagesArea')?.addEventListener('scroll',function(){
  if(this.scrollTop < 80 && !allMsgsLoaded) { ensureLoadMoreBtn(); }
});

/* ════════════════════════════════════════════════════
   SEND MESSAGE
════════════════════════════════════════════════════ */
async function sendMessage(){
  if(!currentRoom) return;
  const inp  = $id('msgInput');
  const text = inp.innerText.trim();
  const hasFiles = uploadQueue.filter(u=>u.fileId).length > 0;

  if(!text && !hasFiles) return;

  $id('sendBtn').disabled = true;

  // Send files first, then text
  const pendingFiles = uploadQueue.filter(u=>u.fileId);

  if(pendingFiles.length){
    for(const u of pendingFiles){
      await apiPost('send',{
        room_id: currentRoom.id,
        file_id: u.fileId,
        text:    pendingFiles.length===1 ? text : '',
        reply_to: replyToId,
      });
    }
    // If text without files was also present (and multiple files)
    if(text && pendingFiles.length > 1){
      await apiPost('send',{ room_id:currentRoom.id, text, reply_to:replyToId });
    }
    clearUploadQueue();
  } else if(text){
    await apiPost('send',{ room_id:currentRoom.id, text, reply_to:replyToId });
  }

  inp.innerText = '';
  cancelReply();
  $id('sendBtn').disabled = false;
  inp.focus();

  // Poll immediately after send — but only if no concurrent poll is running
  if(!_pollMsgLock) pollMessages().catch(()=>{});
  loadRooms().catch(()=>{});
}

// Enter to send
$id('msgInput').addEventListener('keydown', e=>{
  if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendMessage(); }
});

/* ════════════════════════════════════════════════════
   FILE UPLOAD
════════════════════════════════════════════════════ */
$id('fileInput').addEventListener('change', async function(){
  const files = [...this.files];
  this.value='';
  for(const f of files) addFileToQueue(f);
});

function addFileToQueue(file){
  const id = Date.now()+Math.random();
  uploadQueue.push({id, file, fileId:null, uploading:true});
  renderUploadQueue();
  doUpload(id, file);
}

async function doUpload(qid, file){
  if(!currentRoom) return;
  const fd = new FormData();
  fd.append('file', file);
  fd.append('room_id', currentRoom.id);

  const r = await fetch('api/chat_upload.php',{
    method:'POST',
    headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':ME.csrf},
    body: fd,
  });
  const d = await r.json();
  const item = uploadQueue.find(u=>u.id===qid);
  if(!item) return;
  if(d.ok){
    item.fileId    = d.file_id;
    item.uploading = false;
    item.origName  = d.orig_name;
    item.mimeType  = d.mime_type;
    item.fileSize  = d.file_size;
  } else {
    uploadQueue = uploadQueue.filter(u=>u.id!==qid);
    showToast('Ошибка загрузки: '+(d.error||'неизвестная'), '<i class="fas fa-times-circle"></i>');
  }
  renderUploadQueue();
}

function renderUploadQueue(){
  const wrap = $id('uploadPreview');
  if(!wrap) return;
  if(!uploadQueue.length){ wrap.classList.remove('show'); wrap.innerHTML=''; return; }
  wrap.classList.add('show');
  wrap.innerHTML = uploadQueue.map(u=>{
    const isImg = u.mimeType && u.mimeType.startsWith('image/');
    const thumb = isImg && u.fileId ? `<img class="preview-thumb" src="${FILE_API}${u.fileId}" alt="">` : `<span style="font-size:24px">${fileIcon(u.mimeType||'')}</span>`;
    return `<div class="preview-item">
      ${thumb}
      <div>
        <div class="preview-name">${esc(u.file?.name||u.origName||'Файл')}</div>
        <div class="preview-size">${u.uploading?'<i class="fas fa-hourglass-half"></i> Загрузка…':fmtSize(u.fileSize||u.file?.size||0)}</div>
        ${u.uploading?'<div class="progress-bar" style="width:60%"></div>':''}
      </div>
      <button class="preview-remove" onclick="removeFromQueue('${u.id}')"><i class='fas fa-times'></i></button>
    </div>`;
  }).join('');
}

function removeFromQueue(id){
  uploadQueue = uploadQueue.filter(u=>String(u.id)!==String(id));
  renderUploadQueue();
}

function clearUploadQueue(){ uploadQueue=[]; renderUploadQueue(); }

/* ════════════════════════════════════════════════════
   REPLY & CONTEXT MENU
════════════════════════════════════════════════════ */
function startReply(msgId, senderName, text){
  replyToId     = msgId;
  replyToSender = senderName;
  replyToText   = text;
  $id('replyBarSender').textContent = senderName;
  $id('replyBarText').textContent   = text;
  $id('replyBar').classList.add('show');
  $id('msgInput').focus();
}
function cancelReply(){ replyToId=null; $id('replyBar')?.classList.remove('show'); }

let _ctx = null;
function ctxMenu(e, msgId, isOwn){
  e.preventDefault();
  if(_ctx){ _ctx.remove(); _ctx=null; }
  const area  = $id('messagesArea');
  const msgEl = area.querySelector(`[data-mid="${msgId}"]`);
  if(!msgEl) return;
  const body    = msgEl.querySelector('.msg-text,.msg-deleted')?.textContent || '';
  const sender  = msgEl.querySelector('.msg-sender')?.textContent || ME.name;
  const isAdmin = ['owner','admin'].includes(_myRoomRole);
  const canDel  = isOwn || isAdmin;
  // Редактировать: только своё И ещё не прочитано адресатом
  const canEdit = isOwn && msgId > _othersReadId;
  const canPin  = isAdmin && currentRoom?.type !== 'direct';
  const isPinned = _pinnedMsgId === msgId;

  let items = '';
  items += `<div class="ctx-item" onclick="startReply(${msgId},'${esc(sender)}','${esc(body.slice(0,80))}');closeCtx()">Ответить</div>`;
  items += `<div class="ctx-item" onclick="navigator.clipboard.writeText(${JSON.stringify(body)});closeCtx();showToast('Скопировано')">Копировать</div>`;
  if(canEdit) items += `<div class="ctx-item" onclick="startEditMsg(${msgId});closeCtx()">Изменить</div>`;
  if(canPin)  items += `<div class="ctx-item" onclick="${isPinned?'unpinMsg':'pinMsg'}(${msgId});closeCtx()">${isPinned?'Открепить':'Закрепить'}</div>`;
  items += `<div class="ctx-item" onclick="forwardMsg(${msgId},'${esc(body.slice(0,80))}');closeCtx()">Переслать</div>`;
  if(canDel)  items += `<div class="ctx-sep"></div><div class="ctx-item danger" onclick="deleteMsg(${msgId});closeCtx()">Удалить</div>`;

  const menu = document.createElement('div');
  menu.className = 'ctx-menu';
  document.body.appendChild(menu);
  menu.innerHTML = items;
  _ctx = menu;

  // Position: avoid going off screen edges
  const mw = menu.offsetWidth || 160;
  const mh = menu.offsetHeight || 200;
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  let x = e.clientX;
  let y = e.clientY;
  if(x + mw > vw - 8) x = vw - mw - 8;
  if(x < 8) x = 8;
  if(y + mh > vh - 8) y = vh - mh - 8;
  if(y < 8) y = 8;
  menu.style.cssText = `top:${y}px;left:${x}px`;

  setTimeout(()=>document.addEventListener('click', closeCtx, {once:true}), 10);
}
function closeCtx(){ if(_ctx){_ctx.remove();_ctx=null;} }

async function forwardMsg(msgId, previewText){
  // Show room picker overlay
  const rooms2 = rooms.filter(r => r.id !== currentRoom?.id);
  if(!rooms2.length){ showToast('Нет доступных бесед для пересылки'); return; }

  let overlay = $id('forwardOverlay');
  if(!overlay){
    overlay = document.createElement('div');
    overlay.id = 'forwardOverlay';
    overlay.className = 'overlay';
    overlay.innerHTML = `<div class="modal" style="max-width:380px">
      <div class="modal-hdr">
        <div class="modal-title">Переслать сообщение</div>
        <button class="modal-close" onclick="closeOverlay('forwardOverlay')"><i class="fas fa-times"></i></button>
      </div>
      <div class="modal-body" style="padding:0">
        <div id="forwardList" style="max-height:360px;overflow-y:auto"></div>
      </div>
    </div>`;
    document.body.appendChild(overlay);
  }

  overlay.dataset.srcMsg = msgId;
  $id('forwardList').innerHTML = rooms2.map(r => {
    const name = r.name || 'Личный чат';
    const col  = r.avatar_color || avatarColor(name);
    const init = r.type==='channel'?'<i class="fas fa-bullhorn"></i>':r.type==='group'?'<i class="fas fa-users"></i>':avatarInitial(name);
    return `<div style="display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .1s"
        onmouseover="this.style.background='var(--hover)'" onmouseout="this.style.background=''"
        onclick="doForward(${r.id},${msgId})">
      <div style="width:36px;height:36px;border-radius:50%;background:${col};color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">${init}</div>
      <div style="font-size:13px;font-weight:600">${esc(name)}</div>
    </div>`;
  }).join('');
  openOverlay('forwardOverlay');
}

async function doForward(toRoomId, msgId){
  closeOverlay('forwardOverlay');
  const msgEl = $id('messagesArea')?.querySelector(`[data-mid="${msgId}"]`);
  const body  = msgEl?.querySelector('.msg-text')?.textContent || '';
  const senderName = msgEl?.querySelector('.msg-sender')?.textContent ||
                     msgEl?.closest('.msg-wrap')?.querySelector('.msg-av')?.title || '';
  if(!body.trim()){ showToast('Нельзя переслать это сообщение'); return; }
  const fwdBody = (senderName ? `⟫ Переслано от: ${senderName}\n` : '⟫ Переслано\n') + body;
  const d = await apiPost('send', {room_id: toRoomId, text: fwdBody});
  if(d.ok || d.id) showToast('Сообщение переслано');
  else showToast(d.error || 'Ошибка пересылки');
}

async function deleteMsg(id){
  if(!confirm('Удалить сообщение?')) return;
  const d = await apiPost('delete_msg', {id});
  if(d.ok){
    // Обновляем DOM сразу — не ждём следующего poll
    const msgEl = $id('messagesArea')?.querySelector(`[data-mid="${id}"]`);
    if(msgEl){
      const bub = msgEl.querySelector('.msg-bub');
      if(bub) bub.innerHTML = '<span class="msg-deleted"><i class="fas fa-ban"></i> Сообщение удалено</span>';
    }
  } else {
    showToast(d.error || 'Ошибка удаления', '<i class="fas fa-times-circle"></i>');
  }
}

/* ════════════════════════════════════════════════════
   MEMBERS
════════════════════════════════════════════════════ */
async function loadMembers(){
  if(!currentRoom) return;
  const d = await api('room_members',{room_id:currentRoom.id});
  const members = d.members||[];
  currentMembers = members;
  // Update my room role
  const me = members.find(m=>m.user_id===ME.id);
  _myRoomRole = me?.room_role || 'member';
  const isOwner  = _myRoomRole === 'owner';
  const isRoomAdmin = ['owner','admin'].includes(_myRoomRole);
  $id('membersList').innerHTML = members.map(m=>{
    const col  = avatarColor(m.user_name);
    const init = avatarInitial(m.user_name);
    const rl   = m.room_role==='owner'?'<i class="fas fa-crown"></i> Владелец':m.room_role==='admin'?'<i class="fas fa-star"></i> Админ':'Участник';
    const isMe = m.user_id === ME.id;
    const canKick = isRoomAdmin && !isMe && m.room_role !== 'owner';
    const canPromote = isOwner && !isMe && m.room_role !== 'owner';
    const promoteLabel = m.room_role === 'admin' ? '<i class="fas fa-arrow-down"></i>' : '<i class="fas fa-arrow-up"></i>';
    const promoteTitle = m.room_role === 'admin' ? 'Снять администратора' : 'Сделать администратором';
    const newRole = m.room_role === 'admin' ? 'member' : 'admin';
    return `<div class="member-row">
      <div style="width:36px;height:36px;border-radius:50%;background:${col};display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;position:relative">
        ${esc(init)}
        ${m.online?'<span style="position:absolute;bottom:0;right:0;width:9px;height:9px;border-radius:50%;background:var(--green);border:2px solid var(--s2)"></span>':''}
      </div>
      <div class="member-info">
        <div class="member-nm">${esc(m.user_name)}${isMe?' <span style="color:var(--t3);font-size:11px">(Вы)</span>':''}</div>
        <div class="member-rl">${rl}</div>
      </div>
      <div class="member-actions">
        ${canPromote?`<button class="mbtn" title="${promoteTitle}" onclick="setMemberRole(${m.user_id},'${newRole}')">${promoteLabel}</button>`:''}
        ${canKick?`<button class="mbtn danger" title="Исключить" onclick="kickMember(${m.user_id},'${esc(m.user_name)}')"><i class="fas fa-user-slash"></i></button>`:''}
      </div>
    </div>`;
  }).join('');
}

function toggleMembersPanel(){
  membersPanel = !membersPanel;
  $id('membersPanel').classList.toggle('open',membersPanel);
}

async function openAddMemberModal(){
  closeOverlay('createRoomOverlay');
  openOverlay('addMemberOverlay');
  $id('addMemberList').innerHTML = '<div style="padding:16px;text-align:center;color:var(--t3)">Загрузка…</div>';
  const d2 = await api('room_candidates');
  const allUsers = d2.users || [];
  // Get currently existing member IDs
  const existingIds = new Set(currentMembers.map(m => m.user_id || m.id));
  existingIds.add(ME.id);
  const toShow = allUsers.filter(u => !existingIds.has(u.id));
  if (!toShow.length) {
    $id('addMemberList').innerHTML = '<div style="padding:16px;color:var(--t3);font-size:13px">Все пользователи уже в беседе</div>';
    return;
  }
  $id('addMemberList').innerHTML = toShow.map(a=>`<div class="member-select-item">
      <input type="checkbox" id="am_${a.id}" value="${a.id}" data-name="${esc(a.name)}" data-role="${esc(a.role)}">
      <div class="member-avatar-sm" style="background:${avatarColor(a.name)}">${esc(avatarInitial(a.name))}</div>
      <div class="member-name-txt">${esc(a.name)}</div>
    </div>`).join('');
}

async function addSelectedMembers(){
  if(!currentRoom) return;
  const checks = [...$id('addMemberList').querySelectorAll('input:checked')];
  if(!checks.length){ showToast('Выберите участников','<i class="fas fa-exclamation-triangle"></i>'); return; }
  for(const c of checks){
    await apiPost('add_member',{room_id:currentRoom.id, user_id:parseInt(c.value), user_name:c.dataset.name, user_role:c.dataset.role});
  }
  closeOverlay('addMemberOverlay');
  await loadMembers();
  await pollMessages();
}

/* ════════════════════════════════════════════════════
   CREATE ROOM
════════════════════════════════════════════════════ */
const ROOM_COLORS = ['#003366','#0055a5','#15803d','#7c3aed','#b45309','#0e7490','#be185d','#065f46','#1e3a5f','#78350f'];
let selectedColor = ROOM_COLORS[0];

function buildColorPills(){
  $id('colorPills').innerHTML = ROOM_COLORS.map(c=>
    `<div class="color-pill ${c===selectedColor?'sel':''}" style="background:${c}" onclick="selectColor('${c}')"></div>`
  ).join('');
}
function selectColor(c){ selectedColor=c; buildColorPills(); }

function buildMemberSelectList(){
  $id('memberSelectList').innerHTML = ALL_ADMINS.filter(a=>a.id!==ME.id).map(a=>
    `<div class="member-select-item">
      <input type="checkbox" id="ms_${a.id}" value="${a.id}" data-name="${esc(a.name)}" data-role="${esc(a.role)}">
      <div class="member-avatar-sm" style="background:${avatarColor(a.name)}">${esc(avatarInitial(a.name))}</div>
      <div class="member-name-txt">${esc(a.name)}</div>
      <div class="member-role-txt">${a.role==='super_admin'?'<i class="fas fa-star"></i>':'<i class="fas fa-crown"></i>'}</div>
    </div>`
  ).join('');
}

function openNewGroupModal(type){
  $id('createRoomType').value = type;
  $id('createRoomTitle').textContent = type==='channel' ? 'Создать канал' : 'Создать группу';
  $id('crName').placeholder = type==='channel' ? 'Название канала…' : 'Название группы…';
  $id('crName').value=''; $id('crDesc').value='';
  selectedColor = ROOM_COLORS[0];
  buildColorPills();
  buildMemberSelectList();
  openOverlay('createRoomOverlay');
}

async function createRoom(){
  const type = $id('createRoomType').value;
  const name = $id('crName').value.trim();
  if(!name){ showToast('Введите название','<i class="fas fa-exclamation-triangle"></i>'); return; }

  const checks  = [...$id('memberSelectList').querySelectorAll('input:checked')];
  const members = checks.map(c=>({id:parseInt(c.value),name:c.dataset.name,role:c.dataset.role}));

  const d = await apiPost('create_room',{type,name,description:$id('crDesc').value.trim(),color:selectedColor,members});
  closeOverlay('createRoomOverlay');
  if(d.ok||d.room_id){
    await loadRooms();
    await openRoom(d.room_id);
  } else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

/* Единая точка возврата к «нет выбранной беседы».
   Использует display='' чтобы media-query сам решал:
   flex на десктопе, none на мобильном (где показывается список). */
function showNoRoom(){
  currentRoom = null;
  const cv=$id('chatView'); if(cv) cv.style.display='none';
  const nr=$id('noRoom');   if(nr) nr.style.display='';
  // сбрасываем переходные состояния беседы
  $id('pinnedBar')?.classList.remove('show');
  $id('replyBar')?.classList.remove('show');
  $id('membersPanel')?.classList.remove('open');
  membersPanel = false;
  const ar=$id('messagesArea'); if(ar) ar.innerHTML='';
  // на мобильном возвращаемся к списку бесед
  if(isMobile()) $id('sidebar')?.classList.add('mob-open');
}

async function leaveRoom(){
  if(!currentRoom) return;
  if(!confirm('Покинуть этот чат?')) return;
  await apiPost('leave_room',{room_id:currentRoom.id});
  showNoRoom();
  await loadRooms();
}

/* ════════════════════════════════════════════════════
   ROOM SETTINGS
════════════════════════════════════════════════════ */
const RS_COLORS = ['#003366','#0055a5','#15803d','#7c3aed','#b45309','#0e7490','#be185d','#065f46','#1e3a5f','#92400e'];
let _rsSelectedColor = '';

function openRoomSettings(){
  if(!currentRoom || currentRoom.type==='direct') return;
  const canDelete = _myRoomRole==='owner' || ME.role==='super_admin';
  $id('rsName').value = currentRoom.name || '';
  $id('rsDesc').value = currentRoom.description || '';
  _rsSelectedColor = currentRoom.avatar_color || RS_COLORS[0];
  $id('rsColorPills').innerHTML = RS_COLORS.map(c=>
    `<div class="rs-color-pill${c===_rsSelectedColor?' sel':''}" style="background:${c}" onclick="rsPickColor('${c}')"></div>`
  ).join('');
  $id('rsDangerZone').style.display = canDelete ? 'block' : 'none';
  openOverlay('roomSettingsOverlay');
}

function rsPickColor(c){
  _rsSelectedColor = c;
  $id('rsColorPills').querySelectorAll('.rs-color-pill').forEach(el=>{
    el.classList.toggle('sel', el.style.backgroundColor===c);
  });
}

async function saveRoomSettings(){
  if(!currentRoom) return;
  const name = $id('rsName').value.trim();
  const desc = $id('rsDesc').value.trim();
  if(!name){ showToast('Введите название','<i class="fas fa-exclamation-triangle"></i>'); return; }
  const d = await apiPost('update_room',{room_id:currentRoom.id, name, description:desc, avatar_color:_rsSelectedColor});
  if(d.ok){
    currentRoom.name=name; currentRoom.description=desc; currentRoom.avatar_color=_rsSelectedColor;
    $id('tbName').textContent=name;
    const av=$id('tbAvatar'); if(av) av.style.background=_rsSelectedColor;
    closeOverlay('roomSettingsOverlay');
    await loadRooms();
    showToast('Сохранено','<i class="fas fa-check-circle"></i>');
  } else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

async function deleteRoom(){
  if(!currentRoom) return;
  if(!confirm(`Удалить комнату «${currentRoom.name}»? Это нельзя отменить.`)) return;
  const d = await apiPost('delete_room',{room_id:currentRoom.id});
  if(d.ok){
    closeOverlay('roomSettingsOverlay');
    showNoRoom();
    await loadRooms();
    showToast('Комната удалена','<i class="fas fa-trash"></i>');
  } else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

/* ════════════════════════════════════════════════════
   MEMBER ADMIN
════════════════════════════════════════════════════ */
async function kickMember(userId, userName){
  if(!currentRoom) return;
  if(!confirm(`Исключить ${userName} из комнаты?`)) return;
  const d = await apiPost('kick_member',{room_id:currentRoom.id, user_id:userId});
  if(d.ok){
    await loadMembers();
    showToast(`${userName} исключён(а)`,'<i class="fas fa-user-slash"></i>');
  } else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

async function setMemberRole(userId, role){
  if(!currentRoom) return;
  const d = await apiPost('set_member_role',{room_id:currentRoom.id, user_id:userId, role});
  if(d.ok){
    await loadMembers();
    showToast(role==='admin'?'Назначен администратором':'Права администратора сняты','<i class="fas fa-star"></i>');
  } else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

/* ════════════════════════════════════════════════════
   PINNED MESSAGES
════════════════════════════════════════════════════ */
async function loadPinned(){
  if(!currentRoom) return;
  const d = await api('pinned',{room_id:currentRoom.id});
  const pin = d.pinned;
  _pinnedMsgId = pin ? pin.id : null;
  const bar=$id('pinnedBar'), txt=$id('pinnedText'), unpinBtn=$id('pinnedUnpinBtn');
  if(!bar) return;
  if(pin){
    if(txt) txt.textContent=pin.sender_name+': '+(pin.body||'Файл');
    if(unpinBtn) unpinBtn.style.display=['owner','admin'].includes(_myRoomRole)?'':'none';
    bar.classList.add('show');
  } else {
    bar.classList.remove('show');
  }
}

function scrollToPinned(){
  if(!_pinnedMsgId) return;
  const el=$id('messagesArea')?.querySelector(`[data-mid="${_pinnedMsgId}"]`);
  if(el) el.scrollIntoView({behavior:'smooth',block:'center'});
}

async function pinMsg(msgId){
  if(!currentRoom) return;
  const d = await apiPost('pin_msg',{room_id:currentRoom.id, msg_id:msgId});
  if(d.ok){ await loadPinned(); showToast('Сообщение закреплено','<i class="fas fa-thumbtack"></i>'); }
  else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

async function unpinMsg(){
  if(!currentRoom) return;
  const d = await apiPost('pin_msg',{room_id:currentRoom.id, msg_id:null});
  if(d.ok){ await loadPinned(); showToast('Сообщение откреплено','<i class="fas fa-thumbtack"></i>'); }
  else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

/* ════════════════════════════════════════════════════
   EDIT MESSAGE
════════════════════════════════════════════════════ */
function startEditMsg(msgId){
  const area=$id('messagesArea');
  const msgEl=area?.querySelector(`[data-mid="${msgId}"]`);
  const bub=msgEl?.querySelector('.msg-bub');
  const textEl=bub?.querySelector('.msg-text');
  if(!bub||!textEl) return;
  const origText=textEl.textContent;
  bub.innerHTML=`<div class="msg-edit-wrap">
    <textarea class="msg-edit-input" id="editInput_${msgId}">${esc(origText)}</textarea>
    <div class="msg-edit-btns">
      <button class="edit-cancel" onclick="cancelEditMsg(${msgId},${JSON.stringify(origText)})">Отмена</button>
      <button class="edit-save" onclick="saveEditMsg(${msgId})"><i class="fas fa-check"></i> Сохранить</button>
    </div>
  </div>`;
  const inp=$id(`editInput_${msgId}`);
  if(inp){inp.focus();inp.setSelectionRange(inp.value.length,inp.value.length);}
}

function cancelEditMsg(msgId, origText){
  const area=$id('messagesArea');
  const bub=area?.querySelector(`[data-mid="${msgId}"]`)?.querySelector('.msg-bub');
  if(bub) bub.innerHTML=`<div class="msg-text">${esc(origText)}</div>`;
}

async function saveEditMsg(msgId){
  const inp=$id(`editInput_${msgId}`);
  if(!inp) return;
  const text=inp.value.trim();
  if(!text){showToast('Нельзя сохранить пустое сообщение','<i class="fas fa-exclamation-triangle"></i>');return;}
  const d=await apiPost('edit_msg',{id:msgId,text});
  if(d.ok){
    const bub=$id('messagesArea')?.querySelector(`[data-mid="${msgId}"]`)?.querySelector('.msg-bub');
    if(bub) bub.innerHTML=`<div class="msg-text">${esc(text)}</div><span class="msg-edited">(ред.)</span>`;
  } else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

/* ════════════════════════════════════════════════════
   ROOM CONTEXT MENU (sidebar)
════════════════════════════════════════════════════ */
function roomCtxMenu(e, roomId){
  e.stopPropagation();
  const room = rooms.find(r=>r.id===roomId||r.id===+roomId);
  if (!room) return;
  const dd = $id('roomCtxDropdown');
  const items = [];
  if (room.id !== (currentRoom?.id)) {
    items.push({icon:'fa-comments',label:'Открыть',fn:`openRoom(${room.id})`});
  }
  if (room.type !== 'direct') {
    items.push({icon:'fa-user-plus',label:'Добавить участника',fn:`openRoom(${room.id});setTimeout(openAddMemberModal,300)`});
  }
  items.push({icon:'fa-sign-out-alt',label:'Покинуть',fn:`confirmLeaveRoom(${room.id})`});
  if (ME.isAdmin && room.type !== 'direct') {
    items.push({icon:'fa-cog',label:'Настройки',fn:`openRoom(${room.id});openRoomSettings()`});
    items.push({icon:'fa-trash',label:'Удалить',fn:`confirmDeleteRoom(${room.id})`,cls:'danger'});
  }
  dd.innerHTML = items.map(it=>`
    <button onclick="${it.fn};hideRoomCtx()" style="display:flex;align-items:center;gap:10px;
      width:100%;padding:11px 16px;border:none;background:none;text-align:left;cursor:pointer;
      font-size:13px;font-weight:500;transition:background .12s;
      ${it.cls==='danger'?'color:#ff6b6b':'color:rgba(255,255,255,.9)'}">
      <i class="fas ${it.icon}" style="width:14px;text-align:center;opacity:.75"></i>${it.label}
    </button>`).join('');
  const rect = e.currentTarget.getBoundingClientRect();
  // Show off-screen first to measure, then position correctly
  dd.style.visibility = 'hidden';
  dd.style.display = 'block';
  dd.style.left = '0px';
  dd.style.top  = '0px';
  requestAnimationFrame(()=>{
    const w = dd.offsetWidth;
    const h = dd.offsetHeight;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    // Prefer right-align to trigger; shift left if overflows right edge
    let left = rect.right - w;
    if (left < 8) left = 8;
    if (left + w > vw - 8) left = vw - w - 8;
    // Prefer below; flip above if overflows bottom
    let top = rect.bottom + 4;
    if (top + h > vh - 8) top = rect.top - h - 4;
    dd.style.left = left + 'px';
    dd.style.top  = top  + 'px';
    dd.style.visibility = 'visible';
  });
}
function hideRoomCtx(){ $id('roomCtxDropdown').style.display='none'; }
document.addEventListener('click', ()=> hideRoomCtx());

async function confirmLeaveRoom(roomId){
  const room = rooms.find(r=>r.id===roomId);
  if (!room) return;
  if (!confirm(`Покинуть «${room.name || 'чат'}»?`)) return;
  if (currentRoom?.id === roomId) showNoRoom();
  await apiPost('leave_room',{room_id:roomId});
  await loadRooms();
}
async function confirmDeleteRoom(roomId){
  const room = rooms.find(r=>r.id===roomId);
  if (!room) return;
  if (!confirm(`Удалить комнату «${room.name}» навсегда?`)) return;
  if (currentRoom?.id === roomId) showNoRoom();
  await apiPost('delete_room',{room_id:roomId});
  showToast('<i class="fas fa-trash"></i>','Комната удалена','');
  await loadRooms();
}

/* ════════════════════════════════════════════════════
   ADMIN PANEL
════════════════════════════════════════════════════ */
let _cuUsers = [];
async function openAdminPanel(){
  if (!ME.isAdmin) return;
  openOverlay('adminPanelOverlay');
  // load users tab by default
  $id('cuList').innerHTML='<div style="padding:20px;text-align:center;color:var(--t3)">Загрузка…</div>';
  const d = await api('chat_users');
  _cuUsers = d.users || [];
  renderCuList();
}

function switchApTab(tabId, btn){
  document.querySelectorAll('.ap-tab-pane').forEach(p=>p.style.display='none');
  document.querySelectorAll('.ap-tab-btn').forEach(b=>b.classList.remove('active'));
  $id(tabId).style.display='flex';
  btn.classList.add('active');
  if(tabId==='apRooms') loadApRooms();
  if(tabId==='apStats') loadApStats();
}

async function loadApRooms(){
  $id('apRoomList').innerHTML='<div style="padding:20px;text-align:center;color:var(--t3)">Загрузка…</div>';
  const d = await api('admin_rooms');
  const list = d.rooms || [];
  if(!list.length){ $id('apRoomList').innerHTML='<div style="padding:20px;text-align:center;color:var(--t3)">Нет комнат</div>'; return; }
  $id('apRoomList').innerHTML = list.map(r=>{
    const typeIcon = r.type==='channel'?'<i class="fas fa-bullhorn"></i>':r.type==='direct'?'<i class="fas fa-comments"></i>':'<i class="fas fa-users"></i>';
    const name = r.name || (r.type==='direct'?'Личная беседа':'Без названия');
    const ts = r.last_msg_at ? fmtTime(r.last_msg_at) : '—';
    return `<div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)">
      <div style="width:36px;height:36px;border-radius:50%;background:${r.avatar_color||'#003366'};color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">${typeIcon}</div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(name)}</div>
        <div style="font-size:11px;color:var(--t3)">${r.member_count} участников · ${ts}</div>
      </div>
      ${r.id!==1?`<button onclick="apDeleteRoom(${r.id},this)" style="border:none;background:rgba(220,38,38,.15);color:#f87171;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px" title="Удалить комнату"><i class="fas fa-trash"></i></button>`:''}
    </div>`;
  }).join('');
}

async function apDeleteRoom(roomId, btn){
  if(!confirm('Удалить эту комнату и все сообщения в ней?')) return;
  btn.disabled=true;
  await apiPost('delete_room',{room_id:roomId});
  await loadApRooms();
  await loadRooms();
}

async function loadApStats(){
  $id('apStatsContent').innerHTML='<div style="text-align:center;color:var(--t3);padding:20px">Загрузка…</div>';
  const d = await api('admin_stats');
  const s = d.stats||{};
  $id('apStatsContent').innerHTML=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div style="background:var(--bg);border-radius:10px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--accent)">${s.messages||0}</div>
        <div style="font-size:12px;color:var(--t3);margin-top:4px"><i class="fas fa-comment"></i> Сообщений</div>
      </div>
      <div style="background:var(--bg);border-radius:10px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--accent)">${s.rooms||0}</div>
        <div style="font-size:12px;color:var(--t3);margin-top:4px"><i class="fas fa-comments"></i> Комнат</div>
      </div>
      <div style="background:var(--bg);border-radius:10px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--accent)">${s.users||0}</div>
        <div style="font-size:12px;color:var(--t3);margin-top:4px"><i class="fas fa-users"></i> Участников</div>
      </div>
      <div style="background:var(--bg);border-radius:10px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--accent)">${s.online||0}</div>
        <div style="font-size:12px;color:var(--t3);margin-top:4px"><i class="fas fa-circle" style="color:var(--green)"></i> Онлайн</div>
      </div>
      <div style="background:var(--bg);border-radius:10px;padding:16px;text-align:center;grid-column:span 2">
        <div style="font-size:28px;font-weight:700;color:var(--accent)">${s.files||0}</div>
        <div style="font-size:12px;color:var(--t3);margin-top:4px"><i class="fas fa-paperclip"></i> Файлов загружено</div>
      </div>
    </div>`;
}
function renderCuList(){
  const q = ($id('cuSearch')?.value||'').toLowerCase();
  const list = _cuUsers.filter(u=> !q || u.full_name.toLowerCase().includes(q));
  if (!list.length){ $id('cuList').innerHTML='<div style="padding:20px;text-align:center;color:var(--t3)">Нет сотрудников</div>'; return; }
  $id('cuList').innerHTML = list.map(u=>{
    const init = (u.full_name||'?').split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
    const roleLabel = {super_admin:'Супер-адм',admin:'Админ',manager:'Менеджер',employee:'Сотрудник'}[u.role]||u.role;
    return `<div class="cu-row" id="cu-row-${u.id}">
      <div class="cu-avatar" style="background:${avatarColor(u.full_name)}">${init}</div>
      <div class="cu-info">
        <div class="cu-name">${esc(u.full_name)}</div>
        <div class="cu-meta">${roleLabel}${u.chat_username?` · @${esc(u.chat_username)}`:''}${u.has_qr?' · QR':''}</div>
      </div>
      <div class="cu-actions">
        <button class="cu-toggle ${u.chat_access?'on':'off'}" title="${u.chat_access?'Отключить доступ':'Включить доступ'}"
          onclick="toggleCuAccess(${u.id},this)"></button>
        <button style="width:28px;height:28px;border:none;background:var(--bg);border-radius:6px;cursor:pointer;font-size:12px"
          title="Редактировать" onclick="toggleCuEdit(${u.id})"><i class="fas fa-pencil-alt"></i></button>
      </div>
    </div>
    <div class="cu-edit-form" id="cu-edit-${u.id}">
      <div class="cu-field"><label>Логин для чата</label>
        <input type="text" id="cu-username-${u.id}" value="${esc(u.chat_username||'')}" placeholder="chat_login">
      </div>
      <div class="cu-field"><label>Пароль (оставьте пустым — без изменений)</label>
        <input type="password" id="cu-password-${u.id}" placeholder="Новый пароль…">
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary" style="font-size:12px;padding:6px 12px" onclick="saveCuUser(${u.id})">
          <i class="fas fa-save"></i> Сохранить
        </button>
        <button class="btn btn-ghost" style="font-size:12px;padding:6px 12px" onclick="toggleCuEdit(${u.id})">
          Отмена
        </button>
        <button class="btn btn-danger" style="font-size:12px;padding:6px 12px;margin-left:auto" onclick="removeCuUser(${u.id})">
          <i class="fas fa-user-minus"></i> Убрать доступ
        </button>
      </div>
    </div>`;
  }).join('');
}
function toggleCuEdit(id){
  const el = $id(`cu-edit-${id}`);
  el.classList.toggle('open');
}
async function toggleCuAccess(id, btn){
  const isOn = btn.classList.contains('on');
  const newAccess = isOn ? 0 : 1;
  await apiPost('save_chat_user',{id, chat_access: newAccess});
  btn.classList.toggle('on', !isOn);
  btn.classList.toggle('off', isOn);
  const u = _cuUsers.find(u=>u.id===id);
  if (u) u.chat_access = newAccess;
  showToast('<i class="fas fa-user-check"></i>', isOn?'Доступ отключён':'Доступ включён', '');
}
async function saveCuUser(id){
  const username = $id(`cu-username-${id}`).value.trim();
  const password = $id(`cu-password-${id}`).value;
  const payload = {id, chat_username: username};
  if (password) payload.chat_password = password;
  const d = await apiPost('save_chat_user', payload);
  if (d.error){ alert(d.error); return; }
  const u = _cuUsers.find(u=>u.id===id);
  if (u){ u.chat_username = username; if(password) u.has_password=1; }
  $id(`cu-password-${id}`).value = '';
  toggleCuEdit(id);
  renderCuList();
  showToast('<i class="fas fa-check"></i>','Сохранено','');
}
async function removeCuUser(id){
  if (!confirm('Убрать доступ к чату для этого сотрудника?')) return;
  await apiPost('remove_chat_user',{id});
  const u = _cuUsers.find(u=>u.id===id);
  if (u){ u.chat_access=0; u.chat_username=null; u.has_password=0; }
  toggleCuEdit(id);
  renderCuList();
  showToast('<i class="fas fa-user-minus"></i>','Доступ убран','');
}

/* ════════════════════════════════════════════════════
   DIRECT MESSAGE
════════════════════════════════════════════════════ */
function buildDirectUserList(){
  const online = [...$id('directUserList').querySelectorAll('[data-uid]')].map(e=>e.dataset.uid);
  $id('directUserList').innerHTML = ALL_ADMINS.filter(a=>a.id!==ME.id).map(a=>{
    const isOn = onlineSet.has(a.id);
    return `<div class="member-select-item" onclick="openDirectWith(${a.id},'${esc(a.name)}')">
      <div class="member-avatar-sm" style="background:${avatarColor(a.name)};position:relative">
        ${esc(avatarInitial(a.name))}
        ${isOn?'<span style="position:absolute;bottom:0;right:0;width:8px;height:8px;border-radius:50%;background:var(--green);border:2px solid var(--s2)"></span>':''}
      </div>
      <div class="member-name-txt">${esc(a.name)}</div>
      <div class="member-role-txt">${isOn?'<i class="fas fa-circle" style="color:#4dcd5e"></i> онлайн':'<i class="fas fa-circle" style="color:#666"></i> офлайн'}</div>
    </div>`;
  }).join('') || '<div style="padding:16px;color:var(--t3);text-align:center">Нет доступных пользователей</div>';
}

$id('newChatBtn').addEventListener('click',()=>{
  buildDirectUserList();
  openOverlay('newChatOverlay');
});

async function openDirectWith(toId, toName){
  closeOverlay('newChatOverlay');
  const d = await apiPost('create_room',{type:'direct', to_id:toId, to_name:toName});
  if(d.ok||d.room_id){
    await loadRooms();
    openRoom(d.room_id);
  } else showToast(d.error||'Ошибка','<i class="fas fa-times-circle"></i>');
}

/* ════════════════════════════════════════════════════
   IMAGE VIEWER
════════════════════════════════════════════════════ */
function openImgViewer(fileId, name){
  $id('imgViewerSrc').src = FILE_API + fileId;
  $id('imgViewerName').textContent = name || '';
  $id('imgViewer').classList.add('open');
}
function closeImgViewer(){ $id('imgViewer').classList.remove('open'); }

/* ════════════════════════════════════════════════════
   PRESENCE / POLL
════════════════════════════════════════════════════ */
async function pingPresence(){
  const d = await api('ping');
  // Online set from presence
  const onlineD = await api('online');
  onlineSet = new Set((onlineD.online||[]).map(u=>parseInt(u.user_id)));
  renderRoomList($id('searchInput').value);
  updateTopbarStatus();
}

async function pollMessages(){
  if(!currentRoom || _initialLoading || _pollMsgLock) return;
  _pollMsgLock = true;
  try {
    const d = await api('messages', {room_id: currentRoom.id, after: lastMsgId});
    const msgs = d.messages || [];
    if (d.others_read_id !== undefined && d.others_read_id > _othersReadId) {
      _othersReadId = d.others_read_id;
      updateMsgStatuses();
    }
    if(!msgs.length) return;

    setEmpty(false);

    const area = $id('messagesArea');
    let html = '';
    msgs.forEach(m => {
      // skip if already in DOM (race condition guard)
      if(area?.querySelector(`[data-mid="${m.id}"]`)) { lastMsgId = Math.max(lastMsgId, m.id); return; }
      const showSender = m.sender_id !== _prevSender || m.msg_type === 'system';
      html += renderMsg(m, showSender);
      _prevSender = m.sender_id;
      lastMsgId   = Math.max(lastMsgId, m.id);
    });

    if(area && html) {
      area.insertAdjacentHTML('beforeend', html);
      scrollBottom('smooth');
    }

    markRead(lastMsgId);
    updateMsgStatuses();

    // Тихое обновление unread в сайдбаре
    const r = rooms.find(x => x.id === currentRoom.id);
    if(r) r.unread = 0;
    loadRooms().catch(()=>{});
  } finally {
    _pollMsgLock = false;
  }
}

async function pollSignals(){
  // Signals must always poll — never blocked by _initialLoading
  try {
    const d = await api('poll');
    for(const s of (d.signals||[])) await handleSignal(s);
  } catch(_) {}
}

async function pollAll(){
  // Run signals always; messages only when not loading
  await Promise.allSettled([pollMessages(), pollSignals()]);
}

function markRead(lastId){
  if(!currentRoom || !lastId) return;
  apiPost('mark_read',{room_id:currentRoom.id, last_id:lastId}).catch(()=>{});
  const r=rooms.find(x=>x.id===currentRoom.id);
  if(r) r.unread=0;
}

/* ════════════════════════════════════════════════════
   WebRTC CALLS
════════════════════════════════════════════════════ */
const ICE = {
  iceServers:[
    {urls:'stun:stun.l.google.com:19302'},
    {urls:'stun:stun1.l.google.com:19302'},
    {urls:'stun:stun.cloudflare.com:3478'},
    // Metered OpenRelay — primary free TURN, UDP and TCP/TLS variants so at
    // least one survives restrictive mobile carrier firewalls
    {urls:'turn:openrelay.metered.ca:80',          username:'openrelayproject',credential:'openrelayproject'},
    {urls:'turn:openrelay.metered.ca:443',          username:'openrelayproject',credential:'openrelayproject'},
    {urls:'turn:openrelay.metered.ca:443?transport=tcp', username:'openrelayproject',credential:'openrelayproject'},
    {urls:'turns:openrelay.metered.ca:443',         username:'openrelayproject',credential:'openrelayproject'},
    // Metered's newer load-balanced relay (a.relay.metered.ca)
    {urls:'turn:a.relay.metered.ca:80',             username:'openrelayproject',credential:'openrelayproject'},
    {urls:'turn:a.relay.metered.ca:443',            username:'openrelayproject',credential:'openrelayproject'},
    {urls:'turn:a.relay.metered.ca:443?transport=tcp', username:'openrelayproject',credential:'openrelayproject'},
    {urls:'turns:a.relay.metered.ca:443',           username:'openrelayproject',credential:'openrelayproject'},
  ],
  iceCandidatePoolSize: 10,
};

let pc=null,localStream=null,screenStream=null;
let callId=null,callPeerId=null,callPeerName='',callIsVideo=true,isCaller=false;
let isMuted=false,isCamOff=false,isScreen=false;
let pendingInvite=null;
let callStart=null,callTmrInt=null;
let _iceBuffer = []; // buffer ICE candidates before remoteDescription is set
let _remoteDescSet = false;

function genCallId(){ return 'c_'+Date.now()+'_'+Math.random().toString(36).slice(2,7); }

function callPeerInRoom(){
  if(!currentRoom) return null;
  if(currentRoom.type==='direct'){
    // Use currentMembers (live data) instead of DOM scraping
    const peer = currentMembers.find(m => m.user_id !== ME.id);
    if(peer) return {id: peer.user_id, name: peer.user_name};
  }
  return null;
}

async function initiateCall(video=true){
  const peer = callPeerInRoom();
  if(!peer){ showToast('Выберите личный чат для звонка','<i class="fas fa-info-circle"></i>'); return; }
  if(pc){ showToast('Вы уже в звонке','<i class="fas fa-exclamation-triangle"></i>'); return; }
  await startCallTo(peer.id, peer.name, video);
}

async function startCallTo(toId, toName, video=true){
  if(pc){ showToast('Вы уже в звонке','<i class="fas fa-exclamation-triangle"></i>'); return; }
  callId=genCallId(); callPeerId=toId; callPeerName=toName; callIsVideo=video; isCaller=true;
  try{
    localStream = await getStream(video);
    setupPC();
    localStream.getTracks().forEach(t=>pc.addTrack(t,localStream));
    const offer = await pc.createOffer({
      offerToReceiveAudio:true,
      offerToReceiveVideo:video
    });
    await pc.setLocalDescription(offer);
    await apiPost('signal',{call_id:callId,to_id:toId,sig_type:'invite',payload:{offer,callerName:ME.name,isVideo:video}});
    showCallWindow(toName,video);
    showToast('Вызов '+toName+'…','<i class="fas fa-phone"></i>',20000);
  } catch(e){ showToast('Ошибка: '+e.message,'<i class="fas fa-times-circle"></i>'); cleanup(); }
}

async function getStream(video){
  // iOS Safari requires facingMode instead of width/height constraints
  const isMob = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
  const videoConstraints = video
    ? (isMob ? {facingMode:'user'} : {width:{ideal:1280},height:{ideal:720}})
    : false;
  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      audio: {echoCancellation:true, noiseSuppression:true, sampleRate:48000},
      video: videoConstraints
    });
    return stream;
  } catch(e) {
    console.warn('[webrtc] getStream failed:', e.name, e.message);
    if(video){
      showToast('Камера недоступна, только аудио','<i class="fas fa-exclamation-triangle"></i>');
      try { return await navigator.mediaDevices.getUserMedia({audio:true,video:false}); } catch(e2){ throw e2; }
    }
    throw e;
  }
}

let _reconnTimer = null;
let _iceRestartCount = 0;
// Call keepalive — send ping every 5s, watchdog fires if no pong for 18s
let _kaSendInt = null;
let _kaWatchdog = null;

function startKeepalive(){
  stopKeepalive();
  _kaSendInt = setInterval(()=>{
    if(callId && callPeerId)
      apiPost('signal',{call_id:callId,to_id:callPeerId,sig_type:'keepalive'}).catch(()=>{});
  }, 5000);
  resetKaWatchdog();
}

function resetKaWatchdog(){
  clearTimeout(_kaWatchdog);
  _kaWatchdog = setTimeout(()=>{
    // Remote side stopped sending keepalives — network drop or app killed
    showToast('Соединение прервано','<i class="fas fa-phone-slash"></i>');
    hangUp(false);
  }, 18000);
}

function stopKeepalive(){
  clearInterval(_kaSendInt); _kaSendInt = null;
  clearTimeout(_kaWatchdog);  _kaWatchdog = null;
}

function setupPC(){
  _iceBuffer = [];
  _remoteDescSet = false;
  _iceRestartCount = 0;
  pc = new RTCPeerConnection(ICE);
  pc.onicecandidate          = _onIceCandidate;
  pc.ontrack                 = _onTrack;
  pc.onconnectionstatechange = _onConnState;
  pc.oniceconnectionstatechange = _onIceState;
}

// Named handlers — referenced both in setupPC and relay-only PC recreation
function _onIceCandidate(e){
  if(e.candidate) apiPost('signal',{call_id:callId,to_id:callPeerId,sig_type:'ice',payload:e.candidate});
}

function _onTrack(e){
  const stream = e.streams[0];
  const vidEl = $id('vidRemote');
  vidEl.srcObject = stream;
  vidEl.play().catch(()=>{});
  const check = ()=>{
    const hasV = stream.getVideoTracks().some(t=>t.enabled && t.readyState==='live');
    $id('callNoVid').style.display = hasV?'none':'flex';
    vidEl.style.display = hasV?'block':'none';
  };
  check(); setTimeout(check, 1200);
}

function _onConnState(){
  const st = pc?.connectionState;
  console.log('[webrtc] connectionState:', st);
  if(st === 'connected'){
    clearTimeout(_reconnTimer);
    // iOS: re-attempt play() once media flows (ontrack can fire outside gesture)
    const rv=$id('vidRemote');
    if(rv && rv.srcObject){ rv.play().catch(()=>{}); _onTrack({streams:[rv.srcObject]}); }
  } else if(st === 'disconnected'){
    _reconnTimer = setTimeout(()=>{
      if(pc?.connectionState !== 'connected') hangUp(false);
    }, 8000);
  } else if(st === 'failed'){
    clearTimeout(_reconnTimer);
    hangUp(false);
  }
}

async function _onIceState(){
  const ist = pc?.iceConnectionState;
  console.log('[webrtc] iceConnectionState:', ist);
  if(ist === 'failed'){
      // Try ICE restart once before giving up — critical for mobile-to-mobile
      // (carrier-grade NAT, first TURN allocation may fail or time out)
      if(_iceRestartCount < 2 && pc){
        _iceRestartCount++;
        console.log('[webrtc] ICE failed, restart attempt', _iceRestartCount);
        // Attempt 1: normal restart. Attempt 2: force relay-only (TURN)
        // so carrier-grade NAT can't block the path
        const forceRelay = _iceRestartCount >= 2;
        if(forceRelay){
          // Replace PC with relay-only config and re-add tracks
          pc.close(); pc = null;
          _remoteDescSet = false; _iceBuffer = [];
          const relayCfg = Object.assign({}, ICE, {iceTransportPolicy:'relay'});
          pc = new RTCPeerConnection(relayCfg);
          pc.onicecandidate = _onIceCandidate;
          pc.ontrack = _onTrack;
          pc.onconnectionstatechange = _onConnState;
          pc.oniceconnectionstatechange = _onIceState;
          if(localStream) localStream.getTracks().forEach(t=>pc.addTrack(t,localStream));
          console.log('[webrtc] switched to relay-only ICE');
        } else {
          pc.restartIce();
        }
        if(isCaller){
          try{
            const offer = await pc.createOffer({iceRestart:true, offerToReceiveAudio:true, offerToReceiveVideo:callIsVideo});
            await pc.setLocalDescription(offer);
            await apiPost('signal',{call_id:callId,to_id:callPeerId,sig_type:'restart',payload:{offer,forceRelay}});
          } catch(e){ console.warn('[webrtc] restart offer failed', e); }
        }
      } else {
        clearTimeout(_reconnTimer);
        hangUp(false);
      }
  }
}

function showCallWindow(name,video){
  callIsVideo=video;
  $id('callPeerNm').textContent = name;
  $id('callPeerAv').textContent = avatarInitial(name);
  $id('callPeerAv').style.background = avatarColor(name);
  const localVid = $id('vidLocal');
  localVid.srcObject = localStream;
  localVid.style.display = video?'block':'none';
  // iOS requires explicit play() after srcObject assignment
  localVid.play().catch(()=>{});
  $id('callNoVid').style.display = 'flex'; // show placeholder until remote track arrives
  $id('vidRemote').style.display = 'none';
  $id('callWin').classList.add('open');
  startKeepalive();
  callStart=Date.now();
  clearInterval(callTmrInt);
  callTmrInt=setInterval(()=>{
    const s=Math.floor((Date.now()-callStart)/1000);
    $id('callTmr').textContent=Math.floor(s/60)+':'+pad2(s%60);
  },1000);
  updateCCUI();
}

function updateCCUI(){
  $id('ccMute').innerHTML  = isMuted  ?'<i class="fas fa-microphone-slash"></i>':'<i class="fas fa-microphone"></i>';
  $id('ccMute').classList.toggle('muted',isMuted);
  $id('ccCam').innerHTML   = isCamOff ?'<i class="fas fa-video-slash"></i>':'<i class="fas fa-video"></i>';
  $id('ccCam').classList.toggle('muted',isCamOff);
  $id('ccScreen').classList.toggle('muted',isScreen);
  $id('screenBadge').classList.toggle('on',isScreen);
  $id('ccCam').style.display = (localStream&&localStream.getVideoTracks().length)?'':'none';
}

function showIncoming(sig){
  pendingInvite=sig;
  const pl=sig.payload||{};
  const nm=pl.callerName||sig.from_name;
  $id('incAv').textContent=avatarInitial(nm); $id('incAv').style.background=avatarColor(nm);
  $id('incName').textContent=nm;
  $id('incType').textContent=(pl.isVideo!==false)?'Видеозвонок':'Аудиозвонок';
  $id('incomingOverlay').classList.add('open');
  startRing();
}

async function acceptCall(){
  stopRing(); $id('incomingOverlay').classList.remove('open');
  if(!pendingInvite) return;
  const sig=pendingInvite; pendingInvite=null;
  const pl=sig.payload||{};
  callId=sig.call_id; callPeerId=sig.from_id; callPeerName=pl.callerName||sig.from_name;
  callIsVideo=pl.isVideo!==false; isCaller=false;
  try{
    localStream=await getStream(callIsVideo); setupPC();
    localStream.getTracks().forEach(t=>pc.addTrack(t,localStream));
    await pc.setRemoteDescription(new RTCSessionDescription(pl.offer));
    _remoteDescSet = true;
    // Flush buffered ICE candidates
    for(const c of _iceBuffer){ try{ await pc.addIceCandidate(new RTCIceCandidate(c)); }catch(_){} }
    _iceBuffer = [];
    const ans=await pc.createAnswer({
      offerToReceiveAudio:true,
      offerToReceiveVideo:callIsVideo
    });
    await pc.setLocalDescription(ans);
    await apiPost('signal',{call_id:callId,to_id:callPeerId,sig_type:'answer',payload:ans});
    showCallWindow(callPeerName,callIsVideo);
  } catch(e){ showToast('Ошибка: '+e.message,'<i class="fas fa-times-circle"></i>'); cleanup(); }
}

function rejectCall(){
  stopRing(); $id('incomingOverlay').classList.remove('open');
  if(pendingInvite){ apiPost('signal',{call_id:pendingInvite.call_id,to_id:pendingInvite.from_id,sig_type:'reject'}); pendingInvite=null; }
}

function toggleMute(){ isMuted=!isMuted; if(localStream) localStream.getAudioTracks().forEach(t=>t.enabled=!isMuted); updateCCUI(); }
function toggleCam(){  isCamOff=!isCamOff; if(localStream) localStream.getVideoTracks().forEach(t=>t.enabled=!isCamOff); updateCCUI(); }

async function toggleScreen(){
  if(!isScreen){
    try{
      screenStream=await navigator.mediaDevices.getDisplayMedia({video:true});
      const st=screenStream.getVideoTracks()[0];
      const sender=pc.getSenders().find(s=>s.track?.kind==='video');
      if(sender) await sender.replaceTrack(st);
      $id('vidLocal').srcObject=screenStream;
      st.onended=()=>{ isScreen=false; stopScreen(); };
      isScreen=true; updateCCUI();
    } catch(e){ showToast('Экран недоступен','<i class="fas fa-exclamation-triangle"></i>'); }
  } else stopScreen();
}
async function stopScreen(){
  isScreen=false;
  if(screenStream){ screenStream.getTracks().forEach(t=>t.stop()); screenStream=null; }
  if(localStream&&pc){
    const ct=localStream.getVideoTracks()[0];
    if(ct){ const s=pc.getSenders().find(s=>s.track?.kind==='video'); if(s) await s.replaceTrack(ct); }
    const lv=$id('vidLocal'); lv.srcObject=localStream; lv.play().catch(()=>{});
  }
  updateCCUI();
}

async function hangUp(send=true){
  if(send&&callPeerId) await apiPost('signal',{call_id:callId,to_id:callPeerId,sig_type:'hangup'}).catch(()=>{});
  cleanup(); $id('callWin').classList.remove('open'); clearInterval(callTmrInt); $id('callTmr').textContent='0:00';
  showToast('Звонок завершён','<i class="fas fa-phone-slash"></i>');
}

function cleanup(){
  clearTimeout(_reconnTimer);
  stopKeepalive();
  stopRing();
  if(screenStream){screenStream.getTracks().forEach(t=>t.stop());screenStream=null;}
  if(localStream) {localStream.getTracks().forEach(t=>t.stop()); localStream=null;}
  if(pc){pc.close();pc=null;}
  const rv=$id('vidRemote'), lv=$id('vidLocal');
  if(rv){rv.srcObject=null; rv.style.display='none';}
  if(lv){lv.srcObject=null;}
  $id('callNoVid').style.display='flex';
  callId=callPeerId=callPeerName=null; isMuted=isCamOff=isScreen=isCaller=false; pendingInvite=null;
  _iceBuffer=[]; _remoteDescSet=false; _iceRestartCount=0;
}

async function handleSignal(sig){
  switch(sig.sig_type){
    case 'invite':
      if(pc){ apiPost('signal',{call_id:sig.call_id,to_id:sig.from_id,sig_type:'busy'}); }
      else showIncoming(sig);
      break;
    case 'answer':
      if(pc && isCaller && sig.payload){
        await pc.setRemoteDescription(new RTCSessionDescription(sig.payload));
        _remoteDescSet = true;
        // Flush ICE candidates buffered before answer arrived
        for(const c of _iceBuffer){ try{ await pc.addIceCandidate(new RTCIceCandidate(c)); }catch(_){} }
        _iceBuffer = [];
      }
      break;
    case 'ice':
      if(!pc || !sig.payload) break;
      if(!_remoteDescSet){
        // Buffer until remoteDescription is set
        _iceBuffer.push(sig.payload);
      } else {
        try{ await pc.addIceCandidate(new RTCIceCandidate(sig.payload)); }catch(_){}
      }
      break;
    case 'restart':
      // Caller restarted ICE — callee recreates PC if relay-only requested, then re-answers
      if(!isCaller && sig.payload){
        try{
          const pl = sig.payload;
          const offer = pl.offer || pl;
          if(pl.forceRelay && pc){
            pc.close(); pc = null;
            _remoteDescSet = false; _iceBuffer = [];
            const relayCfg = Object.assign({}, ICE, {iceTransportPolicy:'relay'});
            pc = new RTCPeerConnection(relayCfg);
            pc.onicecandidate = _onIceCandidate;
            pc.ontrack = _onTrack;
            pc.onconnectionstatechange = _onConnState;
            pc.oniceconnectionstatechange = _onIceState;
            if(localStream) localStream.getTracks().forEach(t=>pc.addTrack(t,localStream));
          }
          if(!pc) break;
          await pc.setRemoteDescription(new RTCSessionDescription(offer));
          _remoteDescSet = true;
          for(const c of _iceBuffer){ try{ await pc.addIceCandidate(new RTCIceCandidate(c)); }catch(_){} }
          _iceBuffer = [];
          const ans = await pc.createAnswer();
          await pc.setLocalDescription(ans);
          await apiPost('signal',{call_id:callId,to_id:callPeerId,sig_type:'answer',payload:ans});
        } catch(e){ console.warn('[webrtc] restart answer failed', e); }
      }
      break;
    case 'keepalive':
      // Remote side is alive — reset watchdog timer
      if(callId && sig.call_id === callId) resetKaWatchdog();
      break;
    case 'reject':
      hangUp(false);
      showToast(sig.from_name+' отклонил(а) звонок','<i class="fas fa-phone-slash"></i>');
      break;
    case 'hangup': hangUp(false); break;
    case 'busy':
      hangUp(false);
      showToast(sig.from_name+' занят(а)','<i class="fas fa-circle" style="color:#e53935"></i>');
      break;
  }
}

/* ─── Ringtone ─── */
let _ac=null,_ri=null;
function startRing(){
  stopRing();
  _ac=new(window.AudioContext||window.webkitAudioContext)();
  function r(){ const now=_ac.currentTime; [0,.2].forEach(dt=>{const o=_ac.createOscillator(),g=_ac.createGain(); o.type='sine';o.frequency.value=880; g.gain.setValueAtTime(.2,now+dt); g.gain.exponentialRampToValueAtTime(.001,now+dt+.15); o.connect(g);g.connect(_ac.destination); o.start(now+dt);o.stop(now+dt+.15);}); }
  r(); _ri=setInterval(r,1200);
}
function stopRing(){ clearInterval(_ri); try{_ac?.close();}catch(_){} _ac=null; }

/* ════════════════════════════════════════════════════
   MOBILE
════════════════════════════════════════════════════ */
function closeMobileChat(){
  // Просто возвращаем список бесед поверх (сайдбар занимает весь экран),
  // не трогаем noRoom='flex', иначе заглушка вылезает из-под выреза экрана.
  $id('sidebar')?.classList.add('mob-open');
}

function isMobile(){ return window.innerWidth <= 680; }

// Touch long-press for room context menu on mobile
(function(){
  let _ltTimer, _ltTarget;
  document.addEventListener('touchstart', e=>{
    const wrap = e.target.closest('.room-avatar-wrap');
    if (!wrap) return;
    _ltTarget = wrap;
    _ltTimer = setTimeout(()=>{
      const btn = wrap.querySelector('.room-ctx-btn');
      if (btn) btn.click();
    }, 500);
  }, {passive:true});
  document.addEventListener('touchend', ()=>{ clearTimeout(_ltTimer); }, {passive:true});
  document.addEventListener('touchmove', ()=>{ clearTimeout(_ltTimer); }, {passive:true});
})();

/* ════════════════════════════════════════════════════
   INIT & POLLING LOOP
════════════════════════════════════════════════════ */
async function init(){
  // Mobile: show sidebar first
  if (isMobile()) {
    $id('sidebar')?.classList.add('mob-open');
  }

  try {
    await pingPresence();
    await loadRooms();

    // On desktop open general channel; on mobile wait for user tap
    if (!isMobile()) {
      const general = rooms.find(r => r.id === 1 || r.id === '1');
      if (general) await openRoom(general.id);
    }
  } catch(e) {
    console.error('[chat] init error:', e);
  }

  // Опрос каждые 2 сек (сообщения + сигналы)
  setInterval(() => pollAll().catch(()=>{}), 2000);
  // Обновление присутствия и списка комнат каждые 10 сек
  setInterval(async () => {
    try { await pingPresence(); await loadRooms(); } catch(_) {}
  }, 10000);

  // iOS/Android: keyboard pushes content up — track visual viewport height so
  // the input area stays glued to the top of the keyboard. Only apply the
  // offset while the message input is actually focused, otherwise standalone
  // PWA mode (where visualViewport.height differs from innerHeight even with
  // no keyboard) would falsely push the bottom bar up.
  if (window.visualViewport && isMobile()) {
    const mainEl = $id('main');
    const inputBox = $id('msgInput');
    let _kbActive = false;
    const applyOffset = () => {
      if (!mainEl) return;
      if (!_kbActive) { mainEl.style.bottom = ''; return; }
      const keyboardH = Math.max(0, window.innerHeight - window.visualViewport.height - window.visualViewport.offsetTop);
      mainEl.style.bottom = keyboardH > 80 ? keyboardH + 'px' : '';
    };
    inputBox?.addEventListener('focus', () => { _kbActive = true; setTimeout(applyOffset, 100); });
    inputBox?.addEventListener('blur',  () => { _kbActive = false; mainEl && (mainEl.style.bottom = ''); });
    window.visualViewport.addEventListener('resize', applyOffset);
    window.visualViewport.addEventListener('scroll', applyOffset);
  }
}

// Запускаем после полной загрузки DOM
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

// Public rooms browser
async function openPublicRooms(){
  closeOverlay('newChatOverlay');
  openOverlay('publicRoomsOverlay');
  await loadPublicRooms();
}
async function loadPublicRooms(filter=''){
  const d = await api('public_rooms');
  const list = (d.rooms||[]).filter(r=> !filter || r.name?.toLowerCase().includes(filter.toLowerCase()));
  const el = $id('publicRoomList');
  if (!list.length){ el.innerHTML='<div style="padding:16px;text-align:center;color:var(--t3)">Нет публичных комнат</div>'; return; }
  el.innerHTML = list.map(r=>`
    <div style="display:flex;align-items:center;gap:10px;padding:10px;border-bottom:1px solid var(--border)">
      <div style="width:40px;height:40px;border-radius:50%;background:${r.avatar_color||'#003366'};
        color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">
        ${r.type==='channel'?'<i class="fas fa-bullhorn"></i>':'<i class="fas fa-users"></i>'}
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:600">${esc(r.name||'')}</div>
        <div style="font-size:12px;color:var(--t3)">${r.member_count} участников${r.description?` · ${esc(r.description.slice(0,40))}`:''}
        </div>
      </div>
      <button class="btn ${r.is_member?'btn-ghost':'btn-primary'}" style="font-size:12px;padding:6px 10px"
        onclick="${r.is_member?`openRoom(${r.id});closeOverlay('publicRoomsOverlay')`:`joinPublicRoom(${r.id})`}">
        ${r.is_member?'Открыть':'Вступить'}
      </button>
    </div>`).join('');
}
async function joinPublicRoom(roomId){
  await apiPost('join_room',{room_id:roomId});
  await loadRooms();
  showToast('Вы вступили в комнату','<i class="fas fa-check"></i>');
  await loadPublicRooms();
}

// User search for DM
let _searchTimer;
function openUserSearch(){
  closeOverlay('newChatOverlay');
  openOverlay('userSearchOverlay');
  setTimeout(()=>$id('userSearchInput')?.focus(),100);
}
async function searchUsers(){
  clearTimeout(_searchTimer);
  _searchTimer = setTimeout(async()=>{
    const q = $id('userSearchInput')?.value||'';
    if (q.length < 2){ $id('userSearchResults').innerHTML='<div style="padding:12px;color:var(--t3);font-size:13px">Введите минимум 2 символа</div>'; return; }
    const d = await api('search_users', {q});
    const list = d.users||[];
    if (!list.length){ $id('userSearchResults').innerHTML='<div style="padding:12px;color:var(--t3);font-size:13px">Никого не найдено</div>'; return; }
    $id('userSearchResults').innerHTML = list.map(u=>`
      <div class="member-select-item" onclick="openDirectWith(${u.id},'${esc(u.full_name)}');closeOverlay('userSearchOverlay')">
        <div class="member-avatar-sm" style="background:${avatarColor(u.full_name)}">${esc(avatarInitial(u.full_name))}</div>
        <div class="member-name-txt">${esc(u.full_name)}${u.chat_username?` <span style="color:var(--t3);font-size:11px">@${esc(u.chat_username)}</span>`:''}</div>
      </div>`).join('');
  }, 300);
}
function openProfile(){
  if(!ME) return;
  const col = avatarColor(ME.name);
  $id('profileAvatar').style.background = col;
  $id('profileAvatar').textContent = avatarInitial(ME.name);
  $id('profileName').textContent = ME.name || '—';
  $id('profileOrg').textContent = ME.organization || '—';
  $id('profilePos').textContent = ME.position || '—';
  openOverlay('profileOverlay');
}
</script>

<div class="overlay" id="profileOverlay" style="display:none">
  <div class="modal" style="max-width:380px">
    <div class="modal-hdr">
      <div class="modal-title">Мой профиль</div>
      <button class="modal-close" onclick="closeOverlay('profileOverlay')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div style="display:flex;justify-content:center">
        <div id="profileAvatar" style="width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#fff"></div>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--text-3);display:block;margin-bottom:5px">Имя</label>
        <div id="profileName" style="font-size:15px;font-weight:600;padding:8px 12px;background:var(--bg);border-radius:8px;border:1px solid var(--border)"></div>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--text-3);display:block;margin-bottom:5px">Организация</label>
        <div id="profileOrg" style="font-size:13px;color:var(--text-2);padding:8px 12px;background:var(--bg);border-radius:8px;border:1px solid var(--border)"></div>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--text-3);display:block;margin-bottom:5px">Должность</label>
        <div id="profilePos" style="font-size:13px;color:var(--text-2);padding:8px 12px;background:var(--bg);border-radius:8px;border:1px solid var(--border)"></div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
