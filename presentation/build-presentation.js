/**
 * Презентация системы «СеверФудс» — ООО «Север».
 *
 * Собирается из фактов о том, как система реально работает: скриншоты сняты
 * с настоящего интерфейса, цифры в примерах демонстрационные и подписаны.
 */
const pptxgen = require('pptxgenjs');
const path = require('path');

// Изображения: снимки экрана настоящего интерфейса с демонстрационными данными.
// assets/ — снимки веб-части и светлый логотип, android/market — терминал.
const SCR = path.join(__dirname, 'assets');
const MKT = path.join(__dirname, '..', 'android', 'market');

// ── Палитра: фирменные цвета «Севера» ────────────────────────
const NAVY   = '003366';   // основной, 60–70% веса
const NAVY_D = '001A3A';   // тёмные слайды
const TEAL   = '00B49B';   // акцент из логотипа
const ICE    = 'EDF2F8';   // светлая подложка карточек
const WHITE  = 'FFFFFF';
const TEXT   = '0F172A';
const MUTED  = '5B6B7F';
const AMBER  = 'F59E0B';
const RED    = 'DC2626';

const H = 'Cambria';       // заголовки
const B = 'Calibri';       // текст

const pres = new pptxgen();
pres.layout = 'LAYOUT_WIDE';            // 13.333 × 7.5
const W = 13.333, HT = 7.5;
pres.author = 'ООО «Север»';
pres.company = 'ООО «Север»';
pres.title = 'СеверФудс — система учёта питания';

const shadow = () => ({ type: 'outer', color: '0F172A', blur: 12, offset: 2, angle: 90, opacity: 0.12 });

/** Светлый слайд с заголовком. */
function slide(title, sub) {
    const s = pres.addSlide();
    s.background = { color: WHITE };
    if (title) {
        s.addText(title, {
            x: 0.65, y: 0.42, w: W - 1.3, h: 0.72, isTextBox: true, margin: 0,
            fontFace: H, fontSize: 32, bold: true, color: NAVY,
        });
    }
    if (sub) {
        s.addText(sub, {
            x: 0.65, y: 1.16, w: W - 1.3, h: 0.42, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15, color: MUTED,
        });
    }
    return s;
}

/** Карточка со скруглением и мягкой тенью. */
function card(s, x, y, w, h, fill) {
    s.addShape(pres.ShapeType.roundRect, {
        x, y, w, h, rectRadius: 0.12,
        fill: { color: fill || ICE }, line: { color: fill || ICE }, shadow: shadow(),
    });
}

/** Кружок с номером или знаком. */
function badge(s, x, y, d, text, fill, color) {
    s.addShape(pres.ShapeType.ellipse, {
        x, y, w: d, h: d, fill: { color: fill || TEAL }, line: { color: fill || TEAL },
    });
    s.addText(text, {
        x, y, w: d, h: d, isTextBox: true, margin: 0,
        align: 'center', valign: 'middle', fontFace: B, fontSize: 14, bold: true,
        color: color || WHITE,
    });
}

/** Абзац внутри карточки: заголовок + текст. */
function cardText(s, x, y, w, head, body, headColor) {
    s.addText(head, {
        x, y, w, h: 0.5, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 14.5, bold: true, color: headColor || NAVY,
    });
    s.addText(body, {
        x, y: y + 0.52, w, h: 1.62, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 11.5, color: TEXT, lineSpacingMultiple: 1.12,
    });
}

/* ═══════════════ 1. Титул ═══════════════ */
{
    const s = pres.addSlide();
    s.background = { color: NAVY_D };
    s.addImage({ path: `${SCR}/logo-white.png`, x: 0.9, y: 0.75, w: 2.6, h: 1.01 });
    s.addText('СеверФудс', {
        x: 0.9, y: 2.25, w: 9.5, h: 1.1, isTextBox: true, margin: 0,
        fontFace: H, fontSize: 54, bold: true, color: WHITE,
    });
    s.addText('Система учёта питания сотрудников на производственных площадках', {
        x: 0.9, y: 3.35, w: 8.6, h: 0.9, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 20, color: 'A8C0DA', lineSpacingMultiple: 1.2,
    });
    s.addShape(pres.ShapeType.roundRect, {
        x: 0.9, y: 4.5, w: 6.4, h: 0.52, rectRadius: 0.26,
        fill: { color: TEAL }, line: { color: TEAL },
    });
    s.addText('Windows · Android · Эвотор · Веб-интерфейс', {
        x: 0.9, y: 4.5, w: 6.4, h: 0.52, isTextBox: true, margin: 0,
        align: 'center', valign: 'middle', fontFace: B, fontSize: 13.5, bold: true, color: NAVY_D,
    });
    s.addText('Мультиплатформенное решение: терминалы на точках, сервер и отчётность', {
        x: 0.9, y: 5.2, w: 6.9, h: 0.4, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13, color: '8FB0D0',
    });
    s.addText('Производитель ПО — ООО «Север»', {
        x: 0.9, y: 6.2, w: 7, h: 0.4, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 15, bold: true, color: WHITE,
    });
    s.addText('Версия 1.7.7 · сентябрь 2026', {
        x: 0.9, y: 6.62, w: 7, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 12, color: '7E9BBD',
    });
    s.addImage({ path: `${MKT}/screen-2-result.jpg`, x: 8.15, y: 1.55, w: 4.5, h: 2.64,
                 rounding: false, shadow: shadow() });
    s.addImage({ path: `${MKT}/screen-5-logs.jpg`, x: 8.15, y: 4.35, w: 4.5, h: 2.64, shadow: shadow() });
    s.addNotes('Презентация системы учёта питания СеверФудс. Производитель — ООО «Север». Показать: терминал на раздаче, серверную часть с отчётами, центральную панель для нескольких площадок.');
}

/* ═══════════════ 2. Проблема ═══════════════ */
{
    const s = slide('Как учитывают питание сегодня', 'Бумажная ведомость на раздаче — и всё, что из неё следует');
    const items = [
        ['Очередь на раздаче', 'Оператор ищет фамилию среди сотен. В пиковый час — десятки секунд на человека.'],
        ['Двойное питание', 'По бумаге не проверить, получал ли человек этот обед. Тем более — на соседней точке.'],
        ['Споры с подрядчиками', 'Счёт подрядчику выставляется по рукописным отметкам. Оспорить их нечем.'],
        ['Ручной пересчёт', 'Сведение ведомостей в таблицу занимает дни и само по себе источник ошибок.'],
    ];
    let x = 0.65;
    items.forEach(([head, body], i) => {
        card(s, x, 1.95, 2.9, 2.85);
        badge(s, x + 0.28, 2.18, 0.5, String(i + 1), NAVY);
        cardText(s, x + 0.28, 2.8, 2.35, head, body);
        x += 3.05;
    });
    s.addText('Система заменяет ведомость картой с QR-кодом и терминалом на раздаче.', {
        x: 0.65, y: 5.25, w: W - 1.3, h: 0.5, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 16, bold: true, color: NAVY,
    });
    s.addNotes('Боли заказчика. Главная — не скорость, а невозможность доказать объём питания по организации.');
}

/* ═══════════════ 3. Как устроено ═══════════════ */
{
    const s = slide('Как это работает', 'Путь одного прохода: от карты сотрудника до строки в отчёте');
    const steps = [
        ['Карта', 'QR-пропуск\nу сотрудника'],
        ['Терминал', 'Проверка\nи решение'],
        ['Локальная база', 'Запись\nна устройстве'],
        ['Сервер', 'Синхронизация\nпри связи'],
        ['Отчёты', 'Excel\nи веб-интерфейс'],
    ];
    let x = 0.65;
    steps.forEach(([head, body], i) => {
        card(s, x, 2.25, 2.1, 1.9, i === 4 ? 'E3F5F1' : ICE);
        s.addText(head, {
            x: x + 0.1, y: 2.45, w: 1.9, h: 0.5, isTextBox: true, margin: 0,
            align: 'center', fontFace: B, fontSize: 14.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: x + 0.1, y: 2.95, w: 1.9, h: 1.05, isTextBox: true, margin: 0,
            align: 'center', fontFace: B, fontSize: 12, color: TEXT, lineSpacingMultiple: 1.1,
        });
        if (i < 4) {
            s.addShape(pres.ShapeType.rightArrow, {
                x: x + 2.18, y: 3.05, w: 0.38, h: 0.3,
                fill: { color: TEAL }, line: { color: TEAL },
            });
        }
        x += 2.56;
    });
    card(s, 0.65, 4.55, W - 1.3, 1.5, 'F3F7FB');
    s.addText('Ключевое: шаги 1–3 не требуют интернета', {
        x: 1.0, y: 4.78, w: 11, h: 0.36, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 15, bold: true, color: NAVY,
    });
    s.addText('Справочник сотрудников и записи о проходах лежат на самом устройстве. Связь нужна только для обмена с сервером: раздача идёт при выключенном интернете, а накопленные записи уходят сами, когда связь появится.', {
        x: 1.0, y: 5.15, w: 11.3, h: 0.8, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13, color: TEXT, lineSpacingMultiple: 1.15,
    });
    s.addNotes('Здесь же проговорить, что терминал — не «тонкий клиент»: он самодостаточен.');
}

/* ═══════════════ 4. Состав системы ═══════════════ */
{
    const s = slide('Из чего состоит система', 'Три части, которые ставятся независимо друг от друга');
    const parts = [
        ['Точка раздачи', 'Сканирование карт, локальная база и журнал за день. Мультиплатформенное — три варианта устройств:',
         ['Смарт-терминал Эвотор 7.3', 'Планшет на Android', 'Компьютер на Windows']],
        ['Сервер площадки', 'Веб-интерфейс и база данных: сотрудники, организации, точки, расписание, отчёты, печать карт.',
         ['PHP и MySQL на обычном хостинге', 'Работа в браузере, без установки', 'Выгрузки в Excel']],
        ['Центр управления', 'Отдельная панель для нескольких площадок: сводные отчёты по всем регионам сразу.',
         ['Реестр площадок', 'Сводные и выборочные отчёты', 'Наблюдение за точками']],
    ];
    let x = 0.65;
    parts.forEach(([head, body, list], i) => {
        card(s, x, 1.95, 3.9, 3.9, i === 0 ? 'E3F5F1' : ICE);
        s.addText(head, {
            x: x + 0.3, y: 2.25, w: 3.3, h: 0.4, isTextBox: true, margin: 0,
            fontFace: H, fontSize: 19, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: x + 0.3, y: 2.72, w: 3.35, h: 1.45, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12, color: TEXT, lineSpacingMultiple: 1.15,
        });
        s.addText(list.map((t, j) => ({ text: t, options: { bullet: true, breakLine: j < list.length - 1 } })), {
            x: x + 0.3, y: 4.25, w: 3.35, h: 1.4, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, color: NAVY, paraSpaceAfter: 6,
        });
        x += 4.1;
    });
    s.addText('Одна площадка может работать без центра управления. Центр нужен, когда площадок несколько.', {
        x: 0.65, y: 6.1, w: W - 1.3, h: 0.4, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13, italic: true, color: MUTED,
    });
}

/* ═══════════════ 5. Сценарий раздачи ═══════════════ */
{
    const s = slide('Сценарий на раздаче', 'Что делает оператор и что делает система');
    const steps = [
        ['Оператор входит по своей карте', 'Терминал знает, кто работает на раздаче, — это попадает в журнал.'],
        ['Сотрудник подносит карту', 'Сканирование включено постоянно: наводить курсор и нажимать ничего не нужно.'],
        ['Система проверяет право', 'Есть ли человек в справочнике, активна ли карта, какой сейчас приём пищи, не получал ли он его уже.'],
        ['Крупная карточка с решением', 'ФИО, организация и результат видны с расстояния — оператор не вчитывается в мелкий текст.'],
        ['Запись сохраняется', 'Сразу в локальную базу, затем на сервер. В журнале видно, что уже отправлено.'],
    ];
    let y = 1.95;
    steps.forEach(([head, body], i) => {
        badge(s, 0.7, y + 0.05, 0.44, String(i + 1), i === 2 ? NAVY : TEAL);
        s.addText(head, {
            x: 1.3, y, w: 5.6, h: 0.32, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: 1.3, y: y + 0.33, w: 5.75, h: 0.62, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 11.5, color: TEXT, lineSpacingMultiple: 1.1,
        });
        y += 1.0;
    });
    s.addImage({ path: `${MKT}/screen-2-result.jpg`, x: 7.45, y: 1.95, w: 5.2, h: 3.05, shadow: shadow() });
    s.addText('Проход занимает секунду-две', {
        x: 7.45, y: 5.15, w: 5.2, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 14, bold: true, color: NAVY,
    });
    s.addText('Проверка идёт по локальной базе. Когда есть связь, добавляется запрос к серверу — не получал ли человек этот приём пищи на другой точке.', {
        x: 7.45, y: 5.5, w: 5.2, h: 0.9, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 12, color: MUTED, lineSpacingMultiple: 1.15,
    });
}

/* ═══════════════ 6. Три ответа терминала ═══════════════ */
{
    const s = slide('Три ответа терминала', 'Решение видно с расстояния — цветом, а не текстом');
    s.addImage({ path: `${MKT}/screen-2-result.jpg`, x: 0.65, y: 1.95, w: 6.0, h: 3.52, shadow: shadow() });
    s.addImage({ path: `${MKT}/screen-3-duplicate.jpg`, x: 6.95, y: 1.95, w: 6.0, h: 3.52, shadow: shadow() });

    const legend = [
        [TEAL,  'Зелёный — проход засчитан', 'Видно ФИО, организацию и какой приём пищи зафиксирован.'],
        [AMBER, 'Жёлтый — повторный проход', 'Этот приём пищи человек уже получил сегодня; показывается время.'],
        [RED,   'Красный — отказ', 'Карта не найдена, просрочена или заблокирована.'],
    ];
    let x = 0.65;
    legend.forEach(([color, head, body]) => {
        card(s, x, 5.5, 3.9, 1.55, ICE);
        s.addShape(pres.ShapeType.ellipse, {
            x: x + 0.28, y: 5.82, w: 0.24, h: 0.24, fill: { color }, line: { color },
        });
        s.addText(head, {
            x: x + 0.6, y: 5.74, w: 3.2, h: 0.42, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: x + 0.28, y: 6.2, w: 3.45, h: 0.85, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 10.5, color: TEXT, lineSpacingMultiple: 1.1,
        });
        x += 4.1;
    });
    s.addNotes('Звуковой сигнал отличается для каждого случая — оператор не обязан смотреть на экран.');
}

/* ═══════════════ 7. Работа без интернета ═══════════════ */
{
    const s = pres.addSlide();
    s.background = { color: NAVY_D };
    s.addText('Столовая не останавливается из-за связи', {
        x: 0.65, y: 0.6, w: 11.5, h: 0.75, isTextBox: true, margin: 0,
        fontFace: H, fontSize: 32, bold: true, color: WHITE,
    });
    s.addText('На вахте и стройке интернет пропадает. Это не должно останавливать раздачу.', {
        x: 0.65, y: 1.35, w: 11.5, h: 0.4, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 15, color: 'A8C0DA',
    });
    const blocks = [
        ['Справочник — на устройстве', 'Все сотрудники, их организации, категории питания и статусы карт хранятся локально. Проверка идёт без обращения к сети.'],
        ['Записи копятся и уходят сами', 'Проход сохраняется мгновенно. Когда связь появляется, накопленное отправляется на сервер автоматически, без участия оператора.'],
        ['Видно, что ещё не отправлено', 'В журнале у каждой записи есть признак: «синхронизировано» или «офлайн». Потерять проход нельзя.'],
    ];
    let x = 0.65;
    blocks.forEach(([head, body]) => {
        s.addShape(pres.ShapeType.roundRect, {
            x, y: 2.15, w: 3.9, h: 2.5, rectRadius: 0.12,
            fill: { color: '0A2A52' }, line: { color: '17406F' },
        });
        s.addText(head, {
            x: x + 0.3, y: 2.45, w: 3.3, h: 0.6, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15, bold: true, color: TEAL, lineSpacingMultiple: 1.1,
        });
        s.addText(body, {
            x: x + 0.3, y: 3.08, w: 3.35, h: 1.5, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12, color: 'D5E3F2', lineSpacingMultiple: 1.2,
        });
        x += 4.1;
    });
    s.addText('Честная оговорка', {
        x: 0.65, y: 5.1, w: 11.5, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 14, bold: true, color: AMBER,
    });
    s.addText('Без связи терминал знает только свои проходы. Повтор в пределах этой точки он отклонит, а совпадение с соседней точкой станет видно в отчёте после синхронизации. Там, где точки стоят рядом, это закрывается постоянным интернетом на раздаче.', {
        x: 0.65, y: 5.45, w: 11.8, h: 1.0, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13, color: 'D5E3F2', lineSpacingMultiple: 1.2,
    });
}

/* ═══════════════ 8. Двойное питание ═══════════════ */
{
    const s = slide('Контроль двойного питания', 'Один приём пищи — один раз в день');
    const rules = [
        ['В пределах точки', 'Повтор того же приёма пищи в тот же день отклоняется всегда, даже без интернета: терминал видит собственные записи.'],
        ['Между точками', 'Если связь есть, терминал спрашивает сервер: не проходил ли человек этот же приём пищи на другой точке. Это важно, когда залы раздачи стоят рядом.'],
        ['Дубли при синхронизации', 'Повторная отправка той же записи не создаёт вторую строку: сервер распознаёт её по идентификатору и блокировке.'],
        ['Поиск дублей в базе', 'Отдельный инструмент находит записи, попавшие в базу разными путями, — например, скан и не удалённый ручной пропуск.'],
    ];
    let y = 1.95, x = 0.65;
    rules.forEach(([head, body], i) => {
        if (i === 2) { y = 1.95; x = 6.95; }
        card(s, x, y, 5.7, 1.85, i < 2 ? 'E3F5F1' : ICE);
        s.addText(head, {
            x: x + 0.3, y: y + 0.25, w: 5.1, h: 0.35, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: x + 0.3, y: y + 0.62, w: 5.15, h: 1.15, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12, color: TEXT, lineSpacingMultiple: 1.15,
        });
        y += 2.05;
    });
    s.addText('Оператор при отказе видит время, когда человек уже проходил, — спорить не о чем.', {
        x: 0.65, y: 6.2, w: W - 1.3, h: 0.4, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13.5, italic: true, color: MUTED,
    });
}

/* ═══════════════ 9. Расписание и типы питания ═══════════════ */
{
    const s = slide('Расписание столовой и типы питания', 'Терминал сам понимает, какой сейчас приём пищи');
    const meals = [['Завтрак', '07:00 – 11:00'], ['Обед', '12:00 – 15:00'],
                   ['Ужин', '18:00 – 21:00'], ['Ночное', '23:00 – 06:00']];
    let x = 0.65;
    meals.forEach(([name, time], i) => {
        card(s, x, 1.95, 2.9, 1.5, i === 3 ? 'E3F5F1' : ICE);
        s.addText(name, {
            x: x + 0.25, y: 2.2, w: 2.4, h: 0.4, isTextBox: true, margin: 0,
            fontFace: H, fontSize: 20, bold: true, color: NAVY,
        });
        s.addText(time, {
            x: x + 0.25, y: 2.68, w: 2.4, h: 0.4, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14, color: MUTED,
        });
        x += 3.05;
    });
    const notes = [
        ['Расписание у каждой точки своё', 'Окна задаются в веб-интерфейсе отдельно для каждой точки раздачи и дня недели. Пример выше — значения по умолчанию.'],
        ['Ночное питание — полноценный приём', 'Окно может переходить через полночь: смена «23:00–06:00» продолжается в следующий день, и проход в 00:30 относится к ней.'],
        ['Часовой пояс — по точке, а не по серверу', 'У площадок в разных регионах свои сутки. Граница дня считается по поясу точки, поэтому отчёты не «съезжают» на несколько часов.'],
        ['Проход вне расписания', 'Такие записи не теряются: они помечаются как «вне графика», и их видно отдельным фильтром в отчёте.'],
    ];
    let y = 3.75; x = 0.65;
    notes.forEach(([head, body], i) => {
        if (i === 2) { y = 3.75; x = 6.95; }
        s.addText(head, {
            x, y, w: 5.7, h: 0.44, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14, bold: true, color: NAVY,
        });
        s.addText(body, {
            x, y: y + 0.44, w: 5.7, h: 0.85, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12, color: TEXT, lineSpacingMultiple: 1.15,
        });
        y += 1.45;
    });
}

/* ═══════════════ 10. Мультиплатформенность ═══════════════ */
{
    const s = slide('Мультиплатформенность',
                    'Площадка не привязана к одному типу устройств и одному поставщику');
    const rows = [
        ['Смарт-терминал Эвотор', 'Evotor OS (Android)',
         'Готовое рабочее место: экран, корпус, USB-порты. Ставится и обновляется через Эвотор.Маркет. Целевая модель — Эвотор 7.3.', 'E3F5F1'],
        ['Планшет на Android', 'Android 5.1 и новее',
         'Дёшево и мобильно. Экранное закрепление не даёт выйти из приложения, автозапуск после включения, обновление из самого приложения.', ICE],
        ['Компьютер на Windows', 'Windows 10 и 11',
         'Для точек с мини-ПК и монитором. Полноэкранный киоск, автообновление ночью, экранная клавиатура для терминалов без физической.', ICE],
        ['Веб-интерфейс', 'Любой браузер: Windows, macOS, Linux, Android, iOS',
         'Администратор работает без установки чего-либо: справочники, расписание, отчёты и печать карт открываются с компьютера и с телефона.', ICE],
    ];
    let y = 1.8;
    rows.forEach(([head, os, body, fill]) => {
        card(s, 0.65, y, W - 1.3, 1.12, fill);
        s.addText(head, {
            x: 1.0, y: y + 0.14, w: 4.3, h: 0.34, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14.5, bold: true, color: NAVY,
        });
        s.addText(os, {
            x: 1.0, y: y + 0.5, w: 4.3, h: 0.45, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 10.5, color: MUTED,
        });
        s.addText(body, {
            x: 5.5, y: y + 0.16, w: 7.0, h: 0.85, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 11.5, color: TEXT, lineSpacingMultiple: 1.12,
        });
        y += 1.24;
    });
    s.addText('Код один на все устройства: правило проверки прохода везде одинаково, оператор не переучивается, а на одной площадке устройства разных типов работают одновременно.', {
        x: 0.65, y: 6.74, w: W - 1.3, h: 0.34, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 10.5, italic: true, color: MUTED,
    });
    s.addNotes('Мультиплатформенность — не маркетинг: приложение собрано из общего кода, различия только там, где их требует платформа (правила Эвотор.Маркета, киоск на Windows, экранное закрепление на Android).');
}

/* ═══════════════ 11. Эвотор ═══════════════ */
{
    const s = slide('Смарт-терминал Эвотор 7.3', 'Приложение публикуется в Эвотор.Маркете — установка в один клик');
    s.addImage({ path: `${MKT}/screen-1-scanner.jpg`, x: 6.75, y: 1.95, w: 5.9, h: 3.46, shadow: shadow() });
    const items = [
        ['Установка из магазина', 'Приложение ставится на терминал из Эвотор.Маркета и обновляется оттуда же — без флешек и ручных APK.'],
        ['Штатный сканер терминала', 'Код от внешнего QR-сканера принимается через драйвер терминала. Сканер в режиме клавиатуры тоже поддержан.'],
        ['Касса остаётся доступной', 'Приложение не захватывает экран: кассир в любой момент возвращается в меню Эвотора.'],
        ['Фискальная часть не задействована', 'Это учёт прохода, а не продажа: чеки не печатаются, касса не затрагивается.'],
    ];
    let y = 1.95;
    items.forEach(([head, body]) => {
        s.addText(head, {
            x: 0.65, y, w: 5.8, h: 0.32, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: 0.65, y: y + 0.33, w: 5.9, h: 0.7, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, color: TEXT, lineSpacingMultiple: 1.15,
        });
        y += 1.15;
    });
    s.addText('Экран 7″, 1024×600 · Evotor OS 4 · 5 USB-портов', {
        x: 6.75, y: 5.55, w: 5.9, h: 0.45, isTextBox: true, margin: 0,
        align: 'center', fontFace: B, fontSize: 11.5, color: MUTED,
    });
}

/* ═══════════════ 12. Карты-пропуска ═══════════════ */
{
    const s = slide('Карты-пропуска сотрудников', 'QR-код выпускается в системе и живёт по своим правилам');
    const items = [
        ['Печать на бумаге', 'Карточка печатается из веб-интерфейса поштучно или сразу пачкой на всю организацию. Формат — под бейдж.'],
        ['Файл на телефон', 'Персональная карточка выгружается файлом и отправляется человеку в мессенджер: код показывают с экрана телефона.'],
        ['Срок действия и блокировка', 'У карты есть статус: активна, просрочена, заблокирована. Потерянная карта отключается одним действием.'],
        ['Код не меняется при правке', 'Исправление ФИО или организации не перевыпускает QR — карта на руках продолжает работать.'],
    ];
    let x = 0.65, y = 2.0;
    items.forEach(([head, body], i) => {
        if (i === 2) { x = 0.65; y = 4.3; }
        card(s, x, y, 6.0, 2.0, i === 3 ? 'E3F5F1' : ICE);
        s.addText(head, {
            x: x + 0.32, y: y + 0.28, w: 5.3, h: 0.35, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: x + 0.32, y: y + 0.68, w: 5.4, h: 1.1, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, color: TEXT, lineSpacingMultiple: 1.15,
        });
        x += 6.3;
    });
    s.addNotes('Карточки супер-администраторов обычным администраторам не показываются — защита от компрометации кода высшего уровня доступа.');
}

/* ═══════════════ 13. Веб-интерфейс ═══════════════ */
{
    const s = slide('Серверная часть: веб-интерфейс', 'Работа в браузере — устанавливать на компьютеры нечего');
    const cols = [
        ['Сотрудники', ['Справочник и поиск', 'Организации и подразделения', 'Категория и цена питания', 'Выпуск и печать карт']],
        ['Точки и расписание', ['Точки раздачи', 'Окна приёмов пищи', 'Часовой пояс точки', 'Операторы точек']],
        ['Отчёты', ['Журнал проходов', 'Сводка по сотрудникам', 'Сухпай и выездное', 'Выгрузка в Excel']],
        ['Обслуживание', ['Массовая проводка', 'Ручной пропуск', 'Поиск дублей', 'Журнал действий']],
    ];
    let x = 0.65;
    cols.forEach(([head, list]) => {
        card(s, x, 1.95, 2.9, 3.0, ICE);
        s.addText(head, {
            x: x + 0.28, y: 2.18, w: 2.45, h: 0.58, isTextBox: true, margin: 0,
            fontFace: H, fontSize: 17, bold: true, color: NAVY,
        });
        s.addText(list.map((t, j) => ({ text: t, options: { bullet: true, breakLine: j < list.length - 1 } })), {
            x: x + 0.28, y: 2.78, w: 2.45, h: 2.0, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12, color: TEXT, paraSpaceAfter: 7,
        });
        x += 3.05;
    });
    card(s, 0.65, 5.15, W - 1.3, 1.5, 'F3F7FB');
    s.addText('Что нужно для работы сервера', {
        x: 1.0, y: 5.38, w: 11, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 14.5, bold: true, color: NAVY,
    });
    s.addText('Обычный хостинг с PHP и MySQL — отдельный сервер и системный администратор на площадке не требуются. Интерфейс открывается с компьютера и с телефона: адаптирован под мобильный экран.', {
        x: 1.0, y: 5.73, w: 11.3, h: 0.8, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13, color: TEXT, lineSpacingMultiple: 1.15,
    });
}

/* ═══════════════ 14. Отчёты ═══════════════ */
{
    const s = slide('Отчётность', 'То, ради чего всё и делается: достоверный счёт по каждой организации');
    s.addImage({ path: `${SCR}/web-report.png`, x: 0.65, y: 1.9, w: 12.0, h: 3.52, shadow: shadow() });
    const items = [
        ['По сотруднику', 'Завтраки, обеды, ужины, ночное, всего приёмов и сколько дней человек появлялся в столовой.'],
        ['По организации', 'Итоги по каждому подрядчику и общий итог — основание для счёта.'],
        ['Фильтры', 'Период, точка, тип питания, способ проводки, выбранные организации, поиск по ФИО.'],
    ];
    let x = 0.65;
    items.forEach(([head, body]) => {
        s.addText(head, {
            x, y: 5.6, w: 3.85, h: 0.32, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14, bold: true, color: NAVY,
        });
        s.addText(body, {
            x, y: 5.93, w: 3.85, h: 0.75, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 11.5, color: TEXT, lineSpacingMultiple: 1.15,
        });
        x += 4.1;
    });
    s.addText('Данные на снимке экрана демонстрационные.', {
        x: 0.65, y: 6.75, w: 6, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 10.5, italic: true, color: MUTED,
    });
}

/* ═══════════════ 15. Excel ═══════════════ */
{
    const s = slide('Выгрузки и сухой паёк', 'Учитывается не только то, что съедено в зале');
    const left = [
        ['Выгрузка в Excel', 'Журнал проходов и сводка по сотрудникам выгружаются файлом с теми же фильтрами, что на экране: что видно — то и выгружается.'],
        ['Сухой паёк и выездное питание', 'Выдача сухпая и питание на выезде отмечаются отдельно и попадают в отчёты вместе с обычными проходами.'],
    ];
    const right = [
        ['Массовая проводка', 'Когда смена питалась без терминала (авария, выезд), проходы проводятся списком — с отметкой о способе, чтобы это было видно в отчёте.'],
        ['Ручной пропуск', 'Если карта не читается, оператор находит человека в списке и проводит вручную. Запись помечается как ручная.'],
    ];
    let y = 1.95;
    left.forEach(([head, body]) => {
        card(s, 0.65, y, 6.0, 2.1, ICE);
        s.addText(head, { x: 0.97, y: y + 0.28, w: 5.3, h: 0.35, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15.5, bold: true, color: NAVY });
        s.addText(body, { x: 0.97, y: y + 0.7, w: 5.4, h: 1.2, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, color: TEXT, lineSpacingMultiple: 1.15 });
        y += 2.3;
    });
    y = 1.95;
    right.forEach(([head, body]) => {
        card(s, 6.95, y, 6.0, 2.1, ICE);
        s.addText(head, { x: 7.27, y: y + 0.28, w: 5.3, h: 0.35, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15.5, bold: true, color: NAVY });
        s.addText(body, { x: 7.27, y: y + 0.7, w: 5.4, h: 1.2, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, color: TEXT, lineSpacingMultiple: 1.15 });
        y += 2.3;
    });
    s.addText('Каждая запись хранит способ проводки: скан, ручной пропуск, массовая проводка или синхронизация с точки. В отчёте это отдельный фильтр.', {
        x: 0.65, y: 6.5, w: W - 1.3, h: 0.45, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13, italic: true, color: MUTED,
    });
}

/* ═══════════════ 16. Роли ═══════════════ */
{
    const s = slide('Роли и разграничение доступа', 'Каждый видит ровно то, что нужно для его работы');
    const roles = [
        ['Оператор', 'Работает на раздаче', ['Сканирование и ручной пропуск', 'Журнал своей точки', 'Список сотрудников для поиска']],
        ['Администратор', 'Ведёт площадку', ['Сотрудники и организации', 'Точки и расписание', 'Отчёты и выгрузки', 'Выпуск и печать карт']],
        ['Супер-администратор', 'Отвечает за систему', ['Все площадки и настройки', 'Обслуживание базы', 'Управление доступом', 'Журнал действий']],
    ];
    let x = 0.65;
    roles.forEach(([head, sub, list], i) => {
        card(s, x, 1.95, 3.9, 3.7, i === 2 ? 'E3F5F1' : ICE);
        s.addText(head, {
            x: x + 0.3, y: 2.25, w: 3.3, h: 0.4, isTextBox: true, margin: 0,
            fontFace: H, fontSize: 19, bold: true, color: NAVY,
        });
        s.addText(sub, {
            x: x + 0.3, y: 2.68, w: 3.3, h: 0.3, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, color: MUTED,
        });
        s.addText(list.map((t, j) => ({ text: t, options: { bullet: true, breakLine: j < list.length - 1 } })), {
            x: x + 0.3, y: 3.12, w: 3.35, h: 2.3, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, color: TEXT, paraSpaceAfter: 7,
        });
        x += 4.1;
    });
    s.addText('Вход по карте сотрудника: отдельный пароль оператору не нужен и не теряется. Действия администраторов пишутся в журнал.', {
        x: 0.65, y: 5.95, w: W - 1.3, h: 0.45, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13, color: MUTED,
    });
}

/* ═══════════════ 17. Центр управления ═══════════════ */
{
    const s = slide('Центр управления площадками', 'Когда площадок несколько — сводная картина в одном окне');
    s.addImage({ path: `${SCR}/web-admin.png`, x: 0.65, y: 1.9, w: 8.0, h: 5.0, shadow: shadow() });
    const items = [
        ['Все площадки сразу', 'Сотрудники, точки, питание за сегодня и версии приложений по каждому региону.'],
        ['Сводные отчёты', 'Отчёт по всем площадкам или по выбранным: галочками отмечаются нужные регионы.'],
        ['Единый пропуск', 'Руководитель заводится одной картой сразу на всех площадках.'],
        ['Новая площадка', 'Мастер разворачивает регион по образцу действующего.'],
    ];
    let y = 1.95;
    items.forEach(([head, body]) => {
        s.addText(head, {
            x: 8.95, y, w: 3.8, h: 0.34, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 13.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: 8.95, y: y + 0.34, w: 3.8, h: 0.8, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 11.5, color: TEXT, lineSpacingMultiple: 1.15,
        });
        y += 1.15;
    });
    s.addText('Данные на снимке экрана демонстрационные.', {
        x: 8.95, y: 6.6, w: 3.8, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 10.5, italic: true, color: MUTED,
    });
}

/* ═══════════════ 18. Наблюдение за точками ═══════════════ */
{
    const s = slide('Наблюдение за точками и поддержка', 'Проблему видно раньше, чем о ней сообщат с площадки');
    const items = [
        ['Кто на связи', 'Видно, какие точки сейчас работают, когда последний раз выходили на связь и какая на них версия приложения.'],
        ['Очередь записей', 'Если точка накопила непереданные записи, это заметно до того, как расхождение попадёт в отчёт.'],
        ['Удалённые команды', 'Синхронизация, обновление и снятие блокировки экрана запускаются с сервера — без выезда на площадку.'],
        ['Обновления', 'Windows-версия обновляется сама ночью, планшеты — из приложения, Эвотор — через Эвотор.Маркет.'],
    ];
    let x = 0.65, y = 2.0;
    items.forEach(([head, body], i) => {
        if (i === 2) { x = 0.65; y = 4.3; }
        card(s, x, y, 6.0, 2.0, ICE);
        s.addText(head, {
            x: x + 0.32, y: y + 0.28, w: 5.3, h: 0.35, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: x + 0.32, y: y + 0.68, w: 5.4, h: 1.1, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12.5, color: TEXT, lineSpacingMultiple: 1.15,
        });
        x += 6.3;
    });
}

/* ═══════════════ 19. Достоверность данных ═══════════════ */
{
    const s = pres.addSlide();
    s.background = { color: NAVY_D };
    s.addText('Достоверность данных', {
        x: 0.65, y: 0.6, w: 11.5, h: 0.75, isTextBox: true, margin: 0,
        fontFace: H, fontSize: 32, bold: true, color: WHITE,
    });
    s.addText('Отчёт становится основанием для счёта только тогда, когда цифрам можно верить', {
        x: 0.65, y: 1.35, w: 11.5, h: 0.4, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 15, color: 'A8C0DA',
    });
    const items = [
        ['Время хранится в UTC', 'В базе всегда одно время, местное считается при показе — по часовому поясу точки. Перевод часов и разные регионы не смещают отчёт.'],
        ['Защита от дублей', 'Повторная отправка той же записи не создаёт вторую строку. Есть отдельный поиск дублей, попавших в базу разными путями.'],
        ['Видно способ проводки', 'Скан, ручной пропуск, массовая проводка или синхронизация — в отчёте это отдельная колонка и фильтр.'],
        ['Журнал действий', 'Кто и что менял в справочниках и настройках — записывается и доступно супер-администратору.'],
        ['Приведение к расписанию', 'Инструмент сверяет исторические записи с расписанием точки и приводит тип питания в соответствие — с предпросмотром до сохранения.'],
        ['Удаление под запретом', 'Сотрудника с историей питания удалить нельзя: иначе отчёт за прошлый месяц перестал бы читаться.'],
    ];
    let x = 0.65, y = 2.05;
    items.forEach(([head, body], i) => {
        if (i === 3) { x = 6.95; y = 2.05; }
        s.addShape(pres.ShapeType.roundRect, {
            x, y, w: 5.7, h: 1.5, rectRadius: 0.1,
            fill: { color: '0A2A52' }, line: { color: '17406F' },
        });
        s.addText(head, {
            x: x + 0.3, y: y + 0.2, w: 5.0, h: 0.32, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14, bold: true, color: TEAL,
        });
        s.addText(body, {
            x: x + 0.3, y: y + 0.55, w: 5.15, h: 0.85, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 11.5, color: 'D5E3F2', lineSpacingMultiple: 1.15,
        });
        y += 1.65;
    });
}

/* ═══════════════ 20. Персональные данные ═══════════════ */
{
    const s = slide('Персональные данные', 'Что система хранит и как ограничен доступ');
    card(s, 0.65, 1.95, 6.0, 2.3, ICE);
    s.addText('Что хранится', {
        x: 0.97, y: 2.2, w: 5.3, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 15.5, bold: true, color: NAVY,
    });
    s.addText('ФИО, организация, подразделение и должность, категория питания, QR-код карты и записи о проходах: дата, время, точка, тип питания.', {
        x: 0.97, y: 2.6, w: 5.4, h: 1.5, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 12, color: TEXT, lineSpacingMultiple: 1.15,
    });

    card(s, 6.95, 1.95, 6.0, 2.3, 'E3F5F1');
    s.addText('Чего в системе нет', {
        x: 7.27, y: 2.2, w: 5.3, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 15.5, bold: true, color: NAVY,
    });
    s.addText('Биометрии: фотографий, отпечатков и шаблонов лиц в системе нет вообще. Камера используется только для распознавания QR-кода на лету — изображение никуда не сохраняется.', {
        x: 7.27, y: 2.6, w: 5.4, h: 1.5, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 12, color: TEXT, lineSpacingMultiple: 1.15,
    });

    const rules = [
        ['Доступ по ролям', 'Оператору не показываются дата рождения, цена питания и категория: для проведения человека они не нужны.'],
        ['Карты высшего доступа', 'Администратор не может открыть и распечатать карточку супер-администратора.'],
        ['Данные остаются у заказчика', 'Система разворачивается на вашем хостинге. Данные никуда не передаются: внешних сервисов в контуре нет.'],
    ];
    let x = 0.65;
    rules.forEach(([head, body]) => {
        card(s, x, 4.5, 3.9, 2.0, ICE);
        s.addText(head, {
            x: x + 0.3, y: 4.72, w: 3.35, h: 0.44, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: x + 0.3, y: 5.16, w: 3.35, h: 1.25, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 11.5, color: TEXT, lineSpacingMultiple: 1.15,
        });
        x += 4.1;
    });
    s.addNotes('Организационная часть 152-ФЗ — согласия, политика, назначение ответственного — на стороне заказчика; техническая часть по выгрузке и удалению данных по запросу субъекта прорабатывается отдельно.');
}

/* ═══════════════ 21. Внедрение ═══════════════ */
{
    const s = slide('Как проходит внедрение', 'От первой встречи до рабочей столовой');
    const steps = [
        ['Развёртывание', 'Сервер на вашем хостинге, домен площадки, первичная настройка.'],
        ['Загрузка данных', 'Сотрудники загружаются списком из вашей таблицы: ФИО, организация, подразделение, должность.'],
        ['Карты', 'Выпуск QR-кодов, печать карточек или рассылка файлов сотрудникам.'],
        ['Точки и расписание', 'Точки раздачи, окна приёмов пищи, часовой пояс, операторы.'],
        ['Устройства', 'Установка приложения на терминалы, подключение сканеров, проверка на месте.'],
        ['Обучение и запуск', 'Оператору хватает получаса: подносить карту и читать цвет ответа.'],
    ];
    let x = 0.65, y = 2.0;
    steps.forEach(([head, body], i) => {
        if (i === 3) { x = 0.65; y = 4.5; }
        card(s, x, y, 3.9, 2.1, i < 3 ? ICE : 'E3F5F1');
        badge(s, x + 0.3, y + 0.25, 0.46, String(i + 1), i < 3 ? NAVY : TEAL);
        s.addText(head, {
            x: x + 0.88, y: y + 0.3, w: 2.8, h: 0.35, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 14.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: x + 0.3, y: y + 0.88, w: 3.35, h: 1.15, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 11.5, color: TEXT, lineSpacingMultiple: 1.15,
        });
        x += 4.1;
    });
}

/* ═══════════════ 22. Требования ═══════════════ */
{
    const s = slide('Технические требования', 'Ничего экзотического — всё работает на типовом оборудовании');
    const groups = [
        ['Сервер площадки', ['Хостинг с PHP 8 и MySQL', 'Домен и сертификат HTTPS', 'Отдельный сервер не нужен']],
        ['Точка раздачи', ['Эвотор 7.3, планшет Android или ПК с Windows', 'QR-сканер в USB', 'Интернет желателен, но не обязателен']],
        ['Рабочее место администратора', ['Любой современный браузер', 'Подходит и телефон', 'Установка ПО не требуется']],
        ['Карты сотрудников', ['Печать на обычном принтере', 'Или файл на телефоне сотрудника', 'Расходники — бумага и бейдж']],
    ];
    let x = 0.65;
    groups.forEach(([head, list], i) => {
        card(s, x, 1.95, 2.9, 3.6, i === 1 ? 'E3F5F1' : ICE);
        s.addText(head, {
            x: x + 0.28, y: 2.2, w: 2.45, h: 0.7, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15, bold: true, color: NAVY, lineSpacingMultiple: 1.05,
        });
        s.addText(list.map((t, j) => ({ text: t, options: { bullet: true, breakLine: j < list.length - 1 } })), {
            x: x + 0.28, y: 3.0, w: 2.45, h: 2.35, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 12, color: TEXT, paraSpaceAfter: 8,
        });
        x += 3.05;
    });
    card(s, 0.65, 5.8, W - 1.3, 1.1, 'F3F7FB');
    s.addText('Система не привязана к оборудованию одного производителя: точки раздачи на разных устройствах работают в одной площадке одновременно.', {
        x: 1.0, y: 6.05, w: 11.3, h: 0.6, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 13, color: TEXT,
    });
}

/* ═══════════════ 23. Результат ═══════════════ */
{
    const s = slide('Что получает заказчик', 'Три изменения, которые видны с первого месяца');
    const items = [
        ['Счёт подрядчику — из системы', 'Сколько завтраков, обедов, ужинов и ночных приёмов получила каждая организация и каждый человек. Выгрузка в Excel, а не рукописная ведомость.'],
        ['Раздача без очереди', 'Оператор не ищет фамилию: карта, звук, цвет, следующий. Ошибку при вводе сделать негде.'],
        ['Контроль вместо доверия', 'Повторное питание отклоняется на месте, проходы вне графика видны отдельно, все действия администраторов записаны.'],
        ['Свобода в выборе оборудования', 'Эвотор, планшет или компьютер — на выбор и одновременно на разных точках. Веб-интерфейс открывается в любом браузере, включая телефон.'],
    ];
    let y = 1.82;
    items.forEach(([head, body], i) => {
        card(s, 0.65, y, W - 1.3, 1.15, i === 0 ? 'E3F5F1' : ICE);
        badge(s, 1.0, y + 0.32, 0.52, String(i + 1), i === 0 ? TEAL : NAVY);
        s.addText(head, {
            x: 1.8, y: y + 0.18, w: 10.5, h: 0.36, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 15.5, bold: true, color: NAVY,
        });
        s.addText(body, {
            x: 1.8, y: y + 0.56, w: 10.6, h: 0.52, isTextBox: true, margin: 0,
            fontFace: B, fontSize: 11.5, color: TEXT, lineSpacingMultiple: 1.12,
        });
        y += 1.28;
    });
}

/* ═══════════════ 24. Контакты ═══════════════ */
{
    const s = pres.addSlide();
    s.background = { color: NAVY_D };
    s.addImage({ path: `${SCR}/logo-white.png`, x: 0.9, y: 1.1, w: 2.6, h: 1.01 });
    s.addText('СеверФудс', {
        x: 0.9, y: 2.5, w: 6.3, h: 0.9, isTextBox: true, margin: 0,
        fontFace: H, fontSize: 44, bold: true, color: WHITE,
    });
    s.addText('Система учёта питания сотрудников', {
        x: 0.9, y: 3.4, w: 6.3, h: 0.5, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 18, color: 'A8C0DA',
    });
    s.addShape(pres.ShapeType.roundRect, {
        x: 7.6, y: 2.4, w: 5.0, h: 2.7, rectRadius: 0.14,
        fill: { color: '0A2A52' }, line: { color: '17406F' },
    });
    s.addText('Производитель ПО', {
        x: 7.95, y: 2.65, w: 4.3, h: 0.32, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 12, color: TEAL,
    });
    s.addText('ООО «Север»', {
        x: 7.95, y: 3.0, w: 4.3, h: 0.5, isTextBox: true, margin: 0,
        fontFace: H, fontSize: 26, bold: true, color: WHITE,
    });
    s.addText([
        { text: 'Телефон: ‹укажите›', options: { breakLine: true } },
        { text: 'Почта: ‹укажите›',   options: { breakLine: true } },
        { text: 'Сайт: ‹укажите›',    options: {} },
    ], {
        x: 7.95, y: 3.65, w: 4.3, h: 1.2, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 14, color: 'D5E3F2', lineSpacingMultiple: 1.35,
    });
    s.addText('Версия системы 1.7.7 · сентябрь 2026', {
        x: 0.9, y: 6.4, w: 8, h: 0.35, isTextBox: true, margin: 0,
        fontFace: B, fontSize: 12, color: '7E9BBD',
    });
    s.addNotes('Заполнить контакты ООО «Север» перед показом.');
}

pres.writeFile({ fileName: path.join(__dirname, 'SeverFoods-presentation.pptx') })
    .then(f => console.log('готово:', f));
