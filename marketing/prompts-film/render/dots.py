"""Turns a cut-out avatar (RGBA) into a portrait made of dots: a staggered grid
with a little jitter, each dot keeping the photo's colour, its darkness and its
alpha. Writes JSON: {"size": S, "dots": [[x, y, r, g, b, alpha, lum, seed], ...]}.

    python dots.py <cutout.png> <out.json> [size] [step]
"""
import json
import math
import sys

import numpy as np
from PIL import Image

src, out = sys.argv[1], sys.argv[2]
size = int(sys.argv[3]) if len(sys.argv) > 3 else 480
step = float(sys.argv[4]) if len(sys.argv) > 4 else 6.2
a = np.asarray(Image.open(src).convert("RGBA").resize((size, size), Image.LANCZOS)).astype(float)


def h(n):
    s = math.sin(n * 127.1) * 43758.5453
    return s - math.floor(s)


dots, k, row = [], 0, 0
y = 0.0
while y < size:
    x = (row % 2) * step / 2
    while x < size:
        jx, jy = x + (h(k * 3.1) - .5) * step * .5, y + (h(k * 7.7) - .5) * step * .5
        k += 1
        px = a[min(size - 1, max(0, round(jy))), min(size - 1, max(0, round(jx)))]
        al = px[3] / 255
        if al >= .08:
            lum = (0.299 * px[0] + 0.587 * px[1] + 0.114 * px[2]) / 255
            dots.append([round(jx, 1), round(jy, 1), int(px[0]), int(px[1]), int(px[2]), round(al, 2), round(lum, 3), round(h(k * 1.3), 3)])
        x += step
    y += step * 0.866
    row += 1
json.dump({"size": size, "dots": dots}, open(out, "w"), separators=(",", ":"))
print(out, len(dots), "dots")
