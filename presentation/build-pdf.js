/**
 * PDF из веб-версии презентации.
 *
 * Печатаем index.html браузером: у страницы есть стили для печати, где каждый
 * раздел — отдельная альбомная страница. LibreOffice для этого не годится: в
 * среде сборки он не запускается вовсе.
 *
 * Запуск: node build-pdf.js   (нужен playwright: npm install playwright)
 */
const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const src = 'file://' + path.join(__dirname, 'index.html');
  const out = path.join(__dirname, 'SeverFoods-presentation.pdf');

  const browser = await chromium.launch({
    // В песочнице системный Chromium лежит отдельно от пакета playwright.
    executablePath: process.env.CHROMIUM_PATH || undefined,
  });
  const page = await browser.newPage();
  await page.goto(src, { waitUntil: 'networkidle' });

  // Без этого текст может лечь запасным шрифтом: веб-шрифты грузятся с сети.
  const fontsOk = await page.evaluate(async () => {
    await document.fonts.ready;
    return document.fonts.check('700 40px Onest');
  });
  if (!fontsOk) console.warn('Внимание: шрифт Onest не загрузился, PDF будет набран запасным.');

  await page.emulateMedia({ media: 'print' });
  await page.pdf({
    path: out, format: 'A4', landscape: true, printBackground: true,
    margin: { top: '9mm', bottom: '9mm', left: '9mm', right: '9mm' },
  });
  await browser.close();
  console.log('готово:', out);
})();
