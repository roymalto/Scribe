"""Scribe brand files: the app icon, the logo (icon + wordmark, dark and white), PNG icons and favicon.

Same family as SOAP BOX (rounded-square mark, letters outlined so they render the same everywhere, amber accent),
in Scribe's own deep teal. Run from the project folder (needs fonttools, cairosvg, pillow):

    uv run --with fonttools --with cairosvg --with pillow python docs/brand/build_brand.py

Writes public/brand/{icon,logo,logo-white}.svg, public/brand/icon-{32,180,192,512}.png and public/favicon.ico.
"""
import io
import os

import cairosvg
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image

TEAL = "#134e4a"        # Scribe deep teal (the panel's primary is Tailwind teal)
INK = "#0f3d3a"
AMBER = "#f4a62a"       # shared accent with SOAP BOX
PAPER = "#ffffff"
LINE = "#c9d6d4"
SANS = "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"
SERIF = "/usr/share/fonts/truetype/noto/NotoSerif-Bold.ttf"
OUT = "public/brand"


def text_path(font_file, text, x, baseline, size, tracking=0.0):
    """SVG path data for text set at (x, baseline), cap height ~0.72 * size; returns (d, advance width)."""
    font = TTFont(font_file)
    cmap, glyphs = font.getBestCmap(), font.getGlyphSet()
    scale = size / font["head"].unitsPerEm
    pen = SVGPathPen(glyphs)
    cursor = x
    for ch in text:
        name = cmap[ord(ch)]
        glyphs[name].draw(TransformPen(pen, (scale, 0, 0, -scale, cursor, baseline)))
        cursor += glyphs[name].width * scale + tracking
    return pen.getCommands(), cursor - tracking - x


def mark(x=0.0, y=0.0, s=1.0):
    """The icon at (x, y), scale s (512 units at s=1): a prescription sheet with Rx and an amber pen."""
    rx_d, _ = text_path(SERIF, "R", 0, 0, 176)
    x_d, _ = text_path(SERIF, "x", 0, 0, 104)
    return f"""<g transform="translate({x} {y}) scale({s})">
  <rect width="512" height="512" rx="112" fill="{TEAL}"/>
  <rect x="120" y="84" width="272" height="344" rx="34" fill="{PAPER}"/>
  <path fill="{TEAL}" transform="translate(150 246)" d="{rx_d}"/>
  <path fill="{TEAL}" transform="translate(255 268)" d="{x_d}"/>
  <rect x="150" y="300" width="168" height="18" rx="9" fill="{LINE}"/>
  <rect x="150" y="338" width="120" height="18" rx="9" fill="{LINE}"/>
  <g transform="rotate(-45 352 352)">
    <rect x="300" y="331" width="150" height="42" rx="14" fill="{AMBER}"/>
    <path d="M300 331 L262 352 L300 373 Z" fill="{INK}"/>
    <rect x="410" y="331" width="40" height="42" rx="12" fill="{INK}" opacity="0.25"/>
  </g>
</g>"""


def svg(width, height, body, label="Scribe"):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width:.1f} {height:.1f}" role="img" '
            f'aria-label="{label}"><title>{label}</title>{body}</svg>\n')


def logo(word_fill):
    word, w = text_path(SANS, "SCRIBE", 0, 0, 92, tracking=4)
    body = mark(10, 10, 120 / 512) + f'<path fill="{word_fill}" transform="translate(156 103)" d="{word}"/>'
    return svg(156 + w + 12, 140, body)


def main():
    os.makedirs(OUT, exist_ok=True)
    icon = svg(512, 512, mark())
    files = {"icon.svg": icon, "logo.svg": logo(TEAL), "logo-white.svg": logo("#ffffff")}
    for name, content in files.items():
        with open(f"{OUT}/{name}", "w", encoding="utf-8") as f:
            f.write(content)
    for size in (32, 180, 192, 512):
        cairosvg.svg2png(bytestring=icon.encode(), write_to=f"{OUT}/icon-{size}.png", output_width=size, output_height=size)
    png = cairosvg.svg2png(bytestring=icon.encode(), output_width=256, output_height=256)
    Image.open(io.BytesIO(png)).save("public/favicon.ico", sizes=[(16, 16), (32, 32), (48, 48), (64, 64)])
    print("wrote", ", ".join(sorted(os.listdir(OUT))), "and public/favicon.ico")


if __name__ == "__main__":
    main()
