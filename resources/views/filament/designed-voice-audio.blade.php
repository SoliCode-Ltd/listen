{{-- A designed voice's take, in the admin's list (DesignedVoiceResource). --}}
@php $url = \App\Support\AudioUrl::fromPath($getState()); @endphp
@if($url)
  <audio controls preload="none" src="{{ $url }}" style="height: 32px; max-width: 220px;"></audio>
@else
  <span class="text-gray-400">—</span>
@endif
