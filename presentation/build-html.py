#!/usr/bin/env python3
"""
Веб-версия презентации: одна самодостаточная HTML-страница.

Собирается из того же содержания, что и .pptx, но живёт по правилам страницы:
листается стрелками и колесом, печатается в PDF (Ctrl+P), открывается с
телефона. Картинки вшиты в файл — его можно просто положить на сайт или
отправить письмом, ничего рядом не нужно.

Запуск: python3 build-html.py
На выходе:
  index.html        — самодостаточная страница
  web/artifact.html — та же страница без обёртки <html>, для публикации
"""
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
IMG = json.loads((HERE / 'web' / 'images.json').read_text())

TITLE = 'СеверФудс'

STYLE = r"""
<style>
/* ── Токены ─────────────────────────────────────────────────
   Палитра продукта: тёмно-синий и бирюзовый из логотипа «Север».
   Нейтральные — с синим уклоном, чтобы серый не выглядел случайным. */
:root {
  --ink:        #0E1B2A;
  --ink-soft:   #53657C;
  --paper:      #FBFCFD;
  --surface:    #FFFFFF;
  --tint:       #EEF3F8;
  --tint-warm:  #E4F4F0;
  --line:       #D9E2EC;
  --brand:      #003366;
  --brand-lift: #10529A;
  --accent:     #00B49B;
  --accent-ink: #00705F;
  --amber:      #C67C06;
  --red:        #C33A32;
  --deep:       #02203F;   /* тёмные полосы — одинаковы в обеих темах */
  --deep-card:  #0A3059;
  --deep-line:  #17456F;
  --deep-text:  #CFE0F0;
  --shadow:     0 1px 2px rgba(14,27,42,.06), 0 8px 24px rgba(14,27,42,.07);
  --radius:     14px;
  --gutter:     clamp(18px, 4vw, 64px);
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --ink:       #E8EEF5;
    --ink-soft:  #9FB2C6;
    --paper:     #0A1420;
    --surface:   #111E2E;
    --tint:      #16283C;
    --tint-warm: #113A35;
    --line:      #25394F;
    --brand:     #7FB2E8;
    --brand-lift:#A8CDF5;
    --accent:    #29D3B8;
    --accent-ink:#29D3B8;
    --amber:     #E8A33A;
    --red:       #E4665E;
    --shadow:    0 1px 2px rgba(0,0,0,.4), 0 8px 24px rgba(0,0,0,.35);
  }
}
:root[data-theme="dark"] {
  --ink:       #E8EEF5;
  --ink-soft:  #9FB2C6;
  --paper:     #0A1420;
  --surface:   #111E2E;
  --tint:      #16283C;
  --tint-warm: #113A35;
  --line:      #25394F;
  --brand:     #7FB2E8;
  --brand-lift:#A8CDF5;
  --accent:    #29D3B8;
  --accent-ink:#29D3B8;
  --amber:     #E8A33A;
  --red:       #E4665E;
  --shadow:    0 1px 2px rgba(0,0,0,.4), 0 8px 24px rgba(0,0,0,.35);
}

* { box-sizing: border-box; }
body {
  margin: 0;
  background: var(--paper);
  color: var(--ink);
  font-family: 'Onest', system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-size: 16px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
img { max-width: 100%; display: block; }

/* ── Каркас листа ───────────────────────────────────────── */
.deck { scroll-snap-type: y proximity; }
.s {
  scroll-snap-align: start;
  padding: clamp(40px, 7vh, 84px) var(--gutter) clamp(44px, 8vh, 90px);
  border-bottom: 1px solid var(--line);
  position: relative;
}
.s > .inner { max-width: 1240px; margin: 0 auto; }
.s--ink { background: var(--deep); color: #fff; border-bottom-color: var(--deep-line); }
.s--ink .sub, .s--ink .note { color: #93B4D3; }
.s--ink h2 { color: #fff; }

.eyebrow {
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 12px; letter-spacing: .14em; text-transform: uppercase;
  color: var(--accent-ink); margin: 0 0 14px;
}
.s--ink .eyebrow { color: var(--accent); }
h1 {
  font-size: clamp(38px, 6.4vw, 76px); line-height: 1.02; margin: 0 0 18px;
  font-weight: 800; letter-spacing: -.025em; text-wrap: balance;
}
h2 {
  font-size: clamp(25px, 3.4vw, 40px); line-height: 1.12; margin: 0 0 10px;
  font-weight: 700; letter-spacing: -.02em; color: var(--brand); text-wrap: balance;
}
.sub { font-size: clamp(15px, 1.5vw, 18px); color: var(--ink-soft); margin: 0 0 28px; max-width: 78ch; }
p  { margin: 0 0 12px; }
.note { font-size: 14px; color: var(--ink-soft); margin-top: 20px; }

/* ── Титул ──────────────────────────────────────────────── */
.hero { display: grid; grid-template-columns: 1.05fr .95fr; gap: clamp(24px, 4vw, 56px); align-items: center; }
.hero-logo { width: 210px; margin-bottom: 30px; }
.hero p.lead { font-size: clamp(17px, 1.9vw, 21px); color: var(--deep-text); max-width: 46ch; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 26px 0 30px; }
.chip {
  font-family: 'IBM Plex Mono', monospace; font-size: 12.5px; letter-spacing: .04em;
  padding: 7px 13px; border-radius: 999px;
  background: rgba(0,180,155,.16); color: #58E8CE; border: 1px solid rgba(0,180,155,.4);
}
.maker { border-top: 1px solid var(--deep-line); padding-top: 18px; margin-top: 6px; }
.maker strong { font-size: 17px; }
.maker span { display: block; color: #7FA2C4; font-size: 13.5px; margin-top: 4px;
              font-family: 'IBM Plex Mono', monospace; }
.hero-shots { display: grid; gap: 14px; }

/* ── Сетки карточек ─────────────────────────────────────── */
.grid { display: grid; gap: 16px; }
.g2 { grid-template-columns: repeat(2, 1fr); }
.g3 { grid-template-columns: repeat(3, 1fr); }
.g4 { grid-template-columns: repeat(4, 1fr); }
.card {
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
  padding: 20px 20px 22px; box-shadow: var(--shadow);
}
.card h3 { margin: 0 0 8px; font-size: 17px; font-weight: 700; color: var(--brand); }
.card p  { margin: 0; font-size: 14.5px; color: var(--ink); }
.card--accent { background: var(--tint-warm); border-color: color-mix(in srgb, var(--accent) 30%, var(--line)); }
.s--ink .card { background: var(--deep-card); border-color: var(--deep-line); box-shadow: none; }
.s--ink .card h3 { color: var(--accent); }
.s--ink .card p  { color: var(--deep-text); }

.num {
  font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--accent-ink);
  display: block; margin-bottom: 10px; letter-spacing: .1em;
}
.s--ink .num { color: var(--accent); }

/* ── Список шагов ───────────────────────────────────────── */
.steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; }
.steps li { display: grid; grid-template-columns: 34px 1fr; gap: 14px; align-items: start; }
.steps b {
  width: 34px; height: 34px; border-radius: 50%; background: var(--brand); color: #fff;
  display: grid; place-items: center; font-size: 14px; font-family: 'IBM Plex Mono', monospace;
}
.steps strong { display: block; font-size: 16px; margin-bottom: 3px; }
.steps span   { font-size: 14.5px; color: var(--ink-soft); }

/* ── Поток «карта → отчёт» ──────────────────────────────── */
.flow { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; align-items: stretch; }
.flow .card { text-align: center; padding: 18px 12px; }
.flow .card h3 { font-size: 15.5px; }
.flow .card p  { font-size: 13px; color: var(--ink-soft); }

/* ── Снимки экрана ──────────────────────────────────────── */
figure { margin: 0; }
figure img { border-radius: 12px; border: 1px solid var(--line); box-shadow: var(--shadow); }
figcaption { font-size: 13px; color: var(--ink-soft); margin-top: 10px; }
.split { display: grid; grid-template-columns: 1.15fr .85fr; gap: clamp(20px, 3vw, 40px); align-items: start; }
.split--rev { grid-template-columns: .85fr 1.15fr; }

/* ── Статусы терминала — мотив всей страницы ────────────── */
.statuses { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 18px; }
.status { display: flex; gap: 11px; align-items: flex-start; }
.dot { width: 13px; height: 13px; border-radius: 50%; margin-top: 5px; flex: none; }
.dot--ok  { background: var(--accent); }
.dot--dup { background: var(--amber); }
.dot--no  { background: var(--red); }
.status strong { display: block; font-size: 15px; }
.status span   { font-size: 14px; color: var(--ink-soft); }

/* ── Таблица платформ ───────────────────────────────────── */
.plat { display: grid; gap: 12px; }
.plat > div {
  display: grid; grid-template-columns: 300px 1fr; gap: 20px; align-items: baseline;
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
  padding: 18px 22px; box-shadow: var(--shadow);
}
.plat h3 { margin: 0 0 3px; font-size: 16.5px; color: var(--brand); }
.plat small { font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--ink-soft); }
.plat p { margin: 0; font-size: 14.5px; }

/* ── Расписание ─────────────────────────────────────────── */
.meals { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 26px; }
.meal { background: var(--tint); border-radius: var(--radius); padding: 18px 20px; }
.meal--night { background: var(--tint-warm); }
.meal b { display: block; font-size: 20px; font-weight: 700; color: var(--brand); }
.meal span { font-family: 'IBM Plex Mono', monospace; font-size: 13.5px; color: var(--ink-soft); }

/* ── Список внутри карточки ─────────────────────────────── */
.card ul { margin: 10px 0 0; padding-left: 18px; }
.card li { font-size: 14px; margin-bottom: 5px; }

/* ── Навигация ──────────────────────────────────────────── */
.rail {
  position: fixed; right: 14px; top: 50%; transform: translateY(-50%);
  display: grid; gap: 7px; z-index: 20;
}
.rail a { width: 8px; height: 8px; border-radius: 50%; background: var(--line); display: block; }
.rail a[aria-current="true"] { background: var(--accent); transform: scale(1.4); }
.progress {
  position: fixed; left: 0; top: 0; height: 3px; background: var(--accent);
  width: 0; z-index: 30; transition: width .15s linear;
}
.hint {
  position: fixed; left: 50%; bottom: calc(14px + env(safe-area-inset-bottom, 0px));
  transform: translateX(-50%); z-index: 20;
  font-family: 'IBM Plex Mono', monospace; font-size: 11.5px; letter-spacing: .06em;
  background: var(--surface); color: var(--ink-soft);
  border: 1px solid var(--line); border-radius: 999px; padding: 7px 14px; box-shadow: var(--shadow);
}

/* ── Узкий экран ────────────────────────────────────────── */
@media (max-width: 900px) {
  .hero, .split, .split--rev { grid-template-columns: 1fr; }
  .g4 { grid-template-columns: repeat(2, 1fr); }
  .g3 { grid-template-columns: 1fr; }
  .flow { grid-template-columns: repeat(2, 1fr); }
  .meals { grid-template-columns: repeat(2, 1fr); }
  .statuses { grid-template-columns: 1fr; }
  .plat > div { grid-template-columns: 1fr; gap: 8px; }
  .rail, .hint { display: none; }
}
@media (max-width: 520px) {
  .g4, .g2 { grid-template-columns: 1fr; }
  .flow { grid-template-columns: 1fr; }
}

@media (prefers-reduced-motion: reduce) {
  * { scroll-behavior: auto !important; transition: none !important; }
}

/* ── Печать: каждый раздел — страница ───────────────────── */
@media print {
  @page { size: A4 landscape; margin: 9mm; }
  .rail, .hint, .progress { display: none !important; }
  body { background: #fff; color: #10203a; font-size: 11pt; }
  .s { break-after: page; border: none; padding: 0 0 6mm; }
  .s--ink { background: #02203F !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;
            padding: 8mm; border-radius: 4mm; }
  .card, .plat > div { box-shadow: none; break-inside: avoid; }
  h1 { font-size: 30pt; } h2 { font-size: 19pt; }
}
</style>
"""

BODY = r"""
<div class="progress" id="progress"></div>
<nav class="rail" id="rail" aria-label="Разделы"></nav>
<div class="hint">← → листать · Ctrl+P — PDF</div>

<main class="deck" id="deck">

<section class="s s--ink" id="s1">
  <div class="inner hero">
    <div>
      <img class="hero-logo" src="{{logo}}" alt="Логотип «Север»">
      <h1>СеверФудс</h1>
      <p class="lead">Учёт питания сотрудников на производственных площадках: карта с QR-кодом, терминал на раздаче и отчётность, по которой выставляют счёт подрядчику.</p>
      <div class="chips">
        <span class="chip">Windows</span><span class="chip">Android</span>
        <span class="chip">Эвотор</span><span class="chip">Веб-интерфейс</span>
      </div>
      <div class="maker">
        <strong>Производитель ПО — ООО «Север»</strong>
        <span>версия 1.7.7 · сентябрь 2026</span>
      </div>
    </div>
    <div class="hero-shots">
      <img src="{{result}}" alt="Экран терминала: проход засчитан">
      <img src="{{logs}}" alt="Журнал питания за день">
    </div>
  </div>
</section>

<section class="s" id="s2">
  <div class="inner">
    <p class="eyebrow">Что меняем</p>
    <h2>Как учитывают питание сегодня</h2>
    <p class="sub">Бумажная ведомость на раздаче — и всё, что из неё следует.</p>
    <div class="grid g4">
      <div class="card"><span class="num">01</span><h3>Очередь на раздаче</h3><p>Оператор ищет фамилию среди сотен. В пиковый час — десятки секунд на человека.</p></div>
      <div class="card"><span class="num">02</span><h3>Двойное питание</h3><p>По бумаге не проверить, получал ли человек этот обед. Тем более — на соседней точке.</p></div>
      <div class="card"><span class="num">03</span><h3>Споры с подрядчиками</h3><p>Счёт выставляется по рукописным отметкам. Оспорить их нечем.</p></div>
      <div class="card"><span class="num">04</span><h3>Ручной пересчёт</h3><p>Сведение ведомостей в таблицу занимает дни и само по себе источник ошибок.</p></div>
    </div>
    <p class="note">Система заменяет ведомость картой с QR-кодом и терминалом на раздаче.</p>
  </div>
</section>

<section class="s" id="s3">
  <div class="inner">
    <p class="eyebrow">Как устроено</p>
    <h2>Путь одного прохода</h2>
    <p class="sub">От карты сотрудника до строки в отчёте.</p>
    <div class="flow">
      <div class="card"><h3>Карта</h3><p>QR-пропуск у сотрудника</p></div>
      <div class="card"><h3>Терминал</h3><p>Проверка и решение</p></div>
      <div class="card"><h3>Локальная база</h3><p>Запись на устройстве</p></div>
      <div class="card"><h3>Сервер</h3><p>Синхронизация при связи</p></div>
      <div class="card card--accent"><h3>Отчёты</h3><p>Excel и веб-интерфейс</p></div>
    </div>
    <p class="note"><strong>Первые три шага не требуют интернета.</strong> Справочник сотрудников и записи о проходах лежат на самом устройстве: раздача идёт при выключенной связи, а накопленные записи уходят сами, когда связь появится.</p>
  </div>
</section>

<section class="s" id="s4">
  <div class="inner">
    <p class="eyebrow">Состав</p>
    <h2>Из чего состоит система</h2>
    <p class="sub">Три части, которые ставятся независимо друг от друга.</p>
    <div class="grid g3">
      <div class="card card--accent"><h3>Точка раздачи</h3><p>Сканирование карт, локальная база и журнал за день. Мультиплатформенная часть — три варианта устройств:</p>
        <ul><li>смарт-терминал Эвотор 7.3</li><li>планшет на Android</li><li>компьютер на Windows</li></ul></div>
      <div class="card"><h3>Сервер площадки</h3><p>Веб-интерфейс и база данных: сотрудники, организации, точки, расписание, отчёты, печать карт.</p>
        <ul><li>PHP и MySQL на обычном хостинге</li><li>работа в браузере, без установки</li><li>выгрузки в Excel</li></ul></div>
      <div class="card"><h3>Центр управления</h3><p>Отдельная панель для нескольких площадок: сводные отчёты по всем регионам сразу.</p>
        <ul><li>реестр площадок</li><li>сводные и выборочные отчёты</li><li>наблюдение за точками</li></ul></div>
    </div>
    <p class="note">Одна площадка работает без центра управления. Центр нужен, когда площадок несколько.</p>
  </div>
</section>

<section class="s" id="s5">
  <div class="inner">
    <p class="eyebrow">На раздаче</p>
    <h2>Что делает оператор</h2>
    <div class="split">
      <ol class="steps">
        <li><b>1</b><div><strong>Вход по своей карте</strong><span>Терминал знает, кто работает на раздаче, — это попадает в журнал.</span></div></li>
        <li><b>2</b><div><strong>Сотрудник подносит карту</strong><span>Сканирование включено постоянно: наводить курсор и нажимать ничего не нужно.</span></div></li>
        <li><b>3</b><div><strong>Система проверяет право</strong><span>Есть ли человек в справочнике, активна ли карта, какой сейчас приём пищи, не получал ли он его уже.</span></div></li>
        <li><b>4</b><div><strong>Крупная карточка с решением</strong><span>ФИО, организация и результат видны с расстояния — оператор не вчитывается в мелкий текст.</span></div></li>
        <li><b>5</b><div><strong>Запись сохраняется</strong><span>Сразу в локальную базу, затем на сервер. В журнале видно, что уже отправлено.</span></div></li>
      </ol>
      <figure>
        <img src="{{result}}" alt="Карточка сотрудника с результатом прохода">
        <figcaption>Проход занимает секунду-две: проверка идёт по локальной базе, а при наличии связи добавляется запрос к серверу — не получал ли человек этот приём пищи на другой точке. Данные на снимке демонстрационные.</figcaption>
      </figure>
    </div>
  </div>
</section>

<section class="s" id="s6">
  <div class="inner">
    <p class="eyebrow">Обратная связь</p>
    <h2>Три ответа терминала</h2>
    <p class="sub">Решение видно с расстояния — цветом, а не текстом.</p>
    <div class="grid g2">
      <figure><img src="{{result}}" alt="Зелёная карточка: проход засчитан"></figure>
      <figure><img src="{{duplicate}}" alt="Жёлтая карточка: повторный проход"></figure>
    </div>
    <div class="statuses">
      <div class="status"><span class="dot dot--ok"></span><div><strong>Зелёный — проход засчитан</strong><span>Видно ФИО, организацию и какой приём пищи зафиксирован.</span></div></div>
      <div class="status"><span class="dot dot--dup"></span><div><strong>Жёлтый — повторный проход</strong><span>Этот приём пищи человек уже получил сегодня; показывается время.</span></div></div>
      <div class="status"><span class="dot dot--no"></span><div><strong>Красный — отказ</strong><span>Карта не найдена, просрочена или заблокирована.</span></div></div>
    </div>
  </div>
</section>

<section class="s s--ink" id="s7">
  <div class="inner">
    <p class="eyebrow">Автономность</p>
    <h2>Столовая не останавливается из-за связи</h2>
    <p class="sub">На вахте и стройке интернет пропадает. Это не должно останавливать раздачу.</p>
    <div class="grid g3">
      <div class="card"><h3>Справочник — на устройстве</h3><p>Все сотрудники, их организации, категории питания и статусы карт хранятся локально. Проверка идёт без обращения к сети.</p></div>
      <div class="card"><h3>Записи копятся и уходят сами</h3><p>Проход сохраняется мгновенно. Когда связь появляется, накопленное отправляется на сервер автоматически, без участия оператора.</p></div>
      <div class="card"><h3>Видно, что не отправлено</h3><p>В журнале у каждой записи есть признак: «синхронизировано» или «офлайн». Потерять проход нельзя.</p></div>
    </div>
    <p class="note"><strong style="color:#E8A33A">Честная оговорка.</strong> Без связи терминал знает только свои проходы. Повтор в пределах этой точки он отклонит, а совпадение с соседней точкой станет видно в отчёте после синхронизации. Там, где точки стоят рядом, это закрывается постоянным интернетом на раздаче.</p>
  </div>
</section>

<section class="s" id="s8">
  <div class="inner">
    <p class="eyebrow">Контроль</p>
    <h2>Двойное питание</h2>
    <p class="sub">Один приём пищи — один раз в день.</p>
    <div class="grid g2">
      <div class="card card--accent"><h3>В пределах точки</h3><p>Повтор того же приёма пищи в тот же день отклоняется всегда, даже без интернета: терминал видит собственные записи.</p></div>
      <div class="card card--accent"><h3>Между точками</h3><p>Если связь есть, терминал спрашивает сервер: не проходил ли человек этот же приём пищи на другой точке. Это важно, когда залы раздачи стоят рядом.</p></div>
      <div class="card"><h3>Дубли при синхронизации</h3><p>Повторная отправка той же записи не создаёт вторую строку: сервер распознаёт её по идентификатору и блокировке.</p></div>
      <div class="card"><h3>Поиск дублей в базе</h3><p>Отдельный инструмент находит записи, попавшие в базу разными путями, — например, скан и не удалённый ручной пропуск.</p></div>
    </div>
    <p class="note">Оператор при отказе видит время, когда человек уже проходил, — спорить не о чем.</p>
  </div>
</section>

<section class="s" id="s9">
  <div class="inner">
    <p class="eyebrow">Расписание</p>
    <h2>Терминал сам понимает, какой сейчас приём пищи</h2>
    <div class="meals">
      <div class="meal"><b>Завтрак</b><span>07:00 – 11:00</span></div>
      <div class="meal"><b>Обед</b><span>12:00 – 15:00</span></div>
      <div class="meal"><b>Ужин</b><span>18:00 – 21:00</span></div>
      <div class="meal meal--night"><b>Ночное</b><span>23:00 – 06:00</span></div>
    </div>
    <div class="grid g2">
      <div class="card"><h3>Расписание у каждой точки своё</h3><p>Окна задаются в веб-интерфейсе отдельно для каждой точки раздачи и дня недели. Значения выше — по умолчанию.</p></div>
      <div class="card"><h3>Ночное — полноценный приём</h3><p>Окно может переходить через полночь: смена «23:00–06:00» продолжается в следующий день, и проход в 00:30 относится к ней.</p></div>
      <div class="card"><h3>Часовой пояс — по точке</h3><p>У площадок в разных регионах свои сутки. Граница дня считается по поясу точки, поэтому отчёты не «съезжают» на несколько часов.</p></div>
      <div class="card"><h3>Проход вне расписания</h3><p>Такие записи не теряются: они помечаются как «вне графика», и их видно отдельным фильтром в отчёте.</p></div>
    </div>
  </div>
</section>

<section class="s" id="s10">
  <div class="inner">
    <p class="eyebrow">Мультиплатформенность</p>
    <h2>Площадка не привязана к одному типу устройств</h2>
    <p class="sub">Код один на все устройства: правило проверки прохода везде одинаково, оператор не переучивается, а на одной площадке устройства разных типов работают одновременно.</p>
    <div class="plat">
      <div><div><h3>Смарт-терминал Эвотор</h3><small>Evotor OS (Android)</small></div>
        <p>Готовое рабочее место: экран, корпус, USB-порты. Ставится и обновляется через Эвотор.Маркет. Целевая модель — Эвотор 7.3.</p></div>
      <div><div><h3>Планшет на Android</h3><small>Android 5.1 и новее</small></div>
        <p>Дёшево и мобильно. Экранное закрепление не даёт выйти из приложения, автозапуск после включения, обновление из самого приложения.</p></div>
      <div><div><h3>Компьютер на Windows</h3><small>Windows 10 и 11</small></div>
        <p>Для точек с мини-ПК и монитором. Полноэкранный киоск, автообновление ночью, экранная клавиатура для терминалов без физической.</p></div>
      <div><div><h3>Веб-интерфейс</h3><small>Windows · macOS · Linux · Android · iOS</small></div>
        <p>Администратор работает без установки чего-либо: справочники, расписание, отчёты и печать карт открываются с компьютера и с телефона.</p></div>
    </div>
  </div>
</section>

<section class="s" id="s11">
  <div class="inner">
    <p class="eyebrow">Эвотор</p>
    <h2>Смарт-терминал Эвотор 7.3</h2>
    <p class="sub">Приложение публикуется в Эвотор.Маркете — установка в один клик.</p>
    <div class="split--rev split">
      <div class="grid" style="gap:14px">
        <div class="card"><h3>Установка из магазина</h3><p>Приложение ставится на терминал из Эвотор.Маркета и обновляется оттуда же — без флешек и ручных APK.</p></div>
        <div class="card"><h3>Штатный сканер терминала</h3><p>Код от внешнего QR-сканера принимается через драйвер терминала. Сканер в режиме клавиатуры тоже поддержан.</p></div>
        <div class="card"><h3>Касса остаётся доступной</h3><p>Приложение не захватывает экран: кассир в любой момент возвращается в меню Эвотора. Фискальная часть не задействована — это учёт прохода, а не продажа.</p></div>
      </div>
      <figure>
        <img src="{{scanner}}" alt="Рабочий экран приложения на терминале">
        <figcaption>Экран 7″, 1024×600 · Evotor OS 4 · 5 USB-портов. Данные на снимке демонстрационные.</figcaption>
      </figure>
    </div>
  </div>
</section>

<section class="s" id="s12">
  <div class="inner">
    <p class="eyebrow">Пропуска</p>
    <h2>Карты сотрудников</h2>
    <p class="sub">QR-код выпускается в системе и живёт по своим правилам.</p>
    <div class="grid g4">
      <div class="card"><h3>Печать на бумаге</h3><p>Карточка печатается из веб-интерфейса поштучно или сразу пачкой на всю организацию. Формат — под бейдж.</p></div>
      <div class="card"><h3>Файл на телефон</h3><p>Персональная карточка выгружается файлом и отправляется человеку в мессенджер: код показывают с экрана телефона.</p></div>
      <div class="card"><h3>Срок и блокировка</h3><p>У карты есть статус: активна, просрочена, заблокирована. Потерянная карта отключается одним действием.</p></div>
      <div class="card card--accent"><h3>Код не меняется при правке</h3><p>Исправление ФИО или организации не перевыпускает QR — карта на руках продолжает работать.</p></div>
    </div>
  </div>
</section>

<section class="s" id="s13">
  <div class="inner">
    <p class="eyebrow">Серверная часть</p>
    <h2>Веб-интерфейс</h2>
    <p class="sub">Работа в браузере — устанавливать на компьютеры нечего.</p>
    <div class="grid g4">
      <div class="card"><h3>Сотрудники</h3><ul><li>справочник и поиск</li><li>организации и подразделения</li><li>категория и цена питания</li><li>выпуск и печать карт</li></ul></div>
      <div class="card"><h3>Точки и расписание</h3><ul><li>точки раздачи</li><li>окна приёмов пищи</li><li>часовой пояс точки</li><li>операторы точек</li></ul></div>
      <div class="card"><h3>Отчёты</h3><ul><li>журнал проходов</li><li>сводка по сотрудникам</li><li>сухпай и выездное</li><li>выгрузка в Excel</li></ul></div>
      <div class="card"><h3>Обслуживание</h3><ul><li>массовая проводка</li><li>ручной пропуск</li><li>поиск дублей</li><li>журнал действий</li></ul></div>
    </div>
    <p class="note">Нужен обычный хостинг с PHP и MySQL — отдельный сервер и системный администратор на площадке не требуются. Интерфейс адаптирован под телефон.</p>
  </div>
</section>

<section class="s" id="s14">
  <div class="inner">
    <p class="eyebrow">Отчётность</p>
    <h2>Счёт по каждой организации и человеку</h2>
    <figure>
      <img src="{{report}}" alt="Сводный отчёт по сотрудникам">
      <figcaption>Данные на снимке экрана демонстрационные.</figcaption>
    </figure>
    <div class="grid g4" style="margin-top:22px">
      <div class="card"><h3>По сотруднику</h3><p>Завтраки, обеды, ужины, ночное, всего приёмов и дней в столовой.</p></div>
      <div class="card"><h3>По организации</h3><p>Итоги по подрядчику и общий итог — основание для счёта.</p></div>
      <div class="card"><h3>Фильтры</h3><p>Период, точка, тип питания, способ проводки, организации, ФИО.</p></div>
      <div class="card"><h3>Выгрузка в Excel</h3><p>С теми же фильтрами, что и на экране: что видно — то и выгружается.</p></div>
    </div>
  </div>
</section>

<section class="s" id="s15">
  <div class="inner">
    <p class="eyebrow">Кроме зала</p>
    <h2>Сухой паёк, выезды и нештатные ситуации</h2>
    <div class="grid g2">
      <div class="card"><h3>Сухой паёк и выездное питание</h3><p>Выдача сухпая и питание на выезде отмечаются отдельно и попадают в отчёты вместе с обычными проходами.</p></div>
      <div class="card"><h3>Массовая проводка</h3><p>Когда смена питалась без терминала (авария, выезд), проходы проводятся списком — с отметкой о способе, чтобы это было видно в отчёте.</p></div>
      <div class="card"><h3>Ручной пропуск</h3><p>Если карта не читается, оператор находит человека в списке и проводит вручную. Запись помечается как ручная.</p></div>
      <div class="card"><h3>Способ проводки виден всегда</h3><p>Скан, ручной пропуск, массовая проводка или синхронизация с точки — в отчёте это отдельная колонка и фильтр.</p></div>
    </div>
  </div>
</section>

<section class="s" id="s16">
  <div class="inner">
    <p class="eyebrow">Доступ</p>
    <h2>Роли</h2>
    <p class="sub">Каждый видит ровно то, что нужно для его работы.</p>
    <div class="grid g3">
      <div class="card"><h3>Оператор</h3><p>Работает на раздаче.</p><ul><li>сканирование и ручной пропуск</li><li>журнал своей точки</li><li>список сотрудников для поиска</li></ul></div>
      <div class="card"><h3>Администратор</h3><p>Ведёт площадку.</p><ul><li>сотрудники и организации</li><li>точки и расписание</li><li>отчёты и выгрузки</li><li>выпуск и печать карт</li></ul></div>
      <div class="card card--accent"><h3>Супер-администратор</h3><p>Отвечает за систему.</p><ul><li>все площадки и настройки</li><li>обслуживание базы</li><li>управление доступом</li><li>журнал действий</li></ul></div>
    </div>
    <p class="note">Вход по карте сотрудника: отдельный пароль оператору не нужен и не теряется. Действия администраторов пишутся в журнал.</p>
  </div>
</section>

<section class="s" id="s17">
  <div class="inner">
    <p class="eyebrow">Несколько площадок</p>
    <h2>Центр управления</h2>
    <div class="split">
      <figure>
        <img src="{{admin}}" alt="Центр управления площадками">
        <figcaption>Данные на снимке экрана демонстрационные.</figcaption>
      </figure>
      <div class="grid" style="gap:14px">
        <div class="card"><h3>Все площадки сразу</h3><p>Сотрудники, точки, питание за сегодня и версии приложений по каждому региону.</p></div>
        <div class="card"><h3>Сводные отчёты</h3><p>Отчёт по всем площадкам или по выбранным: галочками отмечаются нужные регионы.</p></div>
        <div class="card"><h3>Единый пропуск</h3><p>Руководитель заводится одной картой сразу на всех площадках.</p></div>
        <div class="card"><h3>Новая площадка</h3><p>Мастер разворачивает регион по образцу действующего.</p></div>
      </div>
    </div>
  </div>
</section>

<section class="s" id="s18">
  <div class="inner">
    <p class="eyebrow">Эксплуатация</p>
    <h2>Наблюдение за точками и поддержка</h2>
    <p class="sub">Проблему видно раньше, чем о ней сообщат с площадки.</p>
    <div class="grid g4">
      <div class="card"><h3>Кто на связи</h3><p>Видно, какие точки сейчас работают, когда последний раз выходили на связь и какая на них версия приложения.</p></div>
      <div class="card"><h3>Очередь записей</h3><p>Если точка накопила непереданные записи, это заметно до того, как расхождение попадёт в отчёт.</p></div>
      <div class="card"><h3>Удалённые команды</h3><p>Синхронизация, обновление и снятие блокировки экрана запускаются с сервера — без выезда на площадку.</p></div>
      <div class="card"><h3>Обновления</h3><p>Windows-версия обновляется сама ночью, планшеты — из приложения, Эвотор — через Эвотор.Маркет.</p></div>
    </div>
  </div>
</section>

<section class="s s--ink" id="s19">
  <div class="inner">
    <p class="eyebrow">Данные</p>
    <h2>Достоверность</h2>
    <p class="sub">Отчёт становится основанием для счёта только тогда, когда цифрам можно верить.</p>
    <div class="grid g3">
      <div class="card"><h3>Время хранится в UTC</h3><p>В базе всегда одно время, местное считается при показе — по часовому поясу точки. Перевод часов и разные регионы не смещают отчёт.</p></div>
      <div class="card"><h3>Защита от дублей</h3><p>Повторная отправка той же записи не создаёт вторую строку. Есть отдельный поиск дублей, попавших в базу разными путями.</p></div>
      <div class="card"><h3>Видно способ проводки</h3><p>Скан, ручной пропуск, массовая проводка или синхронизация — в отчёте это отдельная колонка и фильтр.</p></div>
      <div class="card"><h3>Журнал действий</h3><p>Кто и что менял в справочниках и настройках — записывается и доступно супер-администратору.</p></div>
      <div class="card"><h3>Приведение к расписанию</h3><p>Инструмент сверяет исторические записи с расписанием точки и приводит тип питания в соответствие — с предпросмотром до сохранения.</p></div>
      <div class="card"><h3>Удаление под запретом</h3><p>Сотрудника с историей питания удалить нельзя: иначе отчёт за прошлый месяц перестал бы читаться.</p></div>
    </div>
  </div>
</section>

<section class="s" id="s20">
  <div class="inner">
    <p class="eyebrow">152-ФЗ</p>
    <h2>Персональные данные</h2>
    <div class="grid g2">
      <div class="card"><h3>Что хранится</h3><p>ФИО, организация, подразделение и должность, категория питания, QR-код карты и записи о проходах: дата, время, точка, тип питания.</p></div>
      <div class="card card--accent"><h3>Чего в системе нет</h3><p>Биометрии: фотографий, отпечатков и шаблонов лиц нет вообще. Камера используется только для распознавания QR-кода на лету — изображение никуда не сохраняется.</p></div>
    </div>
    <div class="grid g3" style="margin-top:16px">
      <div class="card"><h3>Доступ по ролям</h3><p>Оператору не показываются дата рождения, цена питания и категория: для проведения человека они не нужны.</p></div>
      <div class="card"><h3>Карты высшего доступа</h3><p>Администратор не может открыть и распечатать карточку супер-администратора.</p></div>
      <div class="card"><h3>Данные у заказчика</h3><p>Система разворачивается на вашем хостинге. Данные никуда не передаются: внешних сервисов в контуре нет.</p></div>
    </div>
  </div>
</section>

<section class="s" id="s21">
  <div class="inner">
    <p class="eyebrow">Запуск</p>
    <h2>Как проходит внедрение</h2>
    <div class="grid g3">
      <div class="card"><span class="num">Шаг 1</span><h3>Развёртывание</h3><p>Сервер на вашем хостинге, домен площадки, первичная настройка.</p></div>
      <div class="card"><span class="num">Шаг 2</span><h3>Загрузка данных</h3><p>Сотрудники загружаются списком из вашей таблицы: ФИО, организация, подразделение, должность.</p></div>
      <div class="card"><span class="num">Шаг 3</span><h3>Карты</h3><p>Выпуск QR-кодов, печать карточек или рассылка файлов сотрудникам.</p></div>
      <div class="card"><span class="num">Шаг 4</span><h3>Точки и расписание</h3><p>Точки раздачи, окна приёмов пищи, часовой пояс, операторы.</p></div>
      <div class="card"><span class="num">Шаг 5</span><h3>Устройства</h3><p>Установка приложения на терминалы, подключение сканеров, проверка на месте.</p></div>
      <div class="card card--accent"><span class="num">Шаг 6</span><h3>Обучение и запуск</h3><p>Оператору хватает получаса: подносить карту и читать цвет ответа.</p></div>
    </div>
  </div>
</section>

<section class="s" id="s22">
  <div class="inner">
    <p class="eyebrow">Требования</p>
    <h2>Что нужно для работы</h2>
    <p class="sub">Ничего экзотического — всё работает на типовом оборудовании.</p>
    <div class="grid g4">
      <div class="card"><h3>Сервер площадки</h3><ul><li>хостинг с PHP 8 и MySQL</li><li>домен и сертификат HTTPS</li><li>отдельный сервер не нужен</li></ul></div>
      <div class="card card--accent"><h3>Точка раздачи</h3><ul><li>Эвотор 7.3, планшет Android или ПК с Windows</li><li>QR-сканер в USB</li><li>интернет желателен, но не обязателен</li></ul></div>
      <div class="card"><h3>Рабочее место администратора</h3><ul><li>любой современный браузер</li><li>подходит и телефон</li><li>установка ПО не требуется</li></ul></div>
      <div class="card"><h3>Карты сотрудников</h3><ul><li>печать на обычном принтере</li><li>или файл на телефоне</li><li>расходники — бумага и бейдж</li></ul></div>
    </div>
  </div>
</section>

<section class="s" id="s23">
  <div class="inner">
    <p class="eyebrow">Результат</p>
    <h2>Что получает заказчик</h2>
    <div class="grid g2">
      <div class="card card--accent"><h3>Счёт подрядчику — из системы</h3><p>Сколько завтраков, обедов, ужинов и ночных приёмов получила каждая организация и каждый человек. Выгрузка в Excel, а не рукописная ведомость.</p></div>
      <div class="card"><h3>Раздача без очереди</h3><p>Оператор не ищет фамилию: карта, звук, цвет, следующий. Ошибку при вводе сделать негде.</p></div>
      <div class="card"><h3>Контроль вместо доверия</h3><p>Повторное питание отклоняется на месте, проходы вне графика видны отдельно, все действия администраторов записаны.</p></div>
      <div class="card"><h3>Свобода в выборе оборудования</h3><p>Эвотор, планшет или компьютер — на выбор и одновременно на разных точках. Веб-интерфейс открывается в любом браузере, включая телефон.</p></div>
    </div>
  </div>
</section>

<section class="s s--ink" id="s24">
  <div class="inner">
    <div class="hero">
      <div>
        <img class="hero-logo" src="{{logo}}" alt="Логотип «Север»">
        <h1 style="font-size:clamp(32px,5vw,58px)">СеверФудс</h1>
        <p class="lead">Система учёта питания сотрудников</p>
      </div>
      <div class="card" style="align-self:center">
        <p class="eyebrow" style="margin-bottom:8px">Производитель ПО</p>
        <h3 style="font-size:26px;margin-bottom:14px">ООО «Север»</h3>
        <p>Телефон: ‹укажите›<br>Почта: ‹укажите›<br>Сайт: ‹укажите›</p>
      </div>
    </div>
    <p class="note">Версия системы 1.7.7 · сентябрь 2026</p>
  </div>
</section>

</main>

<script>
// Листание стрелками и точки-навигация справа: страница длинная, а читают её
// как презентацию — с клавиатуры.
(function () {
  var sections = Array.prototype.slice.call(document.querySelectorAll('.s'));
  var rail = document.getElementById('rail');
  var progress = document.getElementById('progress');

  sections.forEach(function (s, i) {
    var a = document.createElement('a');
    a.href = '#' + s.id;
    a.setAttribute('aria-label', 'Раздел ' + (i + 1));
    rail.appendChild(a);
  });
  var dots = Array.prototype.slice.call(rail.children);

  function current() {
    var best = 0, bestDist = Infinity;
    sections.forEach(function (s, i) {
      var d = Math.abs(s.getBoundingClientRect().top);
      if (d < bestDist) { bestDist = d; best = i; }
    });
    return best;
  }

  function mark() {
    var i = current();
    dots.forEach(function (d, j) { d.setAttribute('aria-current', j === i ? 'true' : 'false'); });
    var h = document.documentElement.scrollHeight - window.innerHeight;
    progress.style.width = (h > 0 ? (window.scrollY / h) * 100 : 0) + '%';
  }

  function go(step) {
    var i = Math.min(sections.length - 1, Math.max(0, current() + step));
    sections[i].scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  addEventListener('scroll', mark, { passive: true });
  addEventListener('resize', mark);
  addEventListener('keydown', function (e) {
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') { e.preventDefault(); go(1); }
    if (e.key === 'ArrowLeft'  || e.key === 'PageUp')                    { e.preventDefault(); go(-1); }
    if (e.key === 'Home') { e.preventDefault(); sections[0].scrollIntoView({ behavior: 'smooth' }); }
    if (e.key === 'End')  { e.preventDefault(); sections[sections.length - 1].scrollIntoView({ behavior: 'smooth' }); }
  });
  mark();
})();
</script>
"""

FONTS = ('<link rel="preconnect" href="https://fonts.googleapis.com">\n'
         '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>\n'
         '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?'
         'family=Onest:wght@400;500;700;800&family=IBM+Plex+Mono:wght@400;500&'
         'display=swap">')


def build():
    body = BODY
    for key, uri in IMG.items():
        body = body.replace('{{' + key + '}}', uri)

    head = f'<title>{TITLE}</title>\n{FONTS}\n{STYLE}'

    # Для публикации: страницу оборачивает платформа, свои html/head/body не нужны
    (HERE / 'web' / 'artifact.html').write_text(head + body, encoding='utf-8')

    # Самодостаточный файл: можно положить на сайт или отправить письмом
    standalone = (
        '<!doctype html>\n<html lang="ru">\n<head>\n'
        '<meta charset="utf-8">\n'
        '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n'
        '<meta name="description" content="Система учёта питания сотрудников на производственных '
        'площадках. Производитель ПО — ООО «Север».">\n'
        f'{head}\n</head>\n<body>{body}</body>\n</html>\n'
    )
    (HERE / 'index.html').write_text(standalone, encoding='utf-8')

    size = len(standalone.encode()) / 1024
    print(f'index.html — {size:.0f} КБ, разделов: {body.count("<section")}')


if __name__ == '__main__':
    build()
