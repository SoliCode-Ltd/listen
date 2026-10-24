"""Writes orb-shader.js next to a page: the site's own orb shader, taken from
public/js/listen-orbs.js, with its grain made optional (a page that fills the
frame with the orb adds its own grain over everything). Used by the prompts
films and the emotions ad, with orb.js.
"""

import re


def write(root, out_dir):
    site = (root / "public/js/listen-orbs.js").read_text(encoding="utf-8")
    frag = re.search(r"var FRAG = (\[.*?\])\.join\('\\n'\);", site, re.S).group(1)
    frag = frag.replace("uniform float uLift;", "uniform float uLift; uniform float uGrain;")
    frag = frag.replace("col += (g - 0.5) * 0.05;", "col += (g - 0.5) * 0.05 * uGrain;")
    assert "uGrain;" in frag and "* uGrain" in frag, "the site's orb shader changed: update orbshader.py"
    (out_dir / "orb-shader.js").write_text("window.ORB_FRAG = " + frag + ".join('\\n');\n", encoding="utf-8")
