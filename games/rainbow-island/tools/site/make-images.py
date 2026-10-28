#!/usr/bin/env python3
"""Builds the pictures of the web site (site/images/) and the game icon (assets/icon.png) from the game's own
graphics and from headless screenshots.

    tools/site/shots.sh                      # the screenshots, in var/site/ (bin/jumphp shot)
    python3 -I tools/site/make-images.py .   # from games/rainbow-island
"""
import json
import sys
from pathlib import Path

from PIL import Image, ImageDraw

GAME = Path(sys.argv[1] if len(sys.argv) > 1 else '.').resolve()
SHOTS = GAME / 'var/site'
OUT = GAME / 'site/images'
TILE = 8

# screenshot -> (file name on the site); see shots.sh for what each one shows
SCREENS = ['round-1', 'round-6', 'round-10', 'round-15', 'round-19', 'round-26', 'boss-4', 'boss-28']


def rainbow(island=1):
    """The 48 x 32 rainbow of the game, assembled from its overlay tiles like Arcade\\Artwork does."""
    sheet = Image.open(GAME / f'assets/tiles/overlay-{island}.png').convert('RGBA')
    rows = json.loads((GAME / 'assets/data/rainbow.json').read_text())['tiles']
    arc = Image.new('RGBA', (6 * TILE, 4 * TILE))
    for ty, row in enumerate(rows):
        for tx, tile in enumerate(row):
            if tile:
                box = ((tile % 16) * TILE, (tile // 16) * TILE)
                arc.paste(sheet.crop((*box, box[0] + TILE, box[1] + TILE)), (tx * TILE, ty * TILE))
    return arc


def big(image, scale):
    return image.resize((image.width * scale, image.height * scale), Image.NEAREST)


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    arc = rainbow()

    # icons: the rainbow on a square (favicon, apple-touch, the game page's icon)
    for size, name in [(32, 'favicon.png'), (180, 'apple-touch-icon.png'), (512, 'icon-512.png')]:
        icon = Image.new('RGBA', (48, 48), (0, 0, 0, 0))
        icon.paste(arc, (0, 8), arc)
        icon.resize((size, size), Image.NEAREST).save(OUT / name, optimize=True)
    icon = Image.new('RGBA', (48, 48), (0, 0, 0, 0))
    icon.paste(arc, (0, 8), arc)
    icon.save(GAME / 'assets/icon.png', optimize=True)
    big(arc, 4).save(OUT / 'rainbow.png', optimize=True)

    # the loading screen of the original, and the screenshots
    big(Image.open(GAME / 'assets/sprites/loading.png').convert('RGB'), 2).save(OUT / 'loading.png', optimize=True)
    for name in SCREENS + ['title']:
        Image.open(SHOTS / f'{name}.png').convert('RGB').save(OUT / f'{name}.png', optimize=True)

    # the sharing picture (Open Graph / Twitter): 1200 x 630, the loading screen between two rainbows
    og = Image.new('RGB', (1200, 630), (16, 40, 120))
    draw = ImageDraw.Draw(og)
    for y in range(630):                         # a sky in bands, like the CPC would draw it
        band = y // 30
        draw.line([(0, y), (1200, y)], fill=(20 + band * 3, 70 + band * 6, 170 + band * 3))
    loading = big(Image.open(GAME / 'assets/sprites/loading.png').convert('RGB'), 3).crop((0, 0, 960, 600))
    draw.rectangle([112, 7, 1088, 623], fill=(0, 0, 0))
    og.paste(loading, (120, 15))
    side = big(arc, 2)
    for x, y in [(8, 440), (1096, 440), (8, 60), (1096, 60)]:
        og.paste(side, (x, y), side)
    og.save(OUT / 'og.png', optimize=True)

    # a strip of screenshots (README)
    strip = Image.new('RGB', (4 * 512 + 3 * 8, 352), (0, 0, 0))
    for i, name in enumerate(['round-1', 'round-10', 'round-26', 'boss-28']):
        strip.paste(Image.open(SHOTS / f'{name}.png').convert('RGB'), (i * 520, 0))
    strip.save(OUT / 'strip.png', optimize=True)
    print('images:', ', '.join(sorted(p.name for p in OUT.iterdir())))


main()
