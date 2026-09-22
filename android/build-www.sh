#!/usr/bin/env bash
#
# Сборка веб-части Android-приложения.
#
# Интерфейс НЕ дублируется: он берётся из offline/public — того же самого,
# что работает на Windows. Копия создаётся при каждой сборке, поэтому правка
# интерфейса автоматически попадает в обе версии и они не расходятся.
#
# Отличия Android-сборки от Windows-версии вносятся только здесь:
#   - подключается браузерное ядро (core/) вместо Node.js-сервера;
#   - app.js подключает не тег <script>, а boot.js — после инициализации базы;
#   - вьюпорт запрещает зум (случайный «щипок» на планшете нечем отменить).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/offline/public"
AND="$ROOT/android"
WWW="$AND/www"

VERSION="$(node -p "require('$AND/package.json').version")"
BUILD_DATE="$(date +%d.%m.%Y)"

echo "→ Сборка www для версии $VERSION"

rm -rf "$WWW"
mkdir -p "$WWW"

# 1. Общий интерфейс
cp -r "$SRC/." "$WWW/"

# 2. Браузерное ядро и его ассеты
mkdir -p "$WWW/core"
cp "$AND/core/"*.js "$WWW/core/"
cp "$AND/assets/setup.css" "$WWW/assets/"
cp "$AND/assets/vendor/sql-wasm.js" "$AND/assets/vendor/sql-wasm.wasm" "$WWW/assets/vendor/"

# 3. Правка index.html
python3 - "$WWW/index.html" "$VERSION" "$BUILD_DATE" <<'PY'
import sys, re

path, version, build_date = sys.argv[1], sys.argv[2], sys.argv[3]
html = open(path, encoding='utf-8').read()

# Зум на планшете отключаем: случайный «щипок» двумя пальцами оператору нечем
# отменить, а физической клавиатуры для сброса масштаба нет.
html = html.replace(
    '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
    '<meta name="viewport" content="width=device-width, initial-scale=1.0, '
    'maximum-scale=1.0, user-scalable=no, viewport-fit=cover">')

html = html.replace(
    '<link rel="stylesheet" href="assets/app.css">',
    '<link rel="stylesheet" href="assets/app.css">\n'
    '<link rel="stylesheet" href="assets/setup.css">')

# app.js подключает boot.js — уже после того, как база открыта и переходник
# /api/ встал на место. Прямой тег <script> здесь сломал бы порядок запуска.
core = f'''<script>window.SF_APP_VERSION = "{version}"; window.SF_BUILD_DATE = "{build_date}";</script>
<script src="assets/vendor/sql-wasm.js"></script>
<script src="core/storage.js"></script>
<script src="core/db.js"></script>
<script src="core/tz.js"></script>
<script src="core/net-bridge.js"></script>
<script src="core/settings.js"></script>
<script src="core/update.js"></script>
<script src="core/sync.js"></script>
<script src="core/api.js"></script>
<script src="core/status.js"></script>
<script src="core/evotor.js"></script>
<script src="core/boot.js"></script>'''

if '<script src="assets/app.js"></script>' not in html:
    sys.exit('index.html: не найден тег подключения app.js — сборка остановлена')
html = html.replace('<script src="assets/app.js"></script>', core)

open(path, 'w', encoding='utf-8').write(html)
print('  index.html: ядро подключено, зум отключён')
PY

# 4. Версия для Gradle. versionCode обязан расти при каждом выпуске, иначе
#    Android откажется ставить обновление поверх: составляем его из номера
#    версии (1.7.1 → 10701), это монотонно и читаемо.
IFS=. read -r MAJ MIN PAT <<<"$VERSION"
VCODE=$(( MAJ * 10000 + MIN * 100 + PAT ))
cat > "$AND/version.properties" <<EOF
versionName=$VERSION
versionCode=$VCODE
EOF
echo "  версия $VERSION (versionCode $VCODE)"

echo "✓ Готово: $WWW"
