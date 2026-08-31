#!/usr/bin/env python3
"""
Иконки Android-приложения из логотипа «СЕВЕР».

Полный логотип — вытянутая надпись: на иконке 48 dp она превратилась бы в
нечитаемую полоску. Поэтому берётся левая часть знака — монограмма «CE»,
которая и в мелком размере остаётся узнаваемой, на фирменном синем фоне
(#003366, тот же, что в шапке порталов).

Запуск:  python3 android/make-icons.py
Требуется Pillow. Скрипт перезаписывает файлы в
android/android/app/src/main/res/mipmap-*/ — держим его в репозитории,
чтобы иконку можно было пересобрать, а не подбирать заново вручную.
"""
from pathlib import Path
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
LOGO = ROOT / 'logo.png'
RES  = ROOT / 'android' / 'android' / 'app' / 'src' / 'main' / 'res'

BG = (0, 51, 102, 255)          # --blue-800 порталов
MARK_BOX = (16, 46, 334, 175)   # монограмма «CE» в logo.png (без «BEP»)

# Легаси-иконки: плотность → размер в px
LEGACY = {'mdpi': 48, 'hdpi': 72, 'xhdpi': 96, 'xxhdpi': 144, 'xxxhdpi': 192}
# Адаптивные: холст 108 dp, значимая часть — центральные 72 dp (66%)
ADAPTIVE = {'mdpi': 108, 'hdpi': 162, 'xhdpi': 216, 'xxhdpi': 324, 'xxxhdpi': 432}


def mark(width: int) -> Image.Image:
    """
    Монограмма нужной ширины с сохранением пропорций.

    Серые штрихи знака перекрашиваются в белый: на синем фоне серый по серому
    почти не читается, а на иконке 48 dp это решает, узнаётся знак или нет.
    Бирюзовая часть остаётся фирменной.
    """
    src = Image.open(LOGO).convert('RGBA').crop(MARK_BOX)
    px = src.load()
    for y in range(src.height):
        for x in range(src.width):
            r, g, b, a = px[x, y]
            if a and abs(r - g) < 30 and abs(g - b) < 30:   # серый штрих
                px[x, y] = (255, 255, 255, a)
    h = max(1, round(src.height * width / src.width))
    return src.resize((width, h), Image.LANCZOS)


def centered(canvas: Image.Image, mark_img: Image.Image) -> None:
    canvas.alpha_composite(mark_img, (
        (canvas.width - mark_img.width) // 2,
        (canvas.height - mark_img.height) // 2,
    ))


def rounded_mask(size: int, radius_ratio: float) -> Image.Image:
    m = Image.new('L', (size, size), 0)
    ImageDraw.Draw(m).rounded_rectangle([0, 0, size - 1, size - 1],
                                        radius=round(size * radius_ratio), fill=255)
    return m


def circle_mask(size: int) -> Image.Image:
    m = Image.new('L', (size, size), 0)
    ImageDraw.Draw(m).ellipse([0, 0, size - 1, size - 1], fill=255)
    return m


def build() -> None:
    for density, size in LEGACY.items():
        base = Image.new('RGBA', (size, size), BG)
        centered(base, mark(round(size * 0.64)))

        square = Image.new('RGBA', (size, size), (0, 0, 0, 0))
        square.paste(base, mask=rounded_mask(size, 0.20))

        round_ = Image.new('RGBA', (size, size), (0, 0, 0, 0))
        round_.paste(base, mask=circle_mask(size))

        out = RES / f'mipmap-{density}'
        out.mkdir(parents=True, exist_ok=True)
        square.save(out / 'ic_launcher.png')
        round_.save(out / 'ic_launcher_round.png')

    # Передний план адаптивной иконки: фон задаётся отдельно (ic_launcher_background),
    # поэтому здесь только знак, и он обязан уместиться в центральные 66% —
    # края обрезает лаунчер под свою форму.
    for density, size in ADAPTIVE.items():
        fg = Image.new('RGBA', (size, size), (0, 0, 0, 0))
        centered(fg, mark(round(size * 0.42)))
        (RES / f'mipmap-{density}').mkdir(parents=True, exist_ok=True)
        fg.save(RES / f'mipmap-{density}' / 'ic_launcher_foreground.png')

    (RES / 'values' / 'ic_launcher_background.xml').write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<resources>\n'
        '    <color name="ic_launcher_background">#003366</color>\n'
        '</resources>\n', encoding='utf-8')

    print('Иконки пересобраны:', RES)


if __name__ == '__main__':
    build()
