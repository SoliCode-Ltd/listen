{{-- The site font and text size chosen in Admin → Site Settings (App\Support\SiteFont),
     applied to everything on the page: text, headings, buttons and form fields. The
     size scales only the letters (font-size-adjust), so 100% looks like the original
     design with any font. Code keeps a monospace font at its own size. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="{{ \App\Support\SiteFont::stylesheetUrl() }}" rel="stylesheet">
<style id="listen-site-font">
  html:root {
    --site-font: {!! \App\Support\SiteFont::stack() !!};
    --font-display: var(--site-font);
    --font-body: var(--site-font);
    --font-display-ar: var(--site-font);
    --font-body-ar: var(--site-font);
    --font-sans: var(--site-font);
    --au-font: var(--site-font);
    --au-font-ar: var(--site-font);
  }
  /* Arabic letters are tall in most of these fonts: give text left on the browser's
     default line spacing enough room so lines never touch. */
  html[dir="rtl"] body :is(h1, h2, h3, h4, h5, h6) { line-height: 1.35; }
  html[dir="rtl"] body :is(p, li, dd, blockquote) { line-height: 1.65; }
  /* A field's hint text sits in its own box the height of one line at the font's own
     spacing, which the browser won't let a page change, and it cuts off whatever hangs
     below that line: the dots of ي and the tail of ى. Let them show; the field still
     ends the text at its own edges. */
  html body :is(input, textarea)::placeholder { overflow: visible; }
  /* A field that takes its direction from what is typed in it (dir="auto") reads with the page
     while it is empty, so an Arabic page's hint text runs right to left. */
  html[dir="rtl"] body :is(input, textarea)[dir="auto"]:placeholder-shown { direction: rtl; }
  html[lang="ar"] { font-size-adjust: {{ \App\Support\SiteFont::sizeAdjust('ar') }}; }
  html:not([lang="ar"]) { font-size-adjust: {{ \App\Support\SiteFont::sizeAdjust('en') }}; }
  html body :is(code, pre, kbd, samp) { font-size-adjust: none; }
  html body, html body *:not(code):not(pre):not(kbd):not(samp):not(.font-mono):not(.mono) {
    font-family: var(--site-font) !important;
  }
</style>
