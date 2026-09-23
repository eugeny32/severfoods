"""Публикует remote.ntrip.host: сначала только :80 (ACME-редирект/challenge), выпускает
сертификат certbot --webroot, затем добавляет блок :8443 (за HAProxy, как у остальных
сайтов на этом сервере) и перезагружает nginx. Требует, чтобы DNS-запись уже резолвилась
на этот сервер (иначе certbot не пройдёт HTTP-01 challenge). Не трогает HAProxy — он и так
пересылает весь трафик, кроме SNI=turn.ntrip.host, на nginx:8443 по умолчанию."""
import os
import sys
import paramiko

HOST, PORT = "192.168.1.44", 2222
USER, PW = os.environ["RS_USER"], os.environ["RS_PASS"]
DOMAIN = "remote.ntrip.host"

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, port=PORT, username=USER, password=PW, timeout=15, allow_agent=False, look_for_keys=False)


def run(cmd, as_root=False, timeout=60, check=True):
    if as_root:
        cmd = "echo %s | sudo -S -p '' bash -c %s" % (
            __import__("shlex").quote(PW), __import__("shlex").quote(cmd))
    _, stdout, stderr = c.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode()
    err = stderr.read().decode()
    rc = stdout.channel.recv_exit_status()
    print("$", cmd[:140].replace("\n", " "))
    if out.strip():
        print(out)
    if err.strip():
        print(err, file=sys.stderr)
    if check and rc != 0:
        print("!! rc=%d" % rc)
        sys.exit(1)
    return rc, out, err


STAGE1 = """server {
    listen 80;
    listen [::]:80;
    server_name %s;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}
""" % DOMAIN

sftp = c.open_sftp()
with sftp.file("/tmp/remote-support-nginx-stage1.conf", "w") as f:
    f.write(STAGE1)
with sftp.file("/tmp/remote-support-nginx-full.conf", "w") as f:
    f.write(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "remote-support-nginx.conf"), encoding="utf-8").read())
sftp.close()

# ── этап 1: только :80, чтобы certbot мог пройти challenge ──
run("install -m 644 -o root -g root /tmp/remote-support-nginx-stage1.conf /etc/nginx/sites-available/remote.ntrip.host.conf && "
    "ln -sf /etc/nginx/sites-available/remote.ntrip.host.conf /etc/nginx/sites-enabled/remote.ntrip.host.conf && "
    "nginx -t && systemctl reload nginx", as_root=True)

run("certbot certonly --webroot -w /var/www/certbot -d %s --non-interactive --agree-tos "
    "-m admin@ntrip.host --no-eff-email" % DOMAIN, as_root=True, timeout=90)

# ── этап 2: полный конфиг с :8443 (сертификат уже есть) ──
run("install -m 644 -o root -g root /tmp/remote-support-nginx-full.conf /etc/nginx/sites-available/remote.ntrip.host.conf && "
    "rm -f /tmp/remote-support-nginx-stage1.conf /tmp/remote-support-nginx-full.conf && "
    "nginx -t && systemctl reload nginx", as_root=True)

run("curl -s -o /dev/null -w '%%{http_code}\\n' --resolve %s:443:127.0.0.1 https://%s/admin.html -k" % (DOMAIN, DOMAIN), timeout=20, check=False)
run("systemctl --failed --no-legend")
c.close()
print("\nDONE. https://%s/admin.html should now work from the public internet." % DOMAIN)
