{{-- The site font chosen in Admin → Site Settings (App\Support\SiteFont), applied to
     everything on the page: text, headings, buttons and form fields. Code keeps a
     monospace font. --}}
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
  /* Arabic letters in this font are tall: give text left on the browser's
     default line spacing enough room so lines never touch. */
  html[dir="rtl"] body :is(h1, h2, h3, h4, h5, h6) { line-height: 1.42; }
  html[dir="rtl"] body :is(p, li, dd, blockquote) { line-height: 1.75; }
  html body, html body *:not(code):not(pre):not(kbd):not(samp):not(.font-mono):not(.mono) {
    font-family: var(--site-font) !important;
  }
</style>
