#!/usr/bin/env python3
"""Derives the images the web serves from the originals in docs/assets.

The originals stay as they were delivered, metadata included, and are what the README and
the guide show. The web gets copies sized for the screen: the banner and the four spots in
WebP, a fraction of the weight of the PNGs, and the icons in PNG, cropped around the tucano.

Run it through `make web-art` after an original changes, and commit what it writes.
"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageChops, ImageFilter

ROOT = Path(__file__).resolve().parent.parent
ASSETS = ROOT / "docs" / "assets"
PUBLIC = ROOT / "services" / "web" / "public"

# The hero is at most 1080 px wide (--content-width), and a spot at most 220 px: this leaves
# room for a denser screen without shipping the 2688 px original.
BANNER_WIDTH = 1600
SPOT_SIDE = 512
WEBP_QUALITY = 82

ICONS = {
    "logo-256.png": 256,
    "icon-512.png": 512,
    "icon-192.png": 192,
    "apple-touch-icon-180.png": 180,
    "favicon-64.png": 64,
}

# How far a pixel has to be from the paper to count as ink, and the breathing room the
# icons keep around the bird, as a fraction of the side.
INK_THRESHOLD = 64
ICON_MARGIN = 0.06

# The grain of the paper makes a full-color PNG heavy. An octree palette of 64 colors keeps
# the three inks true (median cut turns the teal tip grey) at a fifth of the weight.
ICON_COLORS = 64


def main() -> None:
    illustrations = PUBLIC / "illustrations"
    illustrations.mkdir(parents=True, exist_ok=True)

    banner = Image.open(ASSETS / "banner.png").convert("RGB")
    height = round(banner.height * BANNER_WIDTH / banner.width)
    save_webp(banner.resize((BANNER_WIDTH, height), Image.Resampling.LANCZOS), illustrations / "banner.webp")

    for spot in sorted((ASSETS / "ui").glob("*.png")):
        image = Image.open(spot).convert("RGB")
        save_webp(image.resize((SPOT_SIDE, SPOT_SIDE), Image.Resampling.LANCZOS), illustrations / f"ui-{spot.stem}.webp")

    tile = around_the_ink(Image.open(ASSETS / "tucano.png").convert("RGB"))
    for name, side in ICONS.items():
        target = PUBLIC / name
        icon = tile.resize((side, side), Image.Resampling.LANCZOS)
        icon.quantize(ICON_COLORS, Image.Quantize.FASTOCTREE, dither=Image.Dither.FLOYDSTEINBERG).save(target, optimize=True)
        print(f"{target.relative_to(ROOT)}  {side}x{side}  {target.stat().st_size // 1024} KB")


def save_webp(image: Image.Image, target: Path) -> None:
    image.save(target, "WEBP", quality=WEBP_QUALITY, method=6)
    print(f"{target.relative_to(ROOT)}  {image.width}x{image.height}  {target.stat().st_size // 1024} KB")


def around_the_ink(image: Image.Image) -> Image.Image:
    """The smallest square around what is printed on the paper, plus a margin.

    The paper is the color of a corner. The grain of the paper would count as ink here and
    there, so the mask goes through a median filter before its box is taken.
    """
    paper = Image.new("RGB", image.size, image.getpixel((0, 0)))
    distance = ImageChops.difference(image, paper).convert("L")
    ink = distance.point(lambda value: 255 if value > INK_THRESHOLD else 0).filter(ImageFilter.MedianFilter(5))
    box = ink.getbbox()
    if box is None:
        raise SystemExit("no ink found on the mascot: is docs/assets/tucano.png a blank page?")

    left, top, right, bottom = box
    side = round(max(right - left, bottom - top) * (1 + 2 * ICON_MARGIN))
    side = min(side, image.width, image.height)
    center_x, center_y = (left + right) / 2, (top + bottom) / 2
    x = min(max(round(center_x - side / 2), 0), image.width - side)
    y = min(max(round(center_y - side / 2), 0), image.height - side)
    return image.crop((x, y, x + side, y + side))


if __name__ == "__main__":
    main()
