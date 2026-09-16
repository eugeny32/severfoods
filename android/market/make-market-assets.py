#!/usr/bin/env python3
"""
Иконка для карточки приложения в Эвотор.Маркете.

Требования площадки (чек-лист ревью Эвотора): 300×300 px, простая узнаваемая
форма, логотип без текста, простой фон без прозрачности, охранная зона 40 px
от края — углы скругляются магазином автоматически, и всё, что ближе 40 px,
может быть срезано.

Знак берётся из общего логотипа тем же способом, что и иконка приложения
(android/make-icons.py): полная надпись «СЕВЕР» в мелком размере превращается в
нечитаемую полоску, поэтому используется монограмма.

Запуск: python3 android/market/make-market-assets.py
"""
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent.parent
LOGO = ROOT / 'logo.png'
OUT  = ROOT / 'android' / 'market'

SIZE      = 300
SAFE_ZONE = 40                  # требование площадки
BG        = (0, 51, 102, 255)   # --blue-800 порталов
MARK_BOX  = (16, 46, 334, 175)  # монограмма «CE» в logo.png, без «BEP»


def mark(width: int) -> Image.Image:
    """Монограмма заданной ширины; серые штрихи — в белый, как в иконке приложения."""
    src = Image.open(LOGO).convert('RGBA').crop(MARK_BOX)
    px = src.load()
    for y in range(src.height):
        for x in range(src.width):
            r, g, b, a = px[x, y]
            if a and abs(r - g) < 30 and abs(g - b) < 30:
                px[x, y] = (255, 255, 255, a)
    h = max(1, round(src.height * width / src.width))
    return src.resize((width, h), Image.LANCZOS)


def build() -> None:
    OUT.mkdir(parents=True, exist_ok=True)

    canvas = Image.new('RGB', (SIZE, SIZE), BG[:3])   # RGB — прозрачность запрещена
    inner  = SIZE - 2 * SAFE_ZONE
    m = mark(round(inner * 0.92))
    canvas.paste(m, ((SIZE - m.width) // 2, (SIZE - m.height) // 2), m)
    canvas.save(OUT / 'icon-300.png')

    print('Иконка готова:', OUT / 'icon-300.png', f'{SIZE}×{SIZE}, охранная зона {SAFE_ZONE} px')


if __name__ == '__main__':
    build()
