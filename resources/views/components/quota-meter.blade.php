{{-- What is left of an allowance, as Voice Design and voice cloning show it at the top of their
     sections: a ring that empties as it is used, "2 of 3 left", and what it counts below.
     $left, $max: the numbers. $value: the line with :left and :max in it. $caption: what it counts.
     Pages update it with window.ListenQuota(element, left, max) as voices come and go. --}}
@props(['left', 'max', 'value', 'caption'])
@php
  $left = max(0, (int) $left);
  $max = max(0, (int) $max);
@endphp
<div {{ $attributes->merge(['class' => 'quota-meter'.($left === 0 ? ' is-empty' : '')]) }} data-quota data-quota-template="{{ $value }}" style="--quota: {{ $max > 0 ? round(min(1, $left / $max), 4) : 0 }};">
  <span class="quota-ring" aria-hidden="true"></span>
  <span class="quota-text">
    <strong data-quota-value>{{ str_replace([':left', ':max'], [$left, $max], $value) }}</strong>
    <small>{{ $caption }}</small>
  </span>
</div>
@once
  <style>
    .quota-meter {
      display: inline-flex; align-items: center; gap: .7rem; min-width: 0;
      padding-block: .45rem; padding-inline: .5rem 1rem; border-radius: 999px;
      border: 1px solid var(--line); background: var(--surface2, var(--surface));
    }
    /* The ring: the share still left, in the accent, going round from the top. */
    .quota-ring {
      position: relative; width: 36px; height: 36px; flex: none; border-radius: 50%;
      background: conic-gradient(var(--a, var(--brand-accent)) calc(var(--quota, 0) * 1turn), color-mix(in oklab, var(--line) 85%, var(--muted)) 0);
      -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 5px), #000 calc(100% - 4.5px));
      mask: radial-gradient(farthest-side, transparent calc(100% - 5px), #000 calc(100% - 4.5px));
      transition: background .4s ease;
    }
    .quota-text { display: grid; min-width: 0; }
    .quota-text strong { font-size: .95rem; font-weight: 700; line-height: 1.45; color: var(--text); white-space: nowrap; }
    .quota-text small { font-size: .76rem; line-height: 1.5; color: var(--muted); white-space: nowrap; }
    .quota-meter.is-empty .quota-text strong { color: var(--muted); }
    @media (prefers-reduced-motion: reduce) { .quota-ring { transition: none; } }
  </style>
  <script>
    // Sets what is left on a quota meter (components/quota-meter.blade.php).
    window.ListenQuota = function (el, left, max) {
      if (!el) return;
      left = Math.max(0, Number(left) || 0);
      max = Math.max(0, Number(max) || 0);
      el.style.setProperty('--quota', String(max > 0 ? Math.min(1, left / max) : 0));
      el.classList.toggle('is-empty', left === 0);
      var value = el.querySelector('[data-quota-value]');
      if (value) value.textContent = String(el.getAttribute('data-quota-template') || ':left / :max').replace(':left', String(left)).replace(':max', String(max));
    };
  </script>
@endonce
