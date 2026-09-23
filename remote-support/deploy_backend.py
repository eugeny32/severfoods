"""Разворачивает бэкенд «Удалённой поддержки» (remote-support/backend) на ntrip.host.
Тянет код прямо с GitHub (sparse checkout, как описано в remote-support/README.md), не трогает
никакие другие службы сервера. Требует переменные окружения:
  RS_USER, RS_PASS       — SSH-доступ к серверу (порт 2222, sudo тем же паролем)
  ADMIN_TOKEN            — общий секрет для admin.html (генерируется один раз, хранится только в .env на сервере)
  TURN_SHARED_SECRET     — static-auth-secret из /etc/turnserver.conf на сервере
Порт TURN на этом сервере нестандартный (50000/5349, не 3478) — см. TURN_PORT/TURN_TLS_PORT ниже,
подобраны под актуальный /etc/turnserver.conf (see remote-support/backend/turn.js)."""
import os
import sys
import paramiko

HOST, PORT = "192.168.1.44", 2222
USER, PW = os.environ["RS_USER"], os.environ["RS_PASS"]
ADMIN_TOKEN = os.environ["ADMIN_TOKEN"]
TURN_SHARED_SECRET = os.environ["TURN_SHARED_SECRET"]
TURN_PORT = os.environ.get("TURN_PORT", "50000")
TURN_TLS_PORT = os.environ.get("TURN_TLS_PORT", "5349")
BACKEND_PORT = os.environ.get("BACKEND_PORT", "8787")
REPO = "https://github.com/eugeny32/severfoods.git"

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, port=PORT, username=USER, password=PW, timeout=15, allow_agent=False, look_for_keys=False)


def run(cmd, as_root=False, timeout=120):
    if as_root:
        cmd = "echo %s | sudo -S -p '' bash -c %s" % (
            __import__("shlex").quote(PW), __import__("shlex").quote(cmd))
    _, stdout, stderr = c.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode()
    err = stderr.read().decode()
    rc = stdout.channel.recv_exit_status()
    print("$", cmd[:120].replace("\n", " "))
    if out.strip():
        print(out)
    if err.strip():
        print(err, file=sys.stderr)
    if rc != 0:
        print("!! rc=%d" % rc)
        sys.exit(1)
    return out


# ── системный пользователь, без sudo, без домашней папки ──
run("id remotesupport >/dev/null 2>&1 || useradd --system --user-group --no-create-home --shell /usr/sbin/nologin remotesupport", as_root=True)

# ── код: git sparse-checkout прямо с GitHub, обновляемо повторным запуском ──
clone_script = """
set -e
if [ ! -d /opt/remote-support-src/.git ]; then
    git clone --filter=blob:none --sparse %s /opt/remote-support-src
fi
cd /opt/remote-support-src
git sparse-checkout set remote-support/backend
git fetch origin main
git checkout main
git reset --hard origin/main
""" % REPO
run(clone_script, as_root=True, timeout=60)

run("cd /opt/remote-support-src/remote-support/backend && npm install --omit=dev", as_root=True, timeout=120)

env_content = (
    "ADMIN_TOKEN=%s\n"
    "TURN_HOST=ntrip.host\n"
    "TURN_SHARED_SECRET=%s\n"
    "TURN_PORT=%s\n"
    "TURN_TLS_PORT=%s\n"
    "PORT=%s\n"
) % (ADMIN_TOKEN, TURN_SHARED_SECRET, TURN_PORT, TURN_TLS_PORT, BACKEND_PORT)
tmp_env = "/tmp/remote-support.env.upload"
sftp = c.open_sftp()
with sftp.file(tmp_env, "w") as f:
    f.write(env_content)
sftp.chmod(tmp_env, 0o600)
sftp.close()
run("install -d -m 750 -o root -g remotesupport /etc/remote-support && "
    "install -m 640 -o root -g remotesupport %s /etc/remote-support/backend.env && rm -f %s" % (tmp_env, tmp_env),
    as_root=True)

unit = """[Unit]
Description=Remote Support: сигналинг-сервер (просмотр/управление Эвотор-терминалами)
After=network.target coturn.service

[Service]
User=remotesupport
Group=remotesupport
WorkingDirectory=/opt/remote-support-src/remote-support/backend
EnvironmentFile=/etc/remote-support/backend.env
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/node server.js
Restart=on-failure
RestartSec=5
Nice=5
MemoryMax=256M
TasksMax=128
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
LockPersonality=yes

[Install]
WantedBy=multi-user.target
"""
tmp_unit = "/tmp/remote-support.service.upload"
sftp = c.open_sftp()
with sftp.file(tmp_unit, "w") as f:
    f.write(unit)
sftp.close()
run("install -m 644 -o root -g root %s /etc/systemd/system/remote-support.service && rm -f %s" % (tmp_unit, tmp_unit), as_root=True)
run("systemctl daemon-reload && systemctl enable remote-support && systemctl restart remote-support", as_root=True)
run("sleep 1 && systemctl is-active remote-support")
run("curl -s -o /dev/null -w '%%{http_code}\\n' http://127.0.0.1:%s/admin.html" % BACKEND_PORT)
run("curl -s http://127.0.0.1:%s/api/devices -H 'X-Admin-Token: %s'" % (BACKEND_PORT, ADMIN_TOKEN))

c.close()
print("\nDONE. Backend listening on 127.0.0.1:%s (not yet exposed via nginx)." % BACKEND_PORT)
