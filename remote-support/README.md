# Удалённая поддержка

Отдельный продукт для просмотра экрана и удалённого управления любыми
смарт-терминалами Эвотор — не привязан к СеверФудс и не ограничен точками
питания. Живёт в этом репозитории только потому, что это единственный
репозиторий, куда есть доступ на запись; логически это самостоятельное
приложение со своим бэкендом, деплоящимся отдельно на `ntrip.host`.

## Как это устроено

Прямого входящего соединения к терминалу нет и не требуется: терминал сам
периодически стучится на сервер («я жива» + забираю очередь команд), а сама
передача видео и управления идёт напрямую между браузером администратора и
терминалом через coturn (TURN/STUN на том же `ntrip.host`) — сервер здесь
пересылает только служебные сообщения WebRTC (offer/answer/ice), и то
недолго, на время установления соединения.

```
Терминал (Android)              ntrip.host                Браузер администратора
  HeartbeatService  ── poll ──▶  backend/server.js  ◀── poll ──  admin.html / viewer.html
  RemoteScreenService ───────────────────── coturn (TURN/STUN) ─────────────────────
                                  (видео и управление идут напрямую, в обход сервера)
```

## Части

- **`backend/`** — Node.js сигналинг-сервер (Express, без внешней БД —
  простой JSON-файл, потому что на `ntrip.host` кроме coturn ничего не
  поднято). Отдаёт также статические страницы `admin.html` (список
  терминалов, запуск сеанса) и `viewer.html` (видео + управление + буфер
  обмена).
- **`android/`** — отдельный Android-проект (пакет `ru.remotesupport.evotor`,
  не связан с проектом `android/android` СеверФудс). Минимальный интерфейс:
  адрес сервера, кнопка регистрации, кнопка включения захвата экрана, кнопка
  открытия специальных возможностей.

## Разворачивание бэкенда на ntrip.host

На сервере сейчас есть только coturn — веб-сервиса ещё нет, разворачивать
нужно вручную (у этой среды разработки нет SSH-доступа к серверу):

```bash
# на ntrip.host
cd /opt && git clone --filter=blob:none --sparse https://github.com/eugeny32/severfoods.git remote-support-src
cd remote-support-src && git sparse-checkout set remote-support/backend
cd remote-support/backend
npm install --production

cat > .env <<'EOF'
ADMIN_TOKEN=<придумайте длинный случайный токен — им откроется admin.html>
TURN_HOST=ntrip.host
TURN_SHARED_SECRET=<тот же static-auth-secret, что в конфиге coturn>
PORT=8787
EOF

# systemd-юнит (пример)
sudo tee /etc/systemd/system/remote-support.service <<'EOF'
[Unit]
Description=Remote Support signaling server
After=network.target

[Service]
WorkingDirectory=/opt/remote-support-src/remote-support/backend
EnvironmentFile=/opt/remote-support-src/remote-support/backend/.env
ExecStart=/usr/bin/node server.js
Restart=on-failure
User=www-data

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now remote-support

# обратный прокси (nginx, пример) — порт 8787 наружу лучше не светить голым
# и сразу отдавать по HTTPS: WebRTC / getUserMedia-подобные API браузер
# разрешает только с защищённых страниц.
```

Обновление — `git -C /opt/remote-support-src pull && systemctl restart remote-support`.

## Сборка Android-приложения

CI: workflow **Build Remote Support APK** (`.github/workflows/build-remote-support.yml`),
собирает debug-APK (ключа боевой подписи в репозитории нет) в
`remote-support/dist/`.

Локально: `cd remote-support/android && ./gradlew assembleDebug`.

## Первый запуск на терминале

1. Установите APK на терминал (через ADB — терминал ещё не публикуется в
   Эвотор.Маркете, см. `android/EVOTOR.md` в основном проекте про два пути
   установки).
2. Откройте приложение → укажите адрес сервера (`https://ваш-домен-на-ntrip.host`)
   → «Сохранить адрес сервера» — приложение зарегистрируется само и появится
   в списке устройств на `admin.html`.
3. На самом терминале нажмите «Включить удалённый доступ» → подтвердите
   системный диалог захвата экрана (один раз, дальше не потребуется, пока
   жив процесс).
4. Там же — «Разрешить управление» → включите приложение в списке
   специальных возможностей Android вручную (программно это не обходится).
5. Откройте `admin.html`, введите `ADMIN_TOKEN`, найдите терминал в списке,
   нажмите «Экран».

Не проверено на живом терминале — устройства в среде разработки нет; первая
компиляция подтверждена сборкой CI (см. коммиты), а не реальным
использованием.
