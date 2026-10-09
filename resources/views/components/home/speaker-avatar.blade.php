{{-- A speaker's round avatar with a small flag badge for their dialect or accent. --}}
@props(['avatar' => null, 'name' => '', 'flag' => null, 'size' => null, 'class' => ''])
<span class="vs-avatar-photo{{ $size ? ' vs-avatar-'.$size : '' }} {{ $class }}">
  @if($avatar)
    <img src="{{ $avatar }}" alt="" width="64" height="64" loading="lazy" decoding="async">
  @else
    <span class="vs-avatar-initial">{{ mb_strtoupper(mb_substr(trim((string) $name) ?: '•', 0, 1)) }}</span>
  @endif
  @if($flag !== false)
    <x-home.flag :url="$flag" size="badge" />
  @endif
</span>
