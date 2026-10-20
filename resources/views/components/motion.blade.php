{{-- Scrolling on the public pages: the browser's own, so it is never delayed or jumpy. In-page
     links glide to their section (stopping below the sticky bars through each section's
     scroll-margin), except for a visitor who asks for less motion. Cards show as they are:
     no entrance effect. --}}
<style>
  @media (prefers-reduced-motion: no-preference) {
    html { scroll-behavior: smooth; }
  }
</style>
