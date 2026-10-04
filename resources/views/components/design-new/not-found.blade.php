@extends('components.design-new.system-layout', ['ntPage' => 'not_found', 'ntTitle' => __('design.system.h404')])
@section('nt-system-content')
  @php
    $ntHelp = trim((string) \App\Models\SiteSetting::get('contact_email', '')) ?: 'help@listenai.studio';
    $ntBars = [];
    for ($i = 0; $i < 40; $i++) {
      $d = abs($i - 19.5) / 19.5;
      $ntBars[] = ['h' => $d < 0.35 ? 4 : (int) round(8 + 70 * ($d - 0.35) * abs(sin($i * 1.7))), 'flat' => $d < 0.35];
    }
    $ntLoc = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';
  @endphp
  <div class="nt-flatline" aria-hidden="true">
    @foreach($ntBars as $bar)<span class="{{ $bar['flat'] ? 'is-flat' : '' }}" style="height: {{ $bar['h'] }}px"></span>@endforeach
  </div>
  <span class="nt-pill">404</span>
  <h1 class="nt-h2 nt-system-title">{{ __('design.system.h404') }}</h1>
  <p class="nt-lead">{{ __('design.system.b404') }}</p>
  <div class="nt-actions">
    <a class="btn btn-primary" href="{{ url('/'.$ntLoc) }}">{{ __('design.system.home') }}</a>
    <a class="btn" href="{{ url('/'.$ntLoc.'/demo') }}">{{ __('design.system.demo') }}</a>
  </div>
  <p class="nt-small">{{ __('design.system.help') }} <a class="nt-link" href="mailto:{{ $ntHelp }}">{{ $ntHelp }}</a></p>
@endsection
