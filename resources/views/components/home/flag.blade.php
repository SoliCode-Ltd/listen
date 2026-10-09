{{-- Round flag for a voice, or the Arabic letter for voices without one country (Fusha, Arabic-accent English). --}}
@props(['url' => null, 'size' => null])
@if($url)
  <span class="vs-flag{{ $size ? ' vs-flag-'.$size : '' }}"><img src="{{ $url }}" alt="" width="24" height="24" loading="lazy" decoding="async"></span>
@else
  <span class="vs-flag vs-flag-ar{{ $size ? ' vs-flag-'.$size : '' }}" aria-hidden="true">ع</span>
@endif
