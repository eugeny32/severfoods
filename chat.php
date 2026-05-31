<?php
/**
 * =====================================================
 *  CANTEEN MESSENGER — Telegram-like chat
 * =====================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

checkAdmin();

$uid    = (int)$_SESSION['user_id'];
$uname  = $_SESSION['user_name']  ?? 'Admin';
$urole  = $_SESSION['role']       ?? 'admin';
$isSA   = $urole === 'super_admin';
$csrf   = Csrf::getToken();

// Список всех admin-пользователей для «Добавить участника»
$allAdmins = $pdo->query(
    "SELECT id, full_name, role FROM employees
     WHERE is_active=1 AND role IN ('admin','super_admin')
     ORDER BY full_name"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Мессенджер — <?= htmlspecialchars(APP_NAME) ?></title>
<?= Csrf::meta() ?>
<style>
/* ════════════════════════════════════════════════════
   BASE
════════════════════════════════════════════════════ */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;overflow:hidden;font-family:'Segoe UI',system-ui,-apple-system,sans-serif;font-size:14px}
:root{
  --s:#17212b;--s2:#232e3c;--s3:#2b5278;--s4:#1c2733;
  --blue:#2b9cf2;--blue2:#1a8de0;--green:#4dcd5e;
  --red:#e53935;--yellow:#fdd835;
  --t1:#fff;--t2:rgba(255,255,255,.7);--t3:rgba(255,255,255,.4);--t4:rgba(255,255,255,.2);
  --border:rgba(255,255,255,.06);
  --msg-in:#182533;--msg-in-t:#fff;
  --msg-out:#2b5278;--msg-out-t:#fff;
  --hover:rgba(255,255,255,.05);--active:rgba(43,146,242,.18);
  --r:10px;--r2:18px;
}

/* ════════════════════════════════════════════════════
   LAYOUT
════════════════════════════════════════════════════ */
.app{display:flex;height:100vh;background:var(--s)}

/* ─── SIDEBAR ─── */
.sidebar{
  width:320px;min-width:280px;max-width:380px;
  display:flex;flex-direction:column;
  background:var(--s2);border-right:1px solid var(--border);
  flex-shrink:0;position:relative;
}
.sidebar-hdr{
  padding:14px 14px 10px;display:flex;align-items:center;gap:10px;
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
  width:36px;height:36px;border-radius:50%;background:var(--blue);
  border:none;color:#fff;font-size:20px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  transition:background .2s;
}
.new-btn:hover{background:var(--blue2)}

/* Room list */
.room-list{flex:1;overflow-y:auto}
.room-list::-webkit-scrollbar{width:3px}
.room-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}

.room-item{
  display:flex;align-items:center;gap:12px;
  padding:10px 14px;cursor:pointer;transition:background .12s;
  border-bottom:1px solid var(--border);position:relative;
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
.room-name{font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
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
.topbar-name{font-weight:700;color:var(--t1);font-size:15px}
.topbar-sub{font-size:12px;color:var(--t3);margin-top:1px}
.topbar-actions{display:flex;gap:4px}
.topbar-btn{
  width:36px;height:36px;border-radius:50%;background:none;border:none;
  color:var(--t2);font-size:18px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:background .15s;
}
.topbar-btn:hover{background:var(--hover);color:var(--t1)}

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

.msg-sender{font-size:12px;font-weight:700;color:var(--blue);margin-bottom:3px;padding-left:12px}
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
.msg-edited{font-size:11px;color:var(--t3);font-style:italic}

/* Context menu */
.ctx-menu{
  position:fixed;z-index:2000;background:var(--s2);
  border:1px solid var(--border);border-radius:var(--r);
  box-shadow:0 8px 30px rgba(0,0,0,.4);min-width:160px;
  overflow:hidden;animation:fadeIn .15s ease;
}
.ctx-item{
  padding:10px 16px;cursor:pointer;color:var(--t1);
  font-size:13px;display:flex;align-items:center;gap:10px;
  transition:background .12s;
}
.ctx-item:hover{background:var(--hover)}
.ctx-item.danger{color:#ff6b6b}

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
  padding:10px 14px;display:flex;align-items:flex-end;gap:10px;flex-shrink:0;
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
  padding:10px 16px;color:var(--t1);font-size:14px;
  resize:none;outline:none;line-height:1.5;font-family:inherit;
}
.input-box:empty::before{content:attr(data-placeholder);color:var(--t3);pointer-events:none}

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
  padding:18px 20px 14px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
}
.modal-title{font-size:17px;font-weight:800;color:var(--t1)}
.modal-close{background:none;border:none;color:var(--t3);font-size:22px;cursor:pointer;padding:0 4px}
.modal-close:hover{color:var(--t1)}
.modal-body{padding:20px}
.modal-footer{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}

.form-field{margin-bottom:16px}
.form-field label{display:block;font-size:12px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
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
  padding:10px 20px;border-radius:var(--r);border:none;font-family:inherit;
  font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;
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

/* ════ ANIMATIONS ════ */
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.fade-in{animation:fadeIn .2s ease}

/* ════ RESPONSIVE ════ */
@media(max-width:680px){
  .sidebar{
    position:absolute;left:0;top:0;bottom:0;z-index:200;
    transform:translateX(-100%);transition:transform .25s;
  }
  .sidebar.mob-open{transform:translateX(0)}
  .mob-back-btn{display:flex!important}
  .members-panel{width:100%}
}
.mob-back-btn{display:none;align-items:center;justify-content:center;
  width:36px;height:36px;border:none;background:none;color:var(--t2);font-size:22px;cursor:pointer}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<div class="app">

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-hdr">
    <a href="index.php" class="hdr-back" title="На главную">←</a>
    <div class="search-wrap">
      <span class="search-icon"><i class="fas fa-search"></i></span>
      <input id="searchInput" type="text" placeholder="Поиск…">
    </div>
    <button class="new-btn" id="newChatBtn" title="Новый чат / группа / канал"><i class="fas fa-pencil-alt"></i></button>
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
        <button class="topbar-btn" id="btnLeave" title="Покинуть" onclick="leaveRoom()" style="display:none"><i class="fas fa-sign-out-alt"></i></button>
      </div>
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
      <div id="directUserList" style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r)"></div>
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

<!-- ═══ JS DATA ═══ -->
<script>
const ME = {
  id:   <?= $uid ?>,
  name: <?= json_encode($uname) ?>,
  role: <?= json_encode($urole) ?>,
  csrf: <?= json_encode($csrf) ?>,
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
  $id('toastIco').textContent=ico; $id('toastMsg').textContent=msg;
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

/* ════════════════════════════════════════════════════
   ROOM LIST
════════════════════════════════════════════════════ */
function typeIcon(type){ return type==='channel'?'<i class="fas fa-bullhorn"></i>':type==='direct'?'<i class="fas fa-comments"></i>':'<i class="fas fa-users"></i>'; }

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
      <div class="room-avatar" style="background:${col}">
        ${r.type==='direct'?`<span style="font-size:16px">${esc(init)}</span>`:esc(init)}
        ${isOnline?'<span class="online-dot"></span>':''}
      </div>
      <div class="room-body">
        <div class="room-name">${r.type!=='group'?`<span class="room-type-icon">${typeIcon(r.type)}</span>`:''}${esc(name)}</div>
        <div class="room-preview">${prev}</div>
      </div>
      <div class="room-meta"><span class="room-time">${ts}</span>${unread}</div>
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

  currentRoom   = room;
  lastMsgId     = 0;
  _prevDate     = '';
  _prevSender   = null;
  allMsgsLoaded = false;
  loadingMore   = false;

  // ── Переключаем UI ───────────────────────────────────
  const noRoomEl   = $id('noRoom');
  const chatViewEl = $id('chatView');
  if (noRoomEl)   noRoomEl.style.display   = 'none';
  if (chatViewEl) chatViewEl.style.display = 'flex';
  if (!chatViewEl) { console.error('[chat] #chatView not found in DOM'); }

  // ── Топбар ───────────────────────────────────────────
  const name = room.name || 'Личный чат';
  const col  = room.avatar_color || avatarColor(name);
  const init = room.type === 'direct' ? avatarInitial(name) :
               room.type === 'channel' ? '<i class="fas fa-bullhorn"></i>' : '<i class="fas fa-users"></i>';

  const tbAvEl   = $id('tbAvatar');
  const tbNmEl   = $id('tbName');
  const tbSubEl  = $id('tbSub');
  const leaveBtn = $id('btnLeave');
  if (tbAvEl)  { tbAvEl.style.background = col; tbAvEl.textContent = init; }
  if (tbNmEl)  tbNmEl.textContent  = name;
  if (tbSubEl) tbSubEl.innerHTML = room.type === 'direct'  ? '<i class="fas fa-comments"></i> Личный чат' :
                                      room.type === 'channel' ? '<i class="fas fa-bullhorn"></i> Канал'       : '<i class="fas fa-users"></i> Группа';
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
  await loadMembers();

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
    html += `<div class="msg-text">${esc(m.body||'').replace(/\n/g,'<br>')}</div>`;
  }
  html += `</div>`; // msg-bub

  html += `<div class="msg-footer"><span class="msg-time-txt">${fmtTime(m.created_at)}</span></div>`;
  html += `</div></div>`;
  return html;
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

  await pollAll();
  await loadRooms();
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
  const area = $id('messagesArea');
  const msgEl= area.querySelector(`[data-mid="${msgId}"]`);
  if(!msgEl) return;
  const body  = msgEl.querySelector('.msg-text,.msg-deleted')?.textContent||'';
  const sender= msgEl.querySelector('.msg-sender')?.textContent || ME.name;

  const menu = document.createElement('div');
  menu.className = 'ctx-menu';
  menu.style.cssText = `top:${e.clientY}px;left:${e.clientX}px`;
  menu.innerHTML = `
    <div class="ctx-item" onclick="startReply(${msgId},'${esc(sender)}','${esc(body.slice(0,80))}');closeCtx()"><i class="fas fa-reply"></i> Ответить</div>
    <div class="ctx-item" onclick="navigator.clipboard.writeText(${JSON.stringify(body)});closeCtx();showToast('Скопировано','<i class="fas fa-check-circle"></i>')"><i class="fas fa-copy"></i> Копировать</div>
    ${isOwn?`<div class="ctx-item danger" onclick="deleteMsg(${msgId});closeCtx()"><i class="fas fa-trash"></i> Удалить</div>`:''}
  `;
  document.body.appendChild(menu);
  _ctx = menu;
  setTimeout(()=>document.addEventListener('click',closeCtx,{once:true}),10);
}
function closeCtx(){ if(_ctx){_ctx.remove();_ctx=null;} }

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
  $id('membersList').innerHTML = members.map(m=>{
    const col  = avatarColor(m.user_name);
    const init = avatarInitial(m.user_name);
    const rl   = m.room_role==='owner'?'<i class="fas fa-crown"></i> Владелец':m.room_role==='admin'?'<i class="fas fa-star"></i> Админ':'Участник';
    return `<div class="member-row">
      <div class="member-av" style="width:36px;height:36px;border-radius:50%;background:${col};display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0">${esc(init)}</div>
      <div class="member-info">
        <div class="member-nm">${esc(m.user_name)}${m.user_id===ME.id?' (Вы)':''}</div>
        <div class="member-rl">${rl}</div>
      </div>
      <div class="member-status ${m.online?'online':''}"></div>
    </div>`;
  }).join('');
}

function toggleMembersPanel(){
  membersPanel = !membersPanel;
  $id('membersPanel').classList.toggle('open',membersPanel);
}

function openAddMemberModal(){
  closeOverlay('createRoomOverlay');
  const existing = new Set(
    [...$id('membersList').querySelectorAll('.member-nm')].map(el=>el.textContent.replace(' (Вы)','').trim())
  );
  $id('addMemberList').innerHTML = ALL_ADMINS
    .filter(a=>a.id!==ME.id && !existing.has(a.name))
    .map(a=>`<div class="member-select-item">
      <input type="checkbox" id="am_${a.id}" value="${a.id}" data-name="${esc(a.name)}" data-role="${esc(a.role)}">
      <div class="member-avatar-sm" style="background:${avatarColor(a.name)}">${esc(avatarInitial(a.name))}</div>
      <div class="member-name-txt">${esc(a.name)}</div>
      <div class="member-role-txt">${a.role==='super_admin'?'<i class="fas fa-star"></i> Супер-admin':'<i class="fas fa-crown"></i> Admin'}</div>
    </div>`).join('');
  openOverlay('addMemberOverlay');
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

async function leaveRoom(){
  if(!currentRoom) return;
  if(!confirm('Покинуть этот чат?')) return;
  await apiPost('leave_room',{room_id:currentRoom.id});
  currentRoom = null;
  const cv=$id('chatView'); if(cv) cv.style.display='none';
  const nr=$id('noRoom');   if(nr) nr.style.display='flex';
  await loadRooms();
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
}

async function pollMessages(){
  if(!currentRoom) return;
  const d = await api('messages', {room_id: currentRoom.id, after: lastMsgId});
  const msgs = d.messages || [];
  if(!msgs.length) return;

  setEmpty(false);   // есть новые сообщения — убираем заглушку

  let html = '';
  msgs.forEach(m => {
    const showSender = m.sender_id !== _prevSender || m.msg_type === 'system';
    html += renderMsg(m, showSender);
    _prevSender = m.sender_id;
    lastMsgId   = Math.max(lastMsgId, m.id);
  });

  const area = $id('messagesArea');
  if(area) {
    area.insertAdjacentHTML('beforeend', html);
    scrollBottom('smooth');
  }

  markRead(lastMsgId);

  // Тихое обновление unread в сайдбаре
  const r = rooms.find(x => x.id === currentRoom.id);
  if(r) r.unread = 0;
  loadRooms().catch(()=>{});
}

async function pollSignals(){
  const d = await api('poll');
  for(const s of (d.signals||[])) handleSignal(s);
}

async function pollAll(){
  await Promise.all([pollMessages(), pollSignals()]).catch(()=>{});
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
const ICE = {iceServers:[
  {urls:'stun:stun.l.google.com:19302'},
  {urls:'stun:stun1.l.google.com:19302'},
  {urls:'stun:stun.cloudflare.com:3478'},
]};

let pc=null,localStream=null,screenStream=null;
let callId=null,callPeerId=null,callPeerName='',callIsVideo=true,isCaller=false;
let isMuted=false,isCamOff=false,isScreen=false;
let pendingInvite=null;
let callStart=null,callTmrInt=null;

function genCallId(){ return 'c_'+Date.now()+'_'+Math.random().toString(36).slice(2,7); }

function callPeerInRoom(){
  if(!currentRoom) return null;
  // For direct rooms — find the other user from members
  if(currentRoom.type==='direct'){
    const d=$id('membersList').querySelectorAll('.member-row');
    for(const row of d){
      const nm=row.querySelector('.member-nm').textContent.replace(' (Вы)','').trim();
      if(nm!==ME.name){
        const av=ALL_ADMINS.find(a=>a.name===nm);
        return av || null;
      }
    }
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
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    await apiPost('signal',{call_id:callId,to_id:toId,sig_type:'invite',payload:{offer,callerName:ME.name,isVideo:video}});
    showCallWindow(toName,video);
    showToast('Вызов '+toName+'…','<i class="fas fa-phone"></i>',20000);
  } catch(e){ showToast('Ошибка: '+e.message,'<i class="fas fa-times-circle"></i>'); cleanup(); }
}

async function getStream(video){
  try{ return await navigator.mediaDevices.getUserMedia({audio:true,video:video?{width:1280,height:720}:false}); }
  catch(e){ if(video){ showToast('Камера недоступна, аудио','<i class="fas fa-exclamation-triangle"></i>'); return await navigator.mediaDevices.getUserMedia({audio:true,video:false}); } throw e; }
}

function setupPC(){
  pc = new RTCPeerConnection(ICE);
  pc.onicecandidate = e=>{ if(e.candidate) apiPost('signal',{call_id:callId,to_id:callPeerId,sig_type:'ice',payload:e.candidate}); };
  pc.ontrack = e=>{
    $id('vidRemote').srcObject = e.streams[0];
    const hasV = e.streams[0].getVideoTracks().length>0;
    $id('callNoVid').style.display = hasV?'none':'flex';
    $id('vidRemote').style.display = hasV?'block':'none';
  };
  pc.onconnectionstatechange = ()=>{ if(['disconnected','failed','closed'].includes(pc.connectionState)) hangUp(false); };
}

function showCallWindow(name,video){
  callIsVideo=video;
  $id('callPeerNm').textContent = name;
  $id('callPeerAv').textContent = avatarInitial(name);
  $id('callPeerAv').style.background = avatarColor(name);
  $id('vidLocal').srcObject = localStream;
  $id('vidLocal').style.display = video?'block':'none';
  $id('callWin').classList.add('open');
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
    const ans=await pc.createAnswer(); await pc.setLocalDescription(ans);
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
  isScreen=false; if(screenStream){ screenStream.getTracks().forEach(t=>t.stop()); screenStream=null; }
  if(localStream&&pc){ const ct=localStream.getVideoTracks()[0]; if(ct){ const s=pc.getSenders().find(s=>s.track?.kind==='video'); if(s) await s.replaceTrack(ct); } $id('vidLocal').srcObject=localStream; }
  updateCCUI();
}

async function hangUp(send=true){
  if(send&&callPeerId) await apiPost('signal',{call_id:callId,to_id:callPeerId,sig_type:'hangup'}).catch(()=>{});
  cleanup(); $id('callWin').classList.remove('open'); clearInterval(callTmrInt); $id('callTmr').textContent='0:00';
  showToast('Звонок завершён','<i class="fas fa-phone-slash"></i>');
}

function cleanup(){
  stopRing();
  if(screenStream){screenStream.getTracks().forEach(t=>t.stop());screenStream=null;}
  if(localStream) {localStream.getTracks().forEach(t=>t.stop()); localStream=null;}
  if(pc){pc.close();pc=null;}
  $id('vidRemote').srcObject=null; $id('vidLocal').srcObject=null;
  callId=callPeerId=callPeerName=null; isMuted=isCamOff=isScreen=isCaller=false; pendingInvite=null;
}

async function handleSignal(sig){
  switch(sig.sig_type){
    case 'invite':
      if(pc){ apiPost('signal',{call_id:sig.call_id,to_id:sig.from_id,sig_type:'busy'}); }
      else showIncoming(sig);
      break;
    case 'answer':
      if(pc&&isCaller) await pc.setRemoteDescription(new RTCSessionDescription(sig.payload));
      break;
    case 'ice':
      if(pc&&sig.payload) try{await pc.addIceCandidate(new RTCIceCandidate(sig.payload));}catch(_){}
      break;
    case 'reject': hangUp(false); showToast(sig.from_name+' отклонил(а) звонок','<i class="fas fa-phone-slash"></i>'); break;
    case 'hangup': hangUp(false); break;
    case 'busy':   hangUp(false); showToast(sig.from_name+' занят(а)','<i class="fas fa-circle" style="color:#e53935"></i>'); break;
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
  $id('sidebar')?.classList.add('mob-open');
  const cv=$id('chatView'); if(cv) cv.style.display='none';
  const nr=$id('noRoom');   if(nr) nr.style.display='flex';
}

/* ════════════════════════════════════════════════════
   INIT & POLLING LOOP
════════════════════════════════════════════════════ */
async function init(){
  try {
    await pingPresence();
    await loadRooms();

    // Открываем «Общий» канал по умолчанию
    // ID может придти как number — ищем и числом, и сравниванием
    const general = rooms.find(r => r.id === 1 || r.id === '1');
    if (general) await openRoom(general.id);
  } catch(e) {
    console.error('[chat] init error:', e);
  }

  // Опрос каждые 2 сек (сообщения + сигналы)
  setInterval(() => pollAll().catch(()=>{}), 2000);
  // Обновление присутствия и списка комнат каждые 10 сек
  setInterval(async () => {
    try { await pingPresence(); await loadRooms(); } catch(_) {}
  }, 10000);
}

// Запускаем после полной загрузки DOM
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
</script>
</body>
</html>
