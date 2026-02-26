<style id="listen-shared-styles">
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700;800;900&display=swap');

:root {
  --font-display: 'Outfit', sans-serif;
  --font-body: 'Inter', sans-serif;
  --bg: #070910;
  --surface: #11182a;
  --surface2: #15213a;
  --line: #2b3a5d;
  --text: #f4f7ff;
  --muted: #9fb0d4;
  --a: #FF4433;
  --b: #8A2BE2;
  --brand-accent: #FF4433;
  --brand-accent-glow: rgba(255, 68, 51, 0.4);
  --ok: #32c58d;
  --danger: #ee4a77;
  --shadow: 0 16px 46px rgba(0,0,0,.30);
  --shadow-sm: 0 4px 12px rgba(0,0,0,.20);
  --radius: .72rem;
  --radius-lg: 1rem;
  --radius-full: 9999px;
}
html[data-theme="light"] {
  --bg: #eff3ff;
  --surface: #fff;
  --surface2: #f7f9ff;
  --line: #d8e2f1;
  --text: #121a2d;
  --muted: #5b6c89;
  --shadow: 0 14px 34px rgba(35,56,97,.13);
  --shadow-sm: 0 2px 8px rgba(35,56,97,.08);
  /* Make the mesh gradients much softer in light mode for a sleek look */
  --a: #ff786b;
  --b: #a461e8;
  --brand-accent-glow: rgba(255, 68, 51, 0.15);
}

* { box-sizing: border-box; }
html, body {
  margin: 0;
  padding: 0;
  max-width: 100%;
}
html.mobile-menu-open,
body.mobile-menu-open {
  overflow: hidden;
}
body {
  font-family: var(--font-body);
  background: radial-gradient(1200px 500px at 92% -20%, color-mix(in oklab,var(--b) 25%,transparent), transparent 60%),
              radial-gradient(900px 420px at -5% 0%, color-mix(in oklab,var(--a) 18%,transparent), transparent 58%),
              var(--bg);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

.container { width: min(1200px, 92%); margin: 0 auto; }

img, svg, video, canvas, iframe {
  max-width: 100%;
  height: auto;
}

h1, h2, h3, h4, h5, h6, .font-display {
  font-family: var(--font-display);
  font-weight: 800;
  letter-spacing: -0.04em;
  line-height: 1.1;
}

h1 {
  font-size: clamp(2.5rem, 5vw, 4rem);
  font-weight: 900;
  letter-spacing: -0.05em;
  line-height: 1.05;
}

/* Recolor the hero graphic to match the new vibrant brand palette (#FF4433, #8A2BE2) */
.hero-art img {
  /* Removed hue shift as new graphic matches native palette */
}

/* ===== HEADER STYLES ===== */
.app-header {
  position: sticky;
  top: 0;
  z-index: 100;
  background: color-mix(in oklab, var(--bg) 88%, transparent);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border-bottom: 1px solid var(--line);
}

.header-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  min-height: 72px;
  width: min(1400px, 94%);
  margin: 0 auto;
  padding: 0 .5rem;
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

.brand-icon {
  width: 1.6rem;
  height: 1.6rem;
  display: flex;
  align-items: center;
  justify-content: center;
}

.brand-icon svg {
  width: 100%;
  height: 100%;
  fill: currentColor;
}

.brand-text {
  background: linear-gradient(135deg, var(--a), var(--b));
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
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
  padding: .5rem .85rem;
  border-radius: var(--radius);
  font-size: .9rem;
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

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-body);
  font-weight: 600;
  font-size: 0.95rem;
  padding: 0.85rem 1.8rem;
  border-radius: 99px;
  border: 1px solid var(--line);
  background: color-mix(in oklab, var(--surface) 80%, transparent);
  color: var(--text);
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  text-decoration: none;
  line-height: Math(1.2);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 24px color-mix(in oklab, var(--line) 40%, transparent);
}

.btn-primary {
  background: linear-gradient(135deg, var(--a), var(--b));
  border: none;
  color: #fff;
  box-shadow: 0 4px 12px color-mix(in oklab, var(--a) 25%, transparent);
}

.btn-primary:hover {
  box-shadow: 0 12px 32px color-mix(in oklab, var(--a) 45%, transparent), 0 0 0 2px color-mix(in oklab, var(--b) 30%, transparent);
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
  border-radius: var(--radius);
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
  background: linear-gradient(135deg, color-mix(in oklab, var(--a) 45%, transparent), color-mix(in oklab, var(--b) 45%, transparent));
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
  max-width: 120px;
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
  width: 240px;
  max-width: calc(100vw - 1rem);
  background: color-mix(in oklab, var(--surface) 80%, transparent);
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
  background: linear-gradient(135deg, color-mix(in oklab, var(--a) 50%, transparent), color-mix(in oklab, var(--b) 50%, transparent));
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
  font-size: .88rem;
  margin-bottom: .1rem;
}

.dropdown-user-info span {
  font-size: .78rem;
  color: var(--muted);
}

.dropdown-divider {
  height: 1px;
  background: var(--line);
  margin: .25rem 0;
}

.dropdown-item {
  display: flex;
  align-items: center;
  gap: .65rem;
  padding: .7rem 1rem;
  font-size: .88rem;
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
  border-radius: var(--radius);
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

.mobile-user {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: 1rem;
  background: var(--surface);
  border-radius: var(--radius);
  border: 1px solid var(--line);
}

.mobile-user-avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--radius-full);
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, color-mix(in oklab, var(--a) 50%, transparent), color-mix(in oklab, var(--b) 50%, transparent));
  font-size: .95rem;
  font-weight: 700;
  color: #fff;
  overflow: hidden;
}

.mobile-user-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.mobile-user-info strong {
  display: block;
  font-size: .9rem;
}

.mobile-user-info span {
  font-size: .8rem;
  color: var(--muted);
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
  background: color-mix(in oklab, var(--bg) 95%, var(--surface));
  border-top: 1px solid var(--line);
  margin-top: auto;
}

.footer-container {
  width: min(1200px, 92%);
  margin: 0 auto;
  padding: 3rem 0 1.5rem;
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
  font-size: .88rem;
  line-height: 1.6;
  color: var(--muted);
  max-width: 280px;
}

.footer-links {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
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
  font-size: .88rem;
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

/* ===== CONTACT PAGE STYLES ===== */
.contact-page {
  padding-top: 4rem;
}

.contact-hero {
  position: relative;
  padding: 4rem 1rem 6rem;
  overflow: hidden;
}

.hero-bg-accent {
  position: absolute;
  top: -40%;
  left: 50%;
  transform: translateX(-50%);
  width: 100vw;
  height: 100vw;
  max-width: 1200px;
  max-height: 1200px;
  background: radial-gradient(circle, color-mix(in oklab, var(--b) 25%, transparent) 0%, transparent 50%);
  z-index: -1;
  pointer-events: none;
  animation: heroPulse 10s ease-in-out infinite alternate;
}

@keyframes heroPulse {
  0% { opacity: 0.6; transform: translateX(-50%) scale(0.95) translateY(0); }
  100% { opacity: 1; transform: translateX(-50%) scale(1.05) translateY(20px); }
}

.contact-form-card {
  background: color-mix(in oklab, var(--surface) 65%, transparent);
  backdrop-filter: blur(32px);
  -webkit-backdrop-filter: blur(32px);
  border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
  border-radius: 1.5rem;
  padding: 3.5rem;
  box-shadow: 0 32px 64px rgba(0,0,0,0.4), 
              inset 0 1px 1px color-mix(in oklab, var(--text) 5%, transparent),
              0 0 0 1px color-mix(in oklab, var(--surface) 50%, transparent);
  margin-top: -3rem;
  position: relative;
  z-index: 2;
  transition: border-color .4s ease, box-shadow .4s ease, transform .4s ease;
}

.contact-form-card:hover {
  border-color: color-mix(in oklab, var(--a) 40%, var(--line));
  box-shadow: 0 32px 80px rgba(0,0,0,0.5), 
              0 0 40px color-mix(in oklab, var(--a) 15%, transparent),
              inset 0 1px 1px color-mix(in oklab, var(--text) 5%, transparent);
  transform: translateY(-4px);
}

.styled-form .form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.5rem;
  margin-bottom: 1.75rem;
}

.styled-form .form-grid .form-group {
  margin-bottom: 0;
}

.styled-form .form-group {
  margin-bottom: 1.75rem;
  position: relative;
}

.styled-form label {
  display: block;
  font-size: 0.85rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted);
  margin-bottom: 0.6rem;
  transition: color 0.3s ease;
}

.styled-form .form-group:focus-within label {
  color: var(--a);
}

.styled-form .form-control {
  width: 100%;
  padding: 1rem 1.15rem;
  font-family: var(--font-body);
  font-size: 1.05rem;
  color: var(--text);
  background: color-mix(in oklab, var(--surface2) 40%, transparent);
  border: 2px solid var(--line);
  border-radius: var(--radius);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
}

.styled-form .form-control:focus {
  outline: none;
  border-color: var(--b);
  box-shadow: 0 0 0 4px color-mix(in oklab, var(--b) 15%, transparent), inset 0 2px 4px rgba(0,0,0,0.1);
  background: color-mix(in oklab, var(--surface) 80%, transparent);
  transform: translateY(-2px);
}

.styled-form .form-control::placeholder {
  color: var(--muted);
  opacity: 0.6;
}

.contact-submit-btn {
  margin-top: 2rem;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 1.15rem;
  font-size: 1.1rem;
  font-weight: 700;
  border-radius: var(--radius-lg);
  position: relative;
  overflow: hidden;
  z-index: 1;
}

.contact-submit-btn::before {
  content: '';
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: linear-gradient(135deg, var(--b), var(--a));
  opacity: 0;
  z-index: -1;
  transition: opacity 0.4s ease;
}

.contact-submit-btn:hover::before {
  opacity: 1;
}

.btn-icon-right {
  width: 1.25rem;
  height: 1.25rem;
  stroke: currentColor;
  stroke-width: 2;
  fill: none;
  transition: transform 0.3s ease;
}

.contact-submit-btn:hover .btn-icon-right {
  transform: translateX(4px) translateY(-4px);
}

/* Animations */
.animate-in {
  opacity: 0;
  transform: translateY(20px);
  animation: fadeUpIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

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
  background: linear-gradient(135deg, var(--a), var(--b));
}
.toggle-switch input:focus-visible + .toggle-slider {
  outline: 2px solid var(--text);
  outline-offset: 2px;
}
.toggle-switch input:checked + .toggle-slider:before {
  transform: translateX(20px);
}

@media (max-width: 960px) {
  .desktop-nav,
  .header-actions .auth-buttons,
  .user-menu {
    display: none;
  }

  .header-inner {
    justify-content: flex-start;
    gap: .5rem;
  }

  .header-actions {
    margin-left: auto;
    gap: .45rem;
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
    width: min(1400px, 96%);
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

  .styled-form .form-grid {
    grid-template-columns: 1fr;
    gap: 1.25rem;
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
  gap: 0.9rem;
  background: color-mix(in oklab, var(--surface) 60%, transparent);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
  padding: 0.5rem 0.9rem;
  border-radius: var(--radius-full);
  width: 100%;
  max-width: 360px;
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
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--a), var(--b));
  color: #fff;
  border: none;
  cursor: pointer;
  flex-shrink: 0;
  box-shadow: 0 4px 12px color-mix(in oklab, var(--a) 35%, transparent);
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
  margin-left: 2px;
  transition: transform 0.2s ease;
}
.custom-audio-play.playing svg {
  margin-left: 0;
}
.custom-audio-time {
  font-family: var(--font-body);
  font-size: 0.78rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  color: var(--text);
  font-variant-numeric: tabular-nums;
  opacity: 0.85;
  flex-shrink: 0;
  min-width: 34px;
}
.custom-audio-time.end {
  text-align: right;
  color: var(--muted);
  opacity: 0.7;
}
.custom-audio-progress-container {
  flex-grow: 1;
  height: 8px;
  background: color-mix(in oklab, var(--line) 40%, transparent);
  border-radius: 99px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
}
.custom-audio-progress {
  position: absolute;
  top: 0;
  left: 0;
  bottom: 0;
  background: linear-gradient(90deg, var(--a), var(--b));
  width: 0%;
  border-radius: 99px;
  pointer-events: none;
  transition: width 0.1s linear;
}
.custom-audio-progress::after {
  content: '';
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: 4px;
  background: rgba(255, 255, 255, 0.8);
  border-radius: 50%;
  box-shadow: -2px 0 4px rgba(0,0,0,0.2);
}
.custom-audio-menu {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 1px solid transparent;
  background: transparent;
  color: var(--muted);
  cursor: pointer;
  flex-shrink: 0;
  transition: all .2s ease;
}
.custom-audio-menu:hover {
  background: color-mix(in oklab, var(--surface2) 80%, transparent);
  border-color: var(--line);
  color: var(--text);
}
.custom-audio-menu svg {
  width: 15px;
  height: 15px;
  stroke: currentColor;
  stroke-width: 2;
  fill: none;
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
    padding: 0.4rem 0.7rem;
    gap: 0.6rem;
  }
}
input[dir="auto"],
textarea[dir="auto"] {
  unicode-bidi: plaintext;
  text-align: start;
}
</style>
