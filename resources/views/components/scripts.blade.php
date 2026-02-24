<script id="listen-shared-scripts">
(function() {
  function safeGetTheme() {
    try {
      return localStorage.getItem('listen_theme');
    } catch (e) {
      return null;
    }
  }

  function safeSetTheme(theme) {
    try {
      localStorage.setItem('listen_theme', theme);
    } catch (e) {}
  }

  var serverDefault = document.documentElement.getAttribute('data-theme') || 'dark';
  var savedTheme = safeGetTheme();
  document.documentElement.setAttribute(
    'data-theme',
    savedTheme || serverDefault
  );

  function initThemeToggle() {
    var themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;

    themeToggle.addEventListener('click', function() {
      var current = document.documentElement.getAttribute('data-theme') || 'dark';
      var next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      safeSetTheme(next);
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

    function setMobileMenuState(isOpen) {
      if (isOpen) {
        mobileNav.classList.add('open');
        document.documentElement.classList.add('mobile-menu-open');
        document.body.classList.add('mobile-menu-open');
      } else {
        mobileNav.classList.remove('open');
        document.documentElement.classList.remove('mobile-menu-open');
        document.body.classList.remove('mobile-menu-open');
      }
      mobileToggle.setAttribute('aria-expanded', String(isOpen));
      mobileNav.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }

    function closeMobileMenu(focusToggle) {
      setMobileMenuState(false);
      if (focusToggle) {
        mobileToggle.focus();
      }
    }

    setMobileMenuState(false);

    mobileToggle.addEventListener('click', function() {
      setMobileMenuState(!mobileNav.classList.contains('open'));
    });

    var mobileLinks = mobileNav.querySelectorAll('a, button');
    Array.prototype.forEach.call(mobileLinks, function(link) {
      link.addEventListener('click', function() {
        closeMobileMenu(false);
      });
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
        closeMobileMenu(true);
      }
    });

    window.addEventListener('resize', function() {
      if (window.innerWidth > 960 && mobileNav.classList.contains('open')) {
        closeMobileMenu(false);
      }
    });
  }

  function initRevealAnimations() {
    if (!('IntersectionObserver' in window)) {
      var revealFallback = document.querySelectorAll('.reveal');
      Array.prototype.forEach.call(revealFallback, function(el) {
        el.classList.add('show');
      });
      return;
    }

    var observer = new IntersectionObserver(function(entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) {
          entries[i].target.classList.add('show');
        }
      }
    }, { threshold: 0.12 });

    var revealElements = document.querySelectorAll('.reveal');
    Array.prototype.forEach.call(revealElements, function(el) {
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
