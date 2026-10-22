"""Cuts a site avatar out of its blue-violet backdrop. The backdrop is a smooth
gradient: it is fitted to the image border, refined on every pixel that matches
it, and then any pixel close to it (border-connected or in a pocket, such as
inside the headphones) is cleared with a soft edge. Writes an RGBA PNG.

    python cutout.py <avatar.webp> <out.png> [size]
"""
import sys
from collections import deque

import numpy as np
from PIL import Image, ImageFilter

src, out = sys.argv[1], sys.argv[2]
size = int(sys.argv[3]) if len(sys.argv) > 3 else 720
im = Image.open(src).convert("RGB").resize((size, size), Image.LANCZOS)
a = np.asarray(im).astype(float)
h, w, _ = a.shape
ys, xs = np.mgrid[0:h, 0:w]
u, v = xs / w, ys / h
X = np.stack([np.ones(h * w), u.ravel(), v.ravel(), (u * v).ravel(), (u ** 2).ravel(), (v ** 2).ravel()], 1)


def fit(mask):
    coef, *_ = np.linalg.lstsq(X[mask.ravel()], a.reshape(-1, 3)[mask.ravel()], rcond=None)
    return (X @ coef).reshape(h, w, 3)


band = np.zeros((h, w), bool)
b = max(4, size // 40)
band[:b, :] = band[:, :b] = band[:, -b:] = True
bg = fit(band)
for _ in range(3):
    dist = np.sqrt(((a - bg) ** 2).sum(-1))
    bg = fit(dist < 30)
dist = np.sqrt(((a - bg) ** 2).sum(-1))

# Border-connected backdrop (generous), plus pockets that match it closely.
near = dist < 40
seen = np.zeros((h, w), bool)
q = deque([(y, x) for y in range(h) for x in (0, w - 1)] + [(y, x) for x in range(w) for y in (0, h - 1)])
while q:
    y, x = q.popleft()
    if seen[y, x] or not near[y, x]:
        continue
    seen[y, x] = True
    for dy, dx in ((1, 0), (-1, 0), (0, 1), (0, -1)):
        ny, nx = y + dy, x + dx
        if 0 <= ny < h and 0 <= nx < w and not seen[ny, nx]:
            q.append((ny, nx))
backdrop = seen | (dist < 18)
alpha = Image.fromarray(((~backdrop) * 255).astype(np.uint8)).filter(ImageFilter.MedianFilter(5)).filter(ImageFilter.MinFilter(3)).filter(ImageFilter.GaussianBlur(1.2))
rgba = im.copy()
rgba.putalpha(alpha)
rgba.save(out)
print(out, f"{(~backdrop).mean():.0%} kept")
