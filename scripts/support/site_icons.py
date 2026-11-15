"""Draws the site's icons from the Listen mark: a purple square with seven white bars.

Every icon is purple right to its edges, so wherever it is shown in a circle (Google's
results, social profile pictures, phone home screens) the circle is all purple, with no
white ring around a smaller mark. Run from the repository root: python3 scripts/support/site_icons.py
"""
from PIL import Image, ImageDraw

PURPLE = (0x76, 0x59, 0xFA, 255)
WHITE = (255, 255, 255, 255)
# The mark on a 128 grid: x of each bar (8 wide, round ends), its top and height.
BARS = [(18, 59, 10), (32, 46, 36), (46, 35, 58), (60, 24, 80), (74, 35, 58), (88, 46, 36), (102, 59, 10)]


def draw(size, corner=24, bars_scale=1.0):
    """The mark at size x size. corner is the corner rounding on the 128 grid (0 = square);
    bars_scale shrinks the bars around the middle, for icons a platform crops tighter."""
    big = size * 4
    unit = big / 128
    image = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    pen = ImageDraw.Draw(image)
    pen.rounded_rectangle([0, 0, big - 1, big - 1], radius=corner * unit, fill=PURPLE)
    for x, top, height in BARS:
        cx, cy = (x + 4 - 64) * bars_scale + 64, (top + height / 2 - 64) * bars_scale + 64
        w, h = 8 * bars_scale, height * bars_scale
        pen.rounded_rectangle([(cx - w / 2) * unit, (cy - h / 2) * unit, (cx + w / 2) * unit, (cy + h / 2) * unit], radius=w / 2 * unit, fill=WHITE)

    return image.resize((size, size), Image.LANCZOS)


SVG = '''<svg width="128" height="128" viewBox="0 0 128 128" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Listen">
  <!-- The Listen mark, purple to its edges: shown in a circle (search results, profile pictures) it fills the circle, with no white ring. -->
  <rect width="128" height="128" rx="24" fill="#7659FA"/>
''' + ''.join(f'  <rect x="{x}" y="{top}" width="8" height="{height}" rx="4" fill="#FFFFFF"/>\n' for x, top, height in BARS) + '</svg>\n'

if __name__ == '__main__':
    open('public/favicon.svg', 'w').write(SVG)
    # Browser tabs and search results.
    draw(192).save('public/favicon.png', optimize=True)
    # iPhone and iPad home screens round the corners themselves and show transparency as black.
    draw(180, corner=0).convert('RGB').save('public/apple-touch-icon.png', optimize=True)
    # Android home screens crop to their own shape: the bars stay well inside the safe circle.
    draw(512, corner=0, bars_scale=0.8).save('public/icon-512.png', optimize=True)
    # The logo search engines show for the company, and the profile picture for social pages.
    draw(512, corner=0).convert('RGB').save('public/images/brand/listen-logo-512.png', optimize=True)
    draw(1024, corner=0, bars_scale=0.85).convert('RGB').save('public/images/brand/listen-profile-1024.png', optimize=True)
