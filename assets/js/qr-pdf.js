/**
 * Персональный PDF с QR-кодом — для отправки сотруднику в мессенджер.
 *
 * Работает на страницах печати (print_qr.php, print_all_qr.php) и берёт
 * данные из уже отрисованных карточек: права на них проверены на сервере,
 * поэтому никакого нового эндпоинта и никакой новой поверхности доступа
 * здесь не появляется.
 *
 * Почему карточка рисуется на canvas, а не пишется в PDF текстом:
 * встроенные шрифты jsPDF кириллицу не поддерживают — ФИО вышло бы мусором.
 * Встраивать TTF пришлось бы вместе с ~300 КБ веса и вопросом лицензии, а
 * canvas рисует штатными шрифтами браузера и заодно даёт свободно сверстать
 * страницу под экран телефона.
 */
(function (global) {
    'use strict';

    // Страница под экран телефона: узкая, вертикальная, пропорции близки к 9:16.
    const PAGE_W_MM = 90;
    const PAGE_H_MM = 160;
    const SCALE     = 4;   // пикселей на мм — запас, чтобы QR не «замылился»
    const CANVAS_W  = PAGE_W_MM * SCALE;
    const CANVAS_H  = PAGE_H_MM * SCALE;

    const FONT = "'Onest', 'Segoe UI', system-ui, sans-serif";

    function esc(v) { return (v === null || v === undefined) ? '' : String(v).trim(); }

    /** Перенос длинного текста по словам; возвращает массив строк. */
    function wrap(ctx, text, maxWidth, maxLines) {
        const words = esc(text).split(/\s+/).filter(Boolean);
        const lines = [];
        let line = '';
        for (const w of words) {
            const probe = line ? line + ' ' + w : w;
            if (ctx.measureText(probe).width <= maxWidth || !line) {
                line = probe;
            } else {
                lines.push(line);
                line = w;
                if (maxLines && lines.length === maxLines) break;
            }
        }
        if (line && (!maxLines || lines.length < maxLines)) lines.push(line);
        if (maxLines && lines.length === maxLines && words.length) {
            // последняя строка могла обрезаться — помечаем многоточием
            const last = lines[maxLines - 1];
            if (ctx.measureText(last).width > maxWidth) lines[maxLines - 1] = last.slice(0, -1) + '…';
        }
        return lines;
    }

    /** Рисует страницу карточки на новом canvas и возвращает его. */
    function cardToCanvas(cardEl) {
        const d       = cardEl.dataset;
        const qrCanvas = cardEl.querySelector('canvas[data-qr]');
        const accent   = esc(d.empColor) || '#003366';

        const cv  = document.createElement('canvas');
        cv.width  = CANVAS_W;
        cv.height = CANVAS_H;
        const ctx = cv.getContext('2d');

        // фон
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, CANVAS_W, CANVAS_H);

        // шапка
        const headH = 22 * SCALE;
        ctx.fillStyle = accent;
        ctx.fillRect(0, 0, CANVAS_W, headH);
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.font = '700 ' + (7 * SCALE) + 'px ' + FONT;
        ctx.fillText('СЕВЕР', CANVAS_W / 2, 10 * SCALE);
        ctx.font = '400 ' + (3.4 * SCALE) + 'px ' + FONT;
        ctx.fillText('Система контроля питания', CANVAS_W / 2, 16 * SCALE);

        // QR — максимально крупно, с белым полем вокруг (нужно сканеру)
        const qrBox = 64 * SCALE;
        const qrX   = (CANVAS_W - qrBox) / 2;
        const qrY   = headH + 6 * SCALE;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(qrX, qrY, qrBox, qrBox);
        if (qrCanvas) {
            ctx.imageSmoothingEnabled = false; // резкие модули QR, без размытия
            ctx.drawImage(qrCanvas, qrX, qrY, qrBox, qrBox);
            ctx.imageSmoothingEnabled = true;
        }

        // текстовый блок
        let y = qrY + qrBox + 9 * SCALE;
        ctx.textAlign = 'center';

        ctx.fillStyle = '#0f172a';
        ctx.font = '700 ' + (5.2 * SCALE) + 'px ' + FONT;
        for (const line of wrap(ctx, d.empName, CANVAS_W - 10 * SCALE, 3)) {
            ctx.fillText(line, CANVAS_W / 2, y);
            y += 6.4 * SCALE;
        }

        y += 1.5 * SCALE;
        ctx.fillStyle = '#475569';
        ctx.font = '400 ' + (3.9 * SCALE) + 'px ' + FONT;
        for (const val of [d.empOrg, d.empDep, d.empPos]) {
            if (!esc(val)) continue;
            for (const line of wrap(ctx, val, CANVAS_W - 12 * SCALE, 2)) {
                ctx.fillText(line, CANVAS_W / 2, y);
                y += 5 * SCALE;
            }
        }

        // статус и срок — внизу страницы, фиксировано
        const valid = d.empValid === '1';
        ctx.font = '700 ' + (3.8 * SCALE) + 'px ' + FONT;
        ctx.fillStyle = valid ? '#166534' : '#b91c1c';
        ctx.fillText(valid ? '✓ Действителен' : '✗ Недействителен', CANVAS_W / 2, CANVAS_H - 12 * SCALE);

        if (esc(d.empExpires)) {
            ctx.font = '400 ' + (3.3 * SCALE) + 'px ' + FONT;
            ctx.fillStyle = '#94a3b8';
            ctx.fillText('до ' + esc(d.empExpires), CANVAS_W / 2, CANVAS_H - 6.5 * SCALE);
        }

        return cv;
    }

    /** Имя файла по ФИО. Пустое ФИО — запасной вариант по коду. */
    function pdfFileName(cardEl) {
        const d = cardEl.dataset;
        let name = esc(d.empName)
            // символы, запрещённые в именах файлов Windows
            .replace(/[\\/:*?"<>|]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 120)
            // Windows молча отбрасывает точки и пробелы в конце имени: без
            // этой чистки «Иванов И.И.» дало бы «Иванов И.И..pdf», а сам файл
            // сохранился бы под другим именем.
            .replace(/[.\s]+$/, '');
        if (!name) {
            const qr = cardEl.querySelector('canvas[data-qr]');
            name = (qr && qr.dataset.qr) ? qr.dataset.qr : 'QR';
        }
        return name + '.pdf';
    }

    function makeDoc(cardEl) {
        const { jsPDF } = global.jspdf;
        const doc = new jsPDF({ unit: 'mm', format: [PAGE_W_MM, PAGE_H_MM], orientation: 'portrait' });
        const img = cardToCanvas(cardEl).toDataURL('image/jpeg', 0.92);
        doc.addImage(img, 'JPEG', 0, 0, PAGE_W_MM, PAGE_H_MM);
        return doc;
    }

    /** Скачивает PDF одного сотрудника. */
    function downloadCardPdf(cardEl) {
        makeDoc(cardEl).save(pdfFileName(cardEl));
    }

    /**
     * Архив с отдельным PDF на каждого сотрудника.
     * Однофамильцы получают суффикс " (2)" — иначе файлы в архиве молча
     * перезаписали бы друг друга, и часть людей осталась бы без кода.
     */
    async function downloadCardsZip(cards, btnEl) {
        if (!cards.length) return;

        const zip   = new global.JSZip();
        const used  = Object.create(null);
        const label = btnEl ? btnEl.innerHTML : null;

        try {
        for (let i = 0; i < cards.length; i++) {
            let name = pdfFileName(cards[i]);
            if (used[name]) {
                const base = name.replace(/\.pdf$/, '');
                name = base + ' (' + (++used[name]) + ').pdf';
            } else {
                used[name] = 1;
            }
            zip.file(name, makeDoc(cards[i]).output('blob'));

            if (btnEl) btnEl.textContent = 'Готовлю… ' + (i + 1) + ' из ' + cards.length;
            // отдаём управление браузеру, иначе вкладка «замерзает» на большой партии
            if (i % 5 === 4) await new Promise(r => setTimeout(r, 0));
        }

        if (btnEl) btnEl.textContent = 'Упаковываю архив…';
        const blob = await zip.generateAsync({ type: 'blob' });

        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'QR-коды (' + cards.length + ').zip';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        } finally {
            // Подпись восстанавливается в любом случае: при ошибке посреди
            // партии на кнопке иначе навсегда осталось бы «Готовлю… N из M».
            if (btnEl && label !== null) btnEl.innerHTML = label;
        }
    }

    global.QrPdf = { downloadCardPdf, downloadCardsZip, pdfFileName, cardToCanvas };
})(window);
