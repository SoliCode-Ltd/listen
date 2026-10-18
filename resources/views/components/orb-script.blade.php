{{-- Draws the voice orbs on the page (components/orb). --}}
@php $orbScript = public_path('js/listen-orbs.js'); @endphp
<script src="{{ asset('js/listen-orbs.js') }}?v={{ is_file($orbScript) ? filemtime($orbScript) : '1' }}" defer></script>
