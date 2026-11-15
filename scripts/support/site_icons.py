"""Makes the site's icons from the Listen icon (resources/brand/listen-icon-source.jpg):
a purple gradient square with the white sound wave in the middle.

The icon is purple right to its edges, so wherever it is shown in a circle (Google's
results, social profile pictures, phone home screens) the circle is all purple, with no
white ring around a smaller mark. Run from the repository root: python3 scripts/support/site_icons.py
"""
import base64
import io

from PIL import Image

SOURCE = 'resources/brand/listen-icon-source.jpg'


def icon(size):
    return Image.open(SOURCE).convert('RGB').resize((size, size), Image.LANCZOS)


def jpeg_bytes(image):
    out = io.BytesIO()
    image.save(out, 'JPEG', quality=90, optimize=True)
    return out.getvalue()


if __name__ == '__main__':
    # Browser tabs and search results.
    icon(192).save('public/favicon.png', optimize=True)
    # The same picture for browsers that ask for an SVG icon first.
    data = base64.b64encode(jpeg_bytes(icon(192))).decode()
    open('public/favicon.svg', 'w').write(
        '<svg width="192" height="192" viewBox="0 0 192 192" xmlns="http://www.w3.org/2000/svg" '
        'xmlns:xlink="http://www.w3.org/1999/xlink" role="img" aria-label="Listen">\n'
        '  <!-- The Listen icon, purple to its edges: shown in a circle it fills the circle, with no white ring. -->\n'
        f'  <image width="192" height="192" href="data:image/jpeg;base64,{data}"/>\n'
        '</svg>\n'
    )
    # iPhone and iPad home screens round the corners themselves.
    icon(180).save('public/apple-touch-icon.png', optimize=True)
    # Android home screens crop to their own shape: the wave sits well inside the safe circle.
    icon(512).save('public/icon-512.png', optimize=True)
    # The logo search engines show for the company, and the profile picture for social pages.
    icon(512).save('public/images/brand/listen-logo-512.png', optimize=True)
    icon(1024).save('public/images/brand/listen-profile-1024.png', optimize=True)
