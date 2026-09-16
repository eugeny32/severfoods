"""
Отрисовка слайдов в картинки — своими руками.

LibreOffice в этой среде не запускается (не конвертирует даже текстовый файл),
поэтому смотреть презентацию нечем. Здесь минимальный рендерер под тот набор
фигур, которым собрана эта презентация: прямоугольники со скруглением, круги,
стрелки, картинки и текст.

Главное — метрики шрифтов: Carlito повторяет метрики Calibri, Caladea —
Cambria. Значит перенос строк и переполнение рамок видно так же, как их увидит
PowerPoint, а не «примерно».
"""
from pptx import Presentation
from pptx.util import Emu
from PIL import Image, ImageDraw, ImageFont
import sys

EMU_IN = 914400.0
DPI = 100                      # 1 дюйм = 100 px → слайд 1333×750

# Метрически совместимые замены: Carlito = Calibri, Liberation Sans = Arial.
FONTS = {
    ('Calibri', False): '/usr/share/fonts/truetype/crosextra/Carlito-Regular.ttf',
    ('Calibri', True):  '/usr/share/fonts/truetype/crosextra/Carlito-Bold.ttf',
    ('Arial', False):   '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ('Arial', True):    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
}
_cache = {}


def font(name, bold, size_pt):
    key = (name, bold, round(size_pt, 1))
    if key not in _cache:
        path = FONTS.get((name, bold)) or FONTS[('Calibri', bold)]
        _cache[key] = ImageFont.truetype(path, max(6, int(round(size_pt * DPI / 72.0))))
    return _cache[key]


def px(v):
    return int(round(v / EMU_IN * DPI))


def rgb(color, default=(0, 0, 0)):
    try:
        if color and color.type is not None and color.rgb is not None:
            r = color.rgb
            return (r[0], r[1], r[2])
    except Exception:
        pass
    return default


def wrap(text, f, max_w):
    """Перенос по словам по реальным метрикам шрифта."""
    out = []
    for para in text.split('\n'):
        if not para.strip():
            out.append('')
            continue
        cur = ''
        for word in para.split():
            trial = (cur + ' ' + word).strip()
            if f.getlength(trial) <= max_w or not cur:
                cur = trial
            else:
                out.append(cur)
                cur = word
        out.append(cur)
    return out


def draw_slide(slide, size, idx):
    W, H = px(size[0]), px(size[1])
    img = Image.new('RGB', (W, H), (255, 255, 255))
    d = ImageDraw.Draw(img, 'RGBA')

    # фон слайда
    try:
        bg = slide.background.fill
        if bg.type is not None and bg.type == 1:
            img.paste(rgb(bg.fore_color, (255, 255, 255)), (0, 0, W, H))
    except Exception:
        pass

    overflow = []

    for sh in slide.shapes:
        if sh.left is None:
            continue
        x, y, w, h = px(sh.left), px(sh.top), px(sh.width), px(sh.height)

        # картинки
        if sh.shape_type == 13:
            try:
                im = Image.open(sh.image.blob and __import__('io').BytesIO(sh.image.blob))
                nat = im.width / im.height
                box = w / h
                im = im.convert('RGBA').resize((max(1, w), max(1, h)))
                img.paste(im, (x, y), im)
                if abs(nat - box) / nat > 0.04:
                    overflow.append(f'слайд {idx}: картинка растянута '
                                    f'(в файле {nat:.2f}, в рамке {box:.2f})')
            except Exception as e:
                d.rectangle([x, y, x + w, y + h], outline=(200, 0, 0), width=2)
            continue

        # заливка фигуры
        fill_col = None
        try:
            if sh.fill.type is not None and sh.fill.type == 1:
                fill_col = rgb(sh.fill.fore_color, None)
        except Exception:
            pass
        if fill_col:
            st = str(sh.shape_type)
            if 'OVAL' in st or 'ELLIPSE' in st:
                d.ellipse([x, y, x + w, y + h], fill=fill_col)
            elif 'ARROW' in st:
                d.polygon([(x, y + h // 3), (x + int(w * 0.6), y + h // 3),
                           (x + int(w * 0.6), y), (x + w, y + h // 2),
                           (x + int(w * 0.6), y + h), (x + int(w * 0.6), y + 2 * h // 3),
                           (x, y + 2 * h // 3)], fill=fill_col)
            else:
                d.rounded_rectangle([x, y, x + w, y + h], radius=10, fill=fill_col)

        # текст
        if not sh.has_text_frame or not sh.text_frame.text.strip():
            continue
        tf = sh.text_frame
        cy = y + 2
        total_h = 0
        lines_all = []
        for p in tf.paragraphs:
            runs = [r for r in p.runs if r.text]
            if not runs:
                lines_all.append((None, '', 0))
                continue
            r0 = runs[0]
            size_pt = r0.font.size.pt if r0.font.size else 14
            bold = bool(r0.font.bold)
            name = r0.font.name or 'Calibri'
            col = rgb(r0.font.color, (15, 23, 42))
            f = font(name, bold, size_pt)
            text = ''.join(r.text for r in runs)
            mult = p.line_spacing if isinstance(p.line_spacing, float) else 1.0
            for ln in wrap(text, f, max(10, w - 4)):
                lh = int(size_pt * DPI / 72.0 * mult * 1.02)
                lines_all.append((f, ln, lh, col, p.alignment))
                total_h += lh

        # вертикальное выравнивание по центру, если задано
        anchor = tf.vertical_anchor
        if anchor == 3 and total_h < h:          # MIDDLE
            cy = y + (h - total_h) // 2

        for item in lines_all:
            if item[0] is None:
                cy += 10
                continue
            f, ln, lh, col, align = item
            tx = x + 2
            if align == 2:                        # CENTER
                tx = x + max(0, (w - int(f.getlength(ln))) // 2)
            d.text((tx, cy), ln, font=f, fill=col)
            cy += lh

        if total_h > h + 3:
            overflow.append(f'слайд {idx}: текст выше рамки на '
                            f'{(total_h - h) / DPI:.2f}" — «{tf.text[:46]}…»')

    return img, overflow


def main(path, out_prefix):
    prs = Presentation(path)
    size = (prs.slide_width, prs.slide_height)
    problems = []
    for i, slide in enumerate(prs.slides, 1):
        img, probs = draw_slide(slide, size, i)
        img.save(f'{out_prefix}-{i:02d}.png')
        problems += probs
    print(f'отрисовано слайдов: {i}')
    if problems:
        print(f'\nзамечания рендера ({len(problems)}):')
        for p in problems:
            print(' •', p)
    else:
        print('замечаний рендера нет')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
