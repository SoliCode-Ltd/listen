import urllib.request
import urllib.parse
import base64
import re
import os

def get_base64_font(family_url):
    req = urllib.request.Request(family_url, headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'})
    css_content = urllib.request.urlopen(req).read().decode('utf-8')
    match = re.search(r'url\((.*?)\)', css_content)
    font_url = match.group(1)
    font_data = urllib.request.urlopen(font_url).read()
    return base64.b64encode(font_data).decode('utf-8')

text_ar = urllib.parse.quote('إسمع')
url_css_ar = f"https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@700&text={text_ar}"
b64_font_ar = get_base64_font(url_css_ar)

text_en = urllib.parse.quote('Listen')
url_css_en = f"https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@700&text={text_en}"
b64_font_en = get_base64_font(url_css_en)

font_face_ar = f"""
@font-face {{
  font-family: 'IBM Plex Arabic';
  src: url(data:font/woff2;charset=utf-8;base64,{b64_font_ar}) format('woff2');
  font-weight: 700;
  font-style: normal;
}}
"""

font_face_en = f"""
@font-face {{
  font-family: 'IBM Plex Sans';
  src: url(data:font/woff2;charset=utf-8;base64,{b64_font_en}) format('woff2');
  font-weight: 700;
  font-style: normal;
}}
"""

templates = {
    'logo-ar-dark.svg': f"""<svg viewBox="0 0 400 128" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <style>
      {font_face_ar}
      .text {{
        font-family: 'IBM Plex Arabic', sans-serif;
        font-size: 68px;
        font-weight: 700;
        fill: #FFFFFF;
      }}
    </style>
  </defs>
  <g transform="translate(14, 0)">
    <rect x="5" y="59" width="10" height="10" rx="5" fill="#7659FA"/>
    <rect x="23" y="42" width="10" height="44" rx="5" fill="#7659FA"/>
    <rect x="41" y="28" width="10" height="72" rx="5" fill="#7659FA"/>
    <rect x="59" y="14" width="10" height="100" rx="5" fill="#7659FA"/>
    <rect x="77" y="28" width="10" height="72" rx="5" fill="#7659FA"/>
    <rect x="95" y="42" width="10" height="44" rx="5" fill="#7659FA"/>
    <rect x="113" y="59" width="10" height="10" rx="5" fill="#7659FA"/>
  </g>
  <text x="155" y="86" class="text">إسمع</text>
</svg>""",
    'logo-ar-light.svg': f"""<svg viewBox="0 0 400 128" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <style>
      {font_face_ar}
      .text {{
        font-family: 'IBM Plex Arabic', sans-serif;
        font-size: 68px;
        font-weight: 700;
        fill: #0F172A;
      }}
    </style>
  </defs>
  <g transform="translate(14, 0)">
    <rect x="5" y="59" width="10" height="10" rx="5" fill="#7659FA"/>
    <rect x="23" y="42" width="10" height="44" rx="5" fill="#7659FA"/>
    <rect x="41" y="28" width="10" height="72" rx="5" fill="#7659FA"/>
    <rect x="59" y="14" width="10" height="100" rx="5" fill="#7659FA"/>
    <rect x="77" y="28" width="10" height="72" rx="5" fill="#7659FA"/>
    <rect x="95" y="42" width="10" height="44" rx="5" fill="#7659FA"/>
    <rect x="113" y="59" width="10" height="10" rx="5" fill="#7659FA"/>
  </g>
  <text x="155" y="86" class="text">إسمع</text>
</svg>""",
    'logo-dark.svg': f"""<svg viewBox="0 0 400 128" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <style>
      {font_face_en}
      .text {{
        font-family: 'IBM Plex Sans', sans-serif;
        font-size: 68px;
        font-weight: 700;
        fill: #FFFFFF;
      }}
    </style>
  </defs>
  <g transform="translate(14, 0)">
    <rect x="5" y="59" width="10" height="10" rx="5" fill="#7659FA"/>
    <rect x="23" y="42" width="10" height="44" rx="5" fill="#7659FA"/>
    <rect x="41" y="28" width="10" height="72" rx="5" fill="#7659FA"/>
    <rect x="59" y="14" width="10" height="100" rx="5" fill="#7659FA"/>
    <rect x="77" y="28" width="10" height="72" rx="5" fill="#7659FA"/>
    <rect x="95" y="42" width="10" height="44" rx="5" fill="#7659FA"/>
    <rect x="113" y="59" width="10" height="10" rx="5" fill="#7659FA"/>
  </g>
  <text x="155" y="86" class="text">Listen</text>
</svg>""",
    'logo-light.svg': f"""<svg viewBox="0 0 400 128" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <style>
      {font_face_en}
      .text {{
        font-family: 'IBM Plex Sans', sans-serif;
        font-size: 68px;
        font-weight: 700;
        fill: #0F172A;
      }}
    </style>
  </defs>
  <g transform="translate(14, 0)">
    <rect x="5" y="59" width="10" height="10" rx="5" fill="#7659FA"/>
    <rect x="23" y="42" width="10" height="44" rx="5" fill="#7659FA"/>
    <rect x="41" y="28" width="10" height="72" rx="5" fill="#7659FA"/>
    <rect x="59" y="14" width="10" height="100" rx="5" fill="#7659FA"/>
    <rect x="77" y="28" width="10" height="72" rx="5" fill="#7659FA"/>
    <rect x="95" y="42" width="10" height="44" rx="5" fill="#7659FA"/>
    <rect x="113" y="59" width="10" height="10" rx="5" fill="#7659FA"/>
  </g>
  <text x="155" y="86" class="text">Listen</text>
</svg>"""
}

out_dir = r"d:\Faculty of Engineering\Work\SoliCode\Expirements\listen\public\images"
for name, content in templates.items():
    with open(os.path.join(out_dir, name), "w", encoding="utf-8") as f:
        f.write(content)
print("Done!")
