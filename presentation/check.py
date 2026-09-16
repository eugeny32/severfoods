"""
Проверка презентации без рендера: LibreOffice в этой среде не запускается
(не конвертирует даже текстовый файл), поэтому смотреть слайды глазами нечем.
Проверяем то, что можно проверить точно:

1. Всё ли внутри слайда и держит ли отступ от края.
2. Влезает ли текст в свою рамку — ширина строк считается по метрикам шрифта
   с переносом по словам.
3. Не накладываются ли текстовые блоки друг на друга и не заезжают ли они
   на картинки.
4. Не растянуты ли картинки: пропорции в рамке сверяются с пропорциями файла.

Шрифт для замера берётся из системных: он шире Calibri, поэтому оценка
консервативная — скорее предупредит лишний раз, чем пропустит обрезанный текст.
"""
from pptx import Presentation
from pptx.util import Emu
from PIL import ImageFont
import glob
import sys

EMU_IN = 914400.0
SLIDE_W, SLIDE_H = 13.333, 7.5
MARGIN = 0.4          # минимальный отступ от края слайда, дюймы

FONT_PATHS = sorted(glob.glob('/usr/share/fonts/**/DejaVuSans.ttf', recursive=True)) \
          or sorted(glob.glob('/usr/share/fonts/**/*.ttf', recursive=True))
FONT_FILE = FONT_PATHS[0]

_font_cache = {}
def font(px):
    if px not in _font_cache:
        _font_cache[px] = ImageFont.truetype(FONT_FILE, px)
    return _font_cache[px]


def text_runs(shape):
    """Текст и максимальный кегль внутри фигуры."""
    if not shape.has_text_frame:
        return '', 0
    txt, size = [], 0
    for p in shape.text_frame.paragraphs:
        line = ''.join(r.text for r in p.runs)
        txt.append(line)
        for r in p.runs:
            if r.font.size:
                size = max(size, r.font.size.pt)
    return '\n'.join(txt), size or 12


def wrapped_height(text, size_pt, width_in, line_mult=1.2):
    """Высота текста после переноса по словам, в дюймах."""
    px = max(8, int(size_pt * 4))          # крупный кегль для точности метрик
    f = font(px)
    scale = size_pt / 72.0 / (px / 72.0)   # перевод из пикселей шрифта в дюймы
    max_px = width_in * 72.0 / scale / 72.0 * px
    lines = 0
    for para in text.split('\n'):
        if not para.strip():
            lines += 1
            continue
        cur = ''
        n = 1
        for word in para.split():
            trial = (cur + ' ' + word).strip()
            if f.getlength(trial) <= max_px or not cur:
                cur = trial
            else:
                n += 1
                cur = word
        lines += n
    return lines * size_pt * line_mult / 72.0


prs = Presentation(sys.argv[1] if len(sys.argv) > 1
                   else 'SeverFoods-presentation.pptx')
problems = []

for idx, slide in enumerate(prs.slides, 1):
    boxes, pics = [], []
    for sh in slide.shapes:
        try:
            x, y = sh.left / EMU_IN, sh.top / EMU_IN
            w, h = sh.width / EMU_IN, sh.height / EMU_IN
        except TypeError:
            continue

        if sh.shape_type == 13:                       # картинка
            pics.append((x, y, w, h))
            try:
                from PIL import Image as _I
                import io as _io
                im = _I.open(_io.BytesIO(sh.image.blob))
                nat, box = im.width / im.height, w / h
                if abs(nat - box) / nat > 0.04:
                    problems.append(f'слайд {idx}: картинка растянута '
                                    f'(в файле {nat:.2f}, в рамке {box:.2f})')
            except Exception:
                pass

        # 1. Границы слайда
        if x < -0.01 or y < -0.01 or x + w > SLIDE_W + 0.01 or y + h > SLIDE_H + 0.01:
            problems.append(f'слайд {idx}: «{sh.shape_type}» выходит за слайд '
                            f'({x:.2f},{y:.2f} {w:.2f}×{h:.2f})')
        elif x < MARGIN - 0.01 or y < MARGIN - 0.01 \
                or x + w > SLIDE_W - MARGIN + 0.01 or y + h > SLIDE_H - MARGIN + 0.01:
            # фоновые карточки и изображения могут подходить ближе — следим только за текстом
            if sh.has_text_frame and sh.text_frame.text.strip():
                problems.append(f'слайд {idx}: текст ближе {MARGIN}" к краю — '
                                f'«{sh.text_frame.text[:34]}…»')

        # 2. Вместимость текста
        text, size = text_runs(sh)
        if text.strip():
            need = wrapped_height(text, size, w - 0.06)
            if need > h + 0.04:
                problems.append(f'слайд {idx}: текст не влезает в рамку '
                                f'({need:.2f}" > {h:.2f}") — «{text[:44]}…»')
            boxes.append((x, y, w, h, text[:30]))

    # 3. Наложение текстовых блоков
    for i in range(len(boxes)):
        for j in range(i + 1, len(boxes)):
            ax, ay, aw, ah, at = boxes[i]
            bx, by, bw, bh, bt = boxes[j]
            ox = min(ax + aw, bx + bw) - max(ax, bx)
            oy = min(ay + ah, by + bh) - max(ay, by)
            if ox > 0.05 and oy > 0.05:
                problems.append(f'слайд {idx}: тексты накладываются '
                                f'({ox:.2f}"×{oy:.2f}") — «{at}…» и «{bt}…»')

    # 4. Текст поверх картинки
    for (ax, ay, aw, ah, at) in boxes:
        for (px_, py_, pw, ph) in pics:
            ox = min(ax + aw, px_ + pw) - max(ax, px_)
            oy = min(ay + ah, py_ + ph) - max(ay, py_)
            if ox > 0.08 and oy > 0.08:
                problems.append(f'слайд {idx}: текст заезжает на картинку '
                                f'({ox:.2f}"×{oy:.2f}") — «{at}…»')

print(f'Слайдов: {len(prs.slides.__iter__.__self__._sldIdLst)}')
print(f'Шрифт для замера: {FONT_FILE}\n')
if problems:
    print(f'Замечаний: {len(problems)}')
    for p in problems:
        print(' •', p)
else:
    print('Замечаний нет')
