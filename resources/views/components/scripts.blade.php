<script id="listen-shared-scripts">
(function() {
  var savedTheme = localStorage.getItem('listen_theme');
  var prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
  document.documentElement.setAttribute('data-theme', savedTheme || (prefersLight ? 'light' : 'dark'));

  function initThemeToggle() {
    var themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;

    themeToggle.addEventListener('click', function() {
      var current = document.documentElement.getAttribute('data-theme') || 'dark';
      var next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    });
  }

  function initUserMenu() {
    var userMenuTrigger = document.getElementById('userMenuTrigger');
    var userMenu = document.getElementById('userMenu');
    if (!userMenuTrigger || !userMenu) return;

    userMenuTrigger.addEventListener('click', function(e) {
      e.stopPropagation();
      var isOpen = userMenu.classList.toggle('open');
      userMenuTrigger.setAttribute('aria-expanded', isOpen);
    });

    document.addEventListener('click', function(e) {
      if (!userMenu.contains(e.target)) {
        userMenu.classList.remove('open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && userMenu.classList.contains('open')) {
        userMenu.classList.remove('open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
        userMenuTrigger.focus();
      }
    });
  }

  function initMobileMenu() {
    var mobileToggle = document.getElementById('mobileMenuToggle');
    var mobileNav = document.getElementById('mobileNav');
    if (!mobileToggle || !mobileNav) return;

    mobileToggle.addEventListener('click', function() {
      var isOpen = mobileNav.classList.toggle('open');
      mobileToggle.setAttribute('aria-expanded', isOpen);
      mobileNav.setAttribute('aria-hidden', !isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
    });

    var mobileLinks = mobileNav.querySelectorAll('a, button');
    mobileLinks.forEach(function(link) {
      link.addEventListener('click', function() {
        mobileNav.classList.remove('open');
        mobileToggle.setAttribute('aria-expanded', 'false');
        mobileNav.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
      });
    });
  }

  function initRevealAnimations() {
    var observer = new IntersectionObserver(function(entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) {
          entries[i].target.classList.add('show');
        }
      }
    }, { threshold: 0.12 });

    var revealElements = document.querySelectorAll('.reveal');
    revealElements.forEach(function(el) {
      observer.observe(el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      initThemeToggle();
      initUserMenu();
      initMobileMenu();
      initRevealAnimations();
    });
  } else {
    initThemeToggle();
    initUserMenu();
    initMobileMenu();
    initRevealAnimations();
  }
})();
</script>
