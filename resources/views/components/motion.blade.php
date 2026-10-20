{{-- Smooth scrolling and the cards' entrance on the public pages: Lenis eases the wheel and
     in-page links (sticky bars allowed for), GSAP's ScrollTrigger brings each grid's cards in
     one after another. Self-hosted (public/vendor/motion). Nothing moves for a visitor who asks
     for less motion, and touch scrolling stays the phone's own. Never on the studio or admin. --}}
@php $motionFile = fn (string $f): string => asset('vendor/motion/'.$f).'?v='.(is_file(public_path('vendor/motion/'.$f)) ? filemtime(public_path('vendor/motion/'.$f)) : '1'); @endphp
<link rel="stylesheet" href="{{ $motionFile('lenis.css') }}">
<style>
  /* Cards wait, unseen, only once the script has said it will bring them in. */
  html.motion-on :is(.vs-card, .vs-long, .vs-env, .vs-dialogue, .plan-card) { opacity: 0; transform: translateY(26px); }
</style>
<script src="{{ $motionFile('lenis.min.js') }}" defer></script>
<script src="{{ $motionFile('gsap.min.js') }}" defer></script>
<script src="{{ $motionFile('ScrollTrigger.min.js') }}" defer></script>
<script>
(function () {
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced) return;
  var CARDS = '.vs-card, .vs-long, .vs-env, .vs-dialogue, .plan-card';
  // Marked before the first paint, so no card shows and then jumps back.
  document.documentElement.classList.add('motion-on');
  // If the scripts never arrive, the cards show anyway.
  var failsafe = setTimeout(function () { document.documentElement.classList.remove('motion-on'); }, 4000);

  window.addEventListener('DOMContentLoaded', function () {
    var cards = Array.prototype.slice.call(document.querySelectorAll(CARDS));
    cards.forEach(function (c) { c.setAttribute('data-motion-card', ''); });
    if (!window.gsap || !window.ScrollTrigger) { clearTimeout(failsafe); document.documentElement.classList.remove('motion-on'); return; }
    clearTimeout(failsafe);
    gsap.registerPlugin(ScrollTrigger);

    // The wheel, eased; touch keeps the phone's own scrolling. Lists and text boxes that scroll
    // on their own (the long reads' text, menus) keep their wheel.
    var lenis = null;
    if (window.Lenis) {
      lenis = new Lenis({ lerp: 0.11, wheelMultiplier: 1, smoothWheel: true, syncTouch: false, allowNestedScroll: true, anchors: false });
      lenis.on('scroll', ScrollTrigger.update);
      gsap.ticker.add(function (time) { lenis.raf(time * 1000); });
      gsap.ticker.lagSmoothing(0);
      window.ListenLenis = lenis;
      // In-page links glide, and stop below the sticky header and section bar.
      document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href^="#"]');
        if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey) return;
        var id = a.getAttribute('href').slice(1);
        var target = id && document.getElementById(id);
        if (!target) return;
        e.preventDefault();
        var offset = parseFloat(getComputedStyle(target).scrollMarginTop) || 90;
        lenis.scrollTo(target, { offset: -offset, duration: 1.1 });
        history.replaceState(null, '', '#' + id);
      });
      // Menus and dialogs that open over the page hold it still.
      new MutationObserver(function () {
        var locked = document.documentElement.style.overflow === 'hidden' || document.body.style.overflow === 'hidden';
        if (locked) lenis.stop(); else lenis.start();
      }).observe(document.body, { attributes: true, attributeFilter: ['style', 'class'] });
    }

    // Each grid's cards come in one after another as they reach the screen.
    gsap.set('[data-motion-card]', { opacity: 0, y: 26 });
    document.documentElement.classList.remove('motion-on');
    ScrollTrigger.batch('[data-motion-card]', {
      start: 'top 90%',
      once: true,
      onEnter: function (batch) {
        batch.forEach(function (c) { c.setAttribute('data-motion-done', ''); });
        gsap.to(batch, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out', stagger: 0.08, overwrite: true, clearProps: 'transform,opacity' });
      },
    });
    // Cards shown or hidden by a filter (dialects, moods) take their place again.
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('[data-vp-region], details summary, .billing-toggle, [role="tab"]')) {
        requestAnimationFrame(function () {
          // A card a filter has just shown, never seen before, comes in now.
          var shown = Array.prototype.filter.call(document.querySelectorAll('[data-motion-card]:not([data-motion-done])'), function (c) { return c.offsetParent !== null && c.getBoundingClientRect().top < window.innerHeight; });
          shown.forEach(function (c) { c.setAttribute('data-motion-done', ''); });
          if (shown.length) gsap.to(shown, { opacity: 1, y: 0, duration: 0.5, ease: 'power3.out', stagger: 0.04, overwrite: true, clearProps: 'transform,opacity' });
          ScrollTrigger.refresh();
        });
      }
    });
    window.addEventListener('load', function () { ScrollTrigger.refresh(); });
  });
})();
</script>
