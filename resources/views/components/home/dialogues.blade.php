{{-- Two-voice dialogues in the page language, shown as a script: each line has its speaker's
     avatar and name, and carries when it is spoken, so it lights up in time with the voice. --}}
@php
  $vsDialogues = $showcaseDialogues ?? [];
@endphp
@if(!empty($vsDialogues))
<section class="section vs-dialogues" id="dialogues">
  <div class="vs-head">
    <div>
      <h2 class="vs-title">{{ __('ui.landing.dialogue_title') }}</h2>
      <p class="vs-lead">{{ __('ui.landing.dialogue_body') }}</p>
    </div>
  </div>
  <div class="vs-dialogue-grid">
    @foreach($vsDialogues as $i => $dialogue)
      @include('components.home.dialogue-card', ['dialogue' => $dialogue, 'cardClass' => $i >= 4 ? ' vs-more' : ''])
    @endforeach
  </div>
  <div class="vs-more-wrap"><a class="vs-more-btn" href="{{ route('samples') }}#conversations">{{ __('ui.landing.dialogue_more') }}</a></div>
</section>
@endif
