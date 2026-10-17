<style id="listen-shared-styles">
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&display=swap');

:root {
  --font-display: var(--site-font, sans-serif);
  --font-body: var(--site-font, sans-serif);
  --font-display-ar: var(--site-font, sans-serif);
  --font-body-ar: var(--site-font, sans-serif);
  --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  --btn-radius: 8px;
  --bg: #0B0B0F;
  --surface: #1A1A22;
  --surface2: #121218;
  --line: #2A2A35;
  --text: #FFFFFF;
  --muted: #A1A1AA;
  --a: #7659FA;
  --b: #7659FA;
  --brand-accent: #7659FA;
  --brand-hover: #5B3DF5;
  --brand-active: #4C2DEB;
  --brand-subtle: #A99CFF;
  /* Violet for small text: readable on every background (WCAG AA). */
  --accent-text: #9580FF;
  --brand-accent-glow: rgba(118, 89, 250, 0.4);
  --ok: #32c58d;
  --danger: #ee4a77;
  --shadow: 0 4px 16px rgba(0,0,0,.15);
  --shadow-sm: 0 2px 8px rgba(0,0,0,.10);
  --radius: .5rem;
  --radius-lg: .75rem;
  --radius-full: 9999px;
}
html[data-theme="light"] {
  --bg: #FFFFFF;
  --surface: #FFFFFF;
  --surface2: #F5F6FB;
  --line: #E6E8F0;
  --text: #0F172A;
  /* Grey text stays readable on the light grey cards too. */
  --muted: #5F6673;
  --accent-text: #6247EE;
  --shadow: 0 4px 12px rgba(0,0,0,.06);
  --shadow-sm: 0 2px 6px rgba(0,0,0,.04);
  --a: #7659FA;
  --b: #7659FA;
  --brand-accent-glow: rgba(118, 89, 250, 0.15);
}

* { box-sizing: border-box; }
html, body {
  margin: 0;
  padding: 0;
  max-width: 100%;
  background-color: var(--bg);
}
html.mobile-menu-open,
body.mobile-menu-open {
  overflow: hidden;
}
body {
  font-family: var(--font-body);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

html[dir="rtl"] body {
  direction: rtl;
  font-family: var(--font-body-ar);
}

html[dir="rtl"] h1,
html[dir="rtl"] h2,
html[dir="rtl"] h3,
html[dir="rtl"] h4,
html[dir="rtl"] h5,
html[dir="rtl"] h6,
html[dir="rtl"] .font-display {
  font-family: var(--font-display-ar);
}

.container { width: min(1200px, 92%); margin: 0 auto; }

img, svg, video, canvas, iframe {
  max-width: 100%;
  height: auto;
}

h1, h2, h3, h4, h5, h6, .font-display {
  font-family: var(--font-display);
  font-weight: 800;
  /* letter-spacing: -0.04em; */
  line-height: 1.1;
}

h1 {
  font-size: clamp(2.5rem, 5vw, 4rem);
  font-weight: 900;
  letter-spacing: -0.05em;
  line-height: 1.05;
}

html[dir="rtl"] h1,
html[dir="rtl"] h2,
html[dir="rtl"] h3,
html[dir="rtl"] h4,
html[dir="rtl"] h5,
html[dir="rtl"] h6 {
  letter-spacing: 0;
  line-height: 1.2;
}

/* Recolor the hero graphic to match the new vibrant brand palette (#FF4433, #8A2BE2) */
.hero-art > img {
  /* Removed hue shift as new graphic matches native palette */
}

/* ===== HEADER STYLES ===== */
.app-header {
  position: sticky;
  top: 0;
  z-index: 100;
  margin: 0;
  padding: 0;
  width: 100%;
  background: color-mix(in oklab, var(--bg) 75%, transparent);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border-bottom: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
}

.header-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  min-height: 64px;
  width: 100%;
  max-width: 1400px;
  margin: 0 auto;
  padding: 0 clamp(1rem, 2vw, 1.5rem);
}

.brand {
  display: flex;
  align-items: center;
  gap: .55rem;
  text-decoration: none;
  color: var(--text);
  font-weight: 800;
  font-size: 1.15rem;
}

.site-logo {
  height: 2.2rem;
  width: auto;
  display: block;
}

.site-logo.logo-light {
   display: none;
}

html[data-theme="light"] .site-logo.logo-dark {
   display: none;
}

html[data-theme="light"] .site-logo.logo-light {
   display: block;
}

.brand-text {
  color: var(--brand-accent);
}

.text-gradient-accent {
  color: var(--brand-accent);
}

/* Desktop Navigation */
.desktop-nav {
  display: flex;
  align-items: center;
  gap: .25rem;
}

.nav-link {
  display: inline-flex;
  align-items: center;
  white-space: nowrap;
  padding: .5rem .85rem;
  border-radius: var(--radius);
  font-size: .95rem;
  font-weight: 600;
  color: var(--muted);
  text-decoration: none;
  transition: all .2s ease;
}

.nav-link:hover {
  color: var(--text);
  background: color-mix(in oklab, var(--surface) 70%, transparent);
}

.nav-link.active {
  color: var(--text);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
}

/* Header Actions */
.header-actions {
  display: flex;
  align-items: center;
  gap: .65rem;
}

/* ===== LANGUAGE SWITCHER ===== */
.locale-switch,
.mobile-locale-switch {
  display: inline-grid;
  grid-template-columns: 1fr 1fr;
  align-items: center;
  position: relative;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius-full);
  padding: 4px;
  isolation: isolate;
  overflow: hidden;
  width: auto;
  min-width: 13rem;
  font-family: var(--font-body), var(--font-body-ar), sans-serif;
}

.locale-switch .locale-indicator,
.mobile-locale-switch .locale-indicator {
  position: absolute;
  top: 4px;
  left: 4px;
  width: calc(50% - 4px);
  height: calc(100% - 8px);
  border-radius: var(--radius-full);
  background: var(--brand-accent);
  border: 1px solid var(--brand-accent);
  box-shadow: 0 4px 12px var(--brand-accent-glow);
  transition: transform .25s cubic-bezier(.4, 0, .2, 1);
  z-index: 0;
  pointer-events: none;
}

.locale-switch.ar-active .locale-indicator,
.mobile-locale-switch.ar-active .locale-indicator {
  transform: translateX(100%);
}

/* During locale navigation, snap the switch state instantly to avoid
   transient visual gaps/flicker on mobile (notably ar -> en). */
.locale-switch.locale-switch-prep .locale-indicator,
.mobile-locale-switch.locale-switch-prep .locale-indicator {
  transition-duration: 0s !important;
}

.locale-switch.locale-switch-prep .locale-link,
.mobile-locale-switch.locale-switch-prep .locale-link {
  transition: none !important;
}


.locale-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: .35rem 0;
  border-radius: var(--radius-full);
  border: none;
  background: transparent;
  color: var(--muted);
  text-decoration: none;
  font-size: .8rem;
  font-weight: 700;
  transition: color .2s ease;
  position: relative;
  z-index: 1;
  width: 100%;
  white-space: nowrap;
  text-align: center;
  letter-spacing: .01em;
  line-height: 1.25;
  font-family: inherit;
  min-height: 1.6rem;
}

.locale-link:hover {
  color: var(--text);
}

.locale-link.active {
  color: #ffffff;
  font-weight: 800;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-body);
  font-weight: 600;
  font-size: 0.95rem;
  padding: 0.85rem 1.8rem;
  border-radius: var(--btn-radius);
  border: 1px solid var(--line);
  background: color-mix(in oklab, var(--surface) 80%, transparent);
  color: var(--text);
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  text-decoration: none;
  line-height: 1.2;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.btn:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-sm);
}

.btn:focus-visible {
  outline: 2px solid var(--brand-accent);
  outline-offset: 3px;
}

.btn-primary {
  background: var(--brand-accent);
  border: none;
  color: #fff;
  box-shadow: var(--shadow-sm);
}

.btn.btn-primary:hover,
.btn-primary:hover {
  background: var(--brand-hover);
  transform: translateY(-2px);
  box-shadow: var(--shadow);
}

.btn.btn-primary:active,
.btn-primary:active {
  background: var(--brand-active);
  transform: translateY(0);
}

.btn-ghost {
  background: transparent;
  border-color: transparent;
  color: var(--text);
}

.btn-ghost:hover {
  box-shadow: 0 12px 24px color-mix(in oklab, var(--line) 40%, transparent);
}

.btn-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.35rem;
  height: 2.35rem;
  padding: 0;
  border-radius: var(--btn-radius);
  border: 1px solid var(--line);
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  transition: all .2s ease;
}

.btn-icon:hover {
  background: color-mix(in oklab, var(--surface2) 80%, transparent);
  color: var(--text);
}

.btn-icon .icon {
  width: 1.05rem;
  height: 1.05rem;
  stroke: currentColor;
  fill: currentColor;
  stroke-width: 2;
}

.theme-toggle .sun { display: none; }
html[data-theme="light"] .theme-toggle .sun { display: block; }
html[data-theme="light"] .theme-toggle .moon { display: none; }

/* User Menu */
.user-menu {
  position: relative;
}

.user-trigger {
  display: flex;
  align-items: center;
  gap: .55rem;
  padding: .3rem .6rem .3rem .3rem;
  border-radius: var(--radius-full);
  border: 1px solid var(--line);
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  transition: all .2s ease;
}

.user-trigger:hover {
  background: color-mix(in oklab, var(--surface2) 60%, var(--surface));
}

.user-avatar {
  width: 1.85rem;
  height: 1.85rem;
  border-radius: var(--radius-full);
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--brand-accent);
  font-size: .75rem;
  font-weight: 700;
  color: #fff;
  overflow: hidden;
}

.user-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.user-name {
  font-size: .85rem;
  font-weight: 600;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-trigger .chevron {
  width: 1rem;
  height: 1rem;
  stroke: var(--muted);
  fill: none;
  stroke-width: 2;
  transition: transform .2s ease;
}

.user-menu.open .user-trigger .chevron {
  transform: rotate(180deg);
}

/* User Dropdown */
.user-dropdown {
  position: absolute;
  top: calc(100% + .5rem);
  right: 0;
  left: auto;
  z-index: 180;
  width: 260px;
  max-width: calc(100vw - 1rem);
  background: color-mix(in oklab, var(--surface) 100%, transparent);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 40px rgba(0,0,0,0.5), inset 0 1px 1px color-mix(in oklab, var(--text) 5%, transparent);
  opacity: 0;
  visibility: hidden;
  transform: translateY(-8px);
  transition: all .2s ease;
  overflow: hidden;
}

.user-menu.open .user-dropdown {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

.dropdown-header {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .85rem 1rem;
  background: color-mix(in oklab, var(--surface2) 50%, transparent);
  border-bottom: 1px solid color-mix(in oklab, var(--line) 50%, transparent);
}

.dropdown-avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--radius-full);
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--brand-accent);
  font-size: .95rem;
  font-weight: 700;
  color: #fff;
  overflow: hidden;
}

.dropdown-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.dropdown-user-info strong {
  display: block;
  font-size: .95rem;
  margin-bottom: .1rem;
}

.dropdown-user-info span {
  font-size: .78rem;
  color: var(--muted);
}

.dropdown-divider {
  /* height: 1px; */
  background: var(--line);
  margin: .25rem 0;
}

.dropdown-item {
  display: flex;
  align-items: center;
  gap: .65rem;
  padding: .7rem 1rem;
  font-size: .95rem;
  color: var(--text);
  text-decoration: none;
  transition: all .15s ease;
  cursor: pointer;
  border: none;
  background: none;
  width: 100%;
  text-align: left;
}

.dropdown-item svg {
  width: 1.1rem;
  height: 1.1rem;
  stroke: var(--muted);
  fill: none;
  stroke-width: 1.8;
}

.dropdown-item:hover {
  background: color-mix(in oklab, var(--surface2) 50%, var(--surface));
}

.dropdown-item:hover svg {
  stroke: var(--text);
}

.dropdown-item.logout {
  color: var(--danger);
}

.dropdown-item.logout svg {
  stroke: var(--danger);
}

/* Mobile Toggle */
.mobile-toggle {
  display: none;
  width: 2.5rem;
  height: 2.5rem;
  padding: 0;
  border: 1px solid var(--line);
  border-radius: var(--radius);
  background: var(--surface);
  cursor: pointer;
  position: relative;
  transition: all .2s ease;
}

.mobile-toggle:hover {
  background: color-mix(in oklab, var(--surface2) 80%, transparent);
}

.hamburger,
.hamburger::before,
.hamburger::after {
  display: block;
  width: 1.35rem;
  height: 2px;
  background: var(--text);
  border-radius: 2px;
  transition: all .25s ease;
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
}

.hamburger { top: 50%; transform: translate(-50%, -50%); }
.hamburger::before { content: ''; top: -7px; }
.hamburger::after { content: ''; top: 7px; }

.mobile-toggle[aria-expanded="true"] .hamburger {
  background: transparent;
}

.mobile-toggle[aria-expanded="true"] .hamburger::before {
  top: 0;
  transform: translateX(-50%) rotate(45deg);
}

.mobile-toggle[aria-expanded="true"] .hamburger::after {
  top: 0;
  transform: translateX(-50%) rotate(-45deg);
}

/* Mobile Navigation */
.mobile-nav {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: var(--bg);
  backdrop-filter: none;
  -webkit-backdrop-filter: none;
  z-index: 130;
  min-height: 100dvh;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity .2s ease, visibility .2s ease;
}

.mobile-nav.open {
  display: block;
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
}

.mobile-nav-inner {
  display: flex;
  flex-direction: column;
  padding: .75rem 1rem 1rem;
  padding-top: calc(env(safe-area-inset-top) + .75rem);
  gap: .25rem;
}

.mobile-nav-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
  position: sticky;
  top: 0;
  z-index: 2;
  padding-bottom: .35rem;
  background: var(--bg);
}

.mobile-nav-brand {
  display: inline-flex;
  align-items: center;
  gap: .55rem;
  text-decoration: none;
  color: var(--text);
  font-weight: 800;
  font-size: 1.05rem;
}

.mobile-nav-brand img {
  max-height: 32px;
  width: auto;
}

.mobile-nav-tools {
  display: flex;
  align-items: center;
  gap: .45rem;
}

.mobile-close .close-icon {
  width: 1.05rem;
  height: 1.05rem;
  stroke: currentColor;
  fill: none;
  stroke-width: 2;
}

.mobile-nav-link {
  display: flex;
  align-items: center;
  padding: .85rem 1rem;
  font-size: .95rem;
  font-weight: 600;
  color: var(--muted);
  text-decoration: none;
  border-radius: var(--btn-radius);
  transition: all .2s ease;
}

.mobile-nav-link:hover,
.mobile-nav-link.active {
  color: var(--text);
  background: var(--surface);
}

.mobile-nav-divider {
  height: 1px;
  background: var(--line);
  margin: .75rem 0;
}

.mobile-locale-switch {
  align-self: flex-start;
  width: auto;
  min-width: 13rem;
}

.mobile-locale-switch .locale-link {
  padding: .55rem 0;
  font-size: .95rem;
  min-height: 1.8rem;
}

.mobile-cta {
  margin-top: .5rem;
  justify-content: center;
}

button.mobile-nav-link {
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  font-family: inherit;
  cursor: pointer;
}

form:has(button.mobile-nav-link) {
  width: 100%;
  margin: 0;
}

.mobile-nav-link.logout {
  color: var(--danger);
  background: color-mix(in oklab, var(--danger) 10%, transparent);
  border: 1px solid color-mix(in oklab, var(--danger) 20%, transparent);
}

.mobile-nav-link.logout:hover {
  background: color-mix(in oklab, var(--danger) 15%, transparent);
  color: var(--danger);
}

/* ===== FOOTER STYLES ===== */
.app-footer {
  background: var(--bg);
  border-top: 1px solid var(--line);
  margin-top: auto;
}

.footer-container {
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
  padding: 2.5rem clamp(.75rem, 2vw, 1.1rem) .8rem;
}

.footer-grid {
  display: grid;
  grid-template-columns: 1.5fr 2.5fr;
  gap: 3rem;
  padding-bottom: 2rem;
  border-bottom: 1px solid var(--line);
}

.footer-brand .brand {
  margin-bottom: 1rem;
}

.footer-tagline {
  font-size: .95rem;
  line-height: 1.6;
  color: var(--muted);
  max-width: 280px;
}

.footer-links {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 1.5rem;
}

.footer-column h4 {
  font-size: .85rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text);
  margin: 0 0 1rem 0;
}

.footer-column ul {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: .55rem;
}

.footer-column a {
  font-size: .95rem;
  color: var(--muted);
  text-decoration: none;
  transition: color .2s ease;
}

.footer-column a:hover {
  color: var(--text);
}

.footer-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 1.5rem;
  flex-wrap: wrap;
  gap: 1rem;
}

.footer-copyright {
  font-size: .85rem;
  color: var(--muted);
  unicode-bidi: plaintext;
}

.footer-copyright p {
  margin: 0;
}

.footer-bottom.single {
  justify-content: center;
}
.footer-bottom.single .footer-copyright {
  width: 100%;
  text-align: center;
}

.footer-copyright a {
  color: var(--text);
  text-decoration: none;
}

.footer-copyright a:hover {
  text-decoration: underline;
}

.footer-social {
  display: flex;
  align-items: center;
  gap: .5rem;
}

.social-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: var(--radius);
  border: 1px solid var(--line);
  background: var(--surface);
  color: var(--muted);
  transition: all .2s ease;
}

.social-link svg {
  width: 1rem;
  height: 1rem;
  fill: currentColor;
}

.social-link:hover {
  color: var(--text);
  border-color: color-mix(in oklab, var(--a) 50%, var(--line));
  transform: translateY(-2px);
}

.page-wrapper {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.page-wrapper > main {
  flex: 1;
  padding-bottom: 3rem;
}

.section-spacing {
  margin-bottom: 3rem;
}

.last-section {
  margin-bottom: 0;
}

/* Animations */
@keyframes fadeUpIn {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Alerts */
.alert {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-radius: var(--radius);
  margin-bottom: 2rem;
  font-weight: 500;
  font-size: 0.95rem;
  animation: fadeUpIn 0.4s ease forwards;
}

.alert-icon {
  width: 1.5rem;
  height: 1.5rem;
  flex-shrink: 0;
  stroke: currentColor;
  stroke-width: 2;
  fill: none;
}

.alert-success {
  background: color-mix(in oklab, var(--ok) 15%, transparent);
  color: var(--ok);
  border: 1px solid color-mix(in oklab, var(--ok) 30%, transparent);
}

.alert-error {
  background: color-mix(in oklab, var(--danger) 15%, transparent);
  color: var(--danger);
  border: 1px solid color-mix(in oklab, var(--danger) 30%, transparent);
}

.text-danger {
  color: var(--danger);
  font-size: 0.85rem;
  margin-top: 0.25rem;
  display: block;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
  .footer-grid {
    grid-template-columns: 1fr;
    gap: 2rem;
  }

  .footer-links {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* ===== SETTINGS CARD & TOGGLE ===== */
.setting-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.15rem 1.25rem;
  background: color-mix(in oklab, var(--surface2) 30%, var(--surface));
  border: 1px solid var(--line);
  border-radius: var(--radius);
  transition: all .25s ease;
}
.setting-card:hover {
  border-color: color-mix(in oklab, var(--a) 40%, var(--line));
  background: color-mix(in oklab, var(--surface2) 50%, var(--surface));
}
@media (max-width: 640px) {
  .setting-card {
    padding: .95rem 1.15rem;
  }
}
.setting-info {
  flex: 1;
}
.setting-info label {
  display: block;
  margin: 0 0 .2rem 0;
  font-size: .95rem;
  font-weight: 600;
  color: var(--text);
  cursor: pointer;
}
.setting-info .hint {
  margin: 0;
  padding: 0;
  font-size: .85rem;
  color: var(--muted);
  line-height: 1.4;
}
.toggle-switch {
  position: relative;
  display: inline-block;
  width: 44px;
  height: 24px;
  flex-shrink: 0;
}
.toggle-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}
.toggle-slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: var(--line);
  transition: .3s cubic-bezier(0.4, 0.0, 0.2, 1);
  border-radius: 24px;
}
.toggle-slider:before {
  position: absolute;
  content: "";
  height: 18px;
  width: 18px;
  left: 3px;
  bottom: 3px;
  background-color: #fff;
  transition: .3s cubic-bezier(0.4, 0.0, 0.2, 1);
  border-radius: 50%;
  box-shadow: var(--shadow-sm);
}
.toggle-switch input:checked + .toggle-slider {
  background: var(--brand-accent);
}
.toggle-switch input:focus-visible + .toggle-slider {
  outline: 2px solid var(--text);
  outline-offset: 2px;
}
.toggle-switch input:checked + .toggle-slider:before {
  transform: translateX(20px);
}

/* Narrower desktops: the links close up a little. */
@media (max-width: 1360px) {
  .nav-link { padding: .5rem .6rem; font-size: .92rem; }
}

/* The menu button takes over before the links would wrap or crowd (six links, two languages). */
@media (max-width: 1180px) {
  .desktop-nav,
  .header-actions .auth-buttons,
  .locale-switch {
    display: none;
  }

  .header-inner {
    justify-content: flex-start;
    gap: .5rem;
  }

  .header-actions {
    margin-inline-start: auto;
    gap: .45rem;
  }

  .user-trigger {
    padding: .3rem;
  }

  .user-trigger .user-name,
  .user-trigger .chevron {
    display: none;
  }

  .user-dropdown {
    right: 0;
    left: auto;
    width: min(280px, calc(100vw - 1rem));
  }

  .mobile-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .app-header {
    background: var(--bg);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
  }
}

@media (max-width: 640px) {
  .container {
    width: min(1200px, 94%);
  }

  .header-inner {
    min-height: 64px;
    width: 100%;
    padding: 0 .25rem;
  }

  .footer-links {
    grid-template-columns: 1fr;
    gap: 1.5rem;
  }

  .footer-bottom {
    flex-direction: column;
    text-align: center;
  }
}

/* ===== ANIMATIONS ===== */
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.app-header,
.app-footer {
  animation: fadeIn .4s ease forwards;
}
.custom-audio-wrapper {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background: color-mix(in oklab, var(--surface) 60%, transparent);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
  padding: 0.45rem 0.65rem;
  border-radius: var(--radius-full);
  width: 100%;
  min-width: 250px;
  max-width: 360px;
  container-type: inline-size;
  box-shadow: var(--shadow-sm), inset 0 1px 1px color-mix(in oklab, #ffffff 5%, transparent);
  transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.custom-audio-wrapper:hover {
  border-color: color-mix(in oklab, var(--a) 30%, var(--line));
  box-shadow: 0 8px 24px color-mix(in oklab, var(--surface2) 80%, transparent);
  transform: translateY(-1px);
}
.custom-audio-play {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--brand-accent);
  color: #fff;
  border: none;
  cursor: pointer;
  flex-shrink: 0;
  box-shadow: none;
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s ease;
}
.custom-audio-play:hover {
  transform: scale(1.1);
  box-shadow: 0 6px 16px color-mix(in oklab, var(--a) 50%, transparent), 0 0 0 3px color-mix(in oklab, var(--b) 20%, transparent);
}
.custom-audio-play:active {
  transform: scale(0.95);
}
.custom-audio-play svg {
  width: 15px;
  height: 15px;
  fill: currentColor;
  /* Centred by the icon's own drawing, so play and pause both sit in the middle. */
  margin-left: 0;
  transition: transform 0.2s ease;
}
.custom-audio-play.playing svg {
  margin-left: 0;
}
.custom-audio-time {
  font-family: var(--font-body);
  font-size: 0.74rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  color: var(--text);
  font-variant-numeric: tabular-nums;
  opacity: 0.85;
  flex-shrink: 0;
  min-width: 35px;
}
.custom-audio-time.end {
  text-align: right;
  color: var(--muted);
  opacity: 0.7;
}
.custom-audio-progress-container {
  flex: 1 1 92px;
  min-width: 92px;
  height: 24px;
  display: flex;
  align-items: center;
  cursor: pointer;
  position: relative;
  touch-action: none;
}
.custom-audio-track {
  width: 100%;
  height: 6px;
  background: color-mix(in oklab, var(--line) 40%, transparent);
  border-radius: 99px;
  position: relative;
  box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
}
.custom-audio-progress {
  position: absolute;
  top: 0;
  left: 0;
  bottom: 0;
  background: var(--brand-accent);
  width: 0%;
  border-radius: 99px;
  pointer-events: none;
}
.custom-audio-thumb {
  position: absolute;
  top: 50%;
  left: 0%;
  transform: translate(-50%, -50%);
  width: 14px;
  height: 14px;
  background: #fff;
  border-radius: 50%;
  box-shadow: 0 2px 6px rgba(0,0,0,0.3);
  pointer-events: none;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
  z-index: 2;
}
.custom-audio-progress-container:hover .custom-audio-thumb {
  transform: translate(-50%, -50%) scale(1.15);
  box-shadow: 0 3px 8px color-mix(in oklab, var(--a) 40%, transparent);
}
.custom-audio-progress-container:active .custom-audio-thumb {
  transform: translate(-50%, -50%) scale(1.3);
}
.custom-audio-menu {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 1px solid var(--line);
  background: color-mix(in oklab, var(--surface2) 80%, transparent);
  color: var(--text);
  cursor: pointer;
  flex-shrink: 0;
  transition: all .2s ease;
}
.custom-audio-menu:hover {
  background: color-mix(in oklab, var(--surface2) 88%, transparent);
  border-color: color-mix(in oklab, var(--a) 26%, var(--line));
  color: var(--text);
}
/* Download menu when the plan offers more than one file type. */
.audio-format-menu {
  position: fixed;
  z-index: 9999;
  min-width: 150px;
  padding: .35rem;
  border-radius: 12px;
  border: 1px solid var(--line);
  background: var(--surface, #fff);
  box-shadow: 0 12px 32px rgba(0,0,0,.18);
  display: grid;
  gap: 2px;
}
.audio-format-title {
  padding: .35rem .6rem .25rem;
  font-size: .72rem;
  font-weight: 700;
  color: var(--muted);
}
.audio-format-menu button {
  all: unset;
  box-sizing: border-box;
  padding: .5rem .6rem;
  border-radius: 8px;
  font-size: .85rem;
  font-weight: 600;
  color: var(--text);
  cursor: pointer;
  direction: ltr;
  text-align: start;
}
.audio-format-menu button:hover,
.audio-format-menu button:focus-visible {
  background: color-mix(in oklab, var(--a) 12%, transparent);
}
.custom-audio-menu svg {
  width: 15px;
  height: 15px;
  stroke: currentColor;
  stroke-width: 2;
  fill: none;
}
@@container (max-width: 280px) {
  .custom-audio-time {
    min-width: 35px;
    font-size: 0.72rem;
  }
  .custom-audio-menu {
    width: 24px;
    height: 24px;
  }
  .custom-audio-menu svg {
    width: 13px;
    height: 13px;
  }
  .custom-audio-progress-container {
    min-width: 82px;
  }
}
@@container (max-width: 230px) {
  .custom-audio-time.end {
    display: none;
  }
  .custom-audio-time {
    min-width: 30px;
    font-size: 0.7rem;
  }
  .custom-audio-progress-container {
    min-width: 74px;
  }
}
html[data-theme="light"] .custom-audio-wrapper {
  background: color-mix(in oklab, #ffffff 80%, transparent);
  border-color: color-mix(in oklab, var(--line) 80%, transparent);
  box-shadow: 0 4px 16px rgba(35,56,97,.06);
}
html[data-theme="light"] .custom-audio-wrapper:hover {
  box-shadow: 0 8px 24px rgba(35,56,97,.1);
}
@media (max-width: 640px) {
  .custom-audio-wrapper {
    max-width: 100%;
    min-width: 240px;
    padding: 0.4rem 0.6rem;
    gap: 0.5rem;
  }
}

html[dir="rtl"] .dropdown-item,
html[dir="rtl"] button.mobile-nav-link {
  text-align: right;
}

/* In RTL the user menu sits on the left side of header actions.
   Anchor dropdown to the left so it opens into the viewport. */
html[dir="rtl"] .user-dropdown {
  right: auto;
  left: 0;
}

html[dir="rtl"] .custom-audio-play svg {
  margin-left: 0;
  margin-right: 0;
}

html[dir="rtl"] .custom-audio-play.playing svg {
  margin-left: 0;
}

html[dir="rtl"] .custom-audio-time.end {
  text-align: right;
}

html[dir="rtl"] .custom-audio-progress {
  left: 0;
  right: auto;
}

html[dir="rtl"] .custom-audio-wrapper {
  direction: ltr;
}

html[dir="rtl"] .locale-switch {
  direction: ltr !important;
}

html[dir="rtl"] .mobile-locale-switch {
  direction: ltr !important;
  align-self: flex-start;
}

input[dir="auto"],
textarea[dir="auto"] {
  unicode-bidi: plaintext;
  text-align: start;
}
/* ===== LOCALE SWITCH TRANSITION ===== */
html.locale-switching,
body.locale-switching {
  background-color: var(--bg) !important;
}

body.locale-switching main,
body.locale-switching .app-footer,
body.locale-switching .mobile-nav-inner {
  opacity: 0 !important;
  transition: opacity .16s ease-out !important;
}

body.locale-fade-in main,
body.locale-fade-in .app-footer,
body.locale-fade-in .mobile-nav-inner {
  animation: localeFadeIn .25s ease-out forwards;
}

@keyframes localeFadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

/* ===== FORCE WESTERN/ENGLISH NUMERALS ===== */
/* Ensure prices, stats, and numeric values always display as 0-9 not ٠-٩ */
html[dir="rtl"] .price-amount,
html[dir="rtl"] .price-currency,
html[dir="rtl"] .price-note,
html[dir="rtl"] .plan-quota,
html[dir="rtl"] .feature-value,
html[dir="rtl"] .kpi-value,
html[dir="rtl"] .stat-value,
html[dir="rtl"] .usage-text,
html[dir="rtl"] .billing-savings,
html[dir="rtl"] .pagination-btn,
html[dir="rtl"] .pagination-info {
  font-variant-numeric: tabular-nums;
}
</style>
