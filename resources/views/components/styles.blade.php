<style id="listen-shared-styles">
:root {
  --bg: #070910;
  --surface: #11182a;
  --surface2: #15213a;
  --line: #2b3a5d;
  --text: #f4f7ff;
  --muted: #9fb0d4;
  --a: #fb8308;
  --b: #5906b2;
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
}

* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
body {
  font-family: Inter, system-ui, -apple-system, sans-serif;
  background: radial-gradient(1200px 500px at 92% -20%, color-mix(in oklab,var(--b) 25%,transparent), transparent 60%),
              radial-gradient(900px 420px at -5% 0%, color-mix(in oklab,var(--a) 18%,transparent), transparent 58%),
              var(--bg);
  color: var(--text);
  min-height: 100vh;
}

.container { width: min(1200px, 92%); margin: 0 auto; }

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
  gap: .4rem;
  padding: .55rem 1rem;
  border-radius: var(--radius);
  font-size: .88rem;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  transition: all .2s ease;
  border: 1px solid var(--line);
  background: var(--surface);
  color: var(--text);
}

.btn:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-sm);
}

.btn-primary {
  background: linear-gradient(135deg, var(--a), var(--b));
  border: none;
  color: #fff;
}

.btn-primary:hover {
  box-shadow: 0 8px 24px color-mix(in oklab, var(--b) 35%, transparent);
}

.btn-ghost {
  background: transparent;
  border-color: transparent;
}

.btn-ghost:hover {
  background: color-mix(in oklab, var(--surface) 50%, transparent);
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
}

.btn-icon .icon {
  width: 1.05rem;
  height: 1.05rem;
  stroke: currentColor;
  fill: none;
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
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow);
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
  background: linear-gradient(180deg, var(--surface2), var(--surface));
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
  border: none;
  background: none;
  cursor: pointer;
  position: relative;
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
  top: 72px;
  left: 0;
  right: 0;
  bottom: 0;
  background: color-mix(in oklab, var(--bg) 95%, transparent);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  z-index: 99;
  overflow-y: auto;
  opacity: 0;
  visibility: hidden;
  transition: all .3s ease;
}

.mobile-nav.open {
  opacity: 1;
  visibility: visible;
}

.mobile-nav-inner {
  display: flex;
  flex-direction: column;
  padding: 1rem;
  gap: .25rem;
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

@media (max-width: 960px) {
  .desktop-nav,
  .header-actions .auth-buttons,
  .user-menu {
    display: none;
  }

  .mobile-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .mobile-nav {
    display: block;
  }
}

@media (max-width: 640px) {
  .header-inner {
    min-height: 64px;
  }

  .footer-links {
    grid-template-columns: 1fr;
    gap: 1.5rem;
  }

  .footer-bottom {
    flex-direction: column;
    text-align: center;
  }

  .mobile-nav {
    top: 64px;
  }
}

/* ===== ANIMATIONS ===== */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.app-header,
.app-footer {
  animation: fadeIn .4s ease forwards;
}
</style>
