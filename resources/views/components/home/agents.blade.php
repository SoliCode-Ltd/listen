{{-- The homepage's Agents section, set like the homepage's other sections: the title, a line and
     what a voice agent does, centred; a real call with Listen's own agent, recorded live, the voice on
     one side and the words on the other; then its two buttons. --}}
<section class="section agl agl-home" aria-labelledby="agentsHomeTitle">
  <div class="agl-home-head" data-agl-rise>
    <div>
      <h2 id="agentsHomeTitle">{{ __('agents_landing.home_title') }}</h2>
      <p class="section-lead">{{ __('agents_landing.home_text') }}</p>
    </div>
    <ul class="agl-home-facts">
      @foreach(__('agents_landing.home_facts') as $i => $fact)
        <li>
          <svg viewBox="0 0 24 24" aria-hidden="true">{!! [
            '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
            '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
          ][$i] ?? '' !!}</svg>
          <span>{{ $fact }}</span>
        </li>
      @endforeach
    </ul>
  </div>
  <div class="agl-real-wrap">
    <x-agents.real-call size="wide" data-agl-rise style="--rise-d: .1s" />
  </div>
  {{-- After the example: where to learn more, and a real call with Listen's own agent when it is on the page. --}}
  <div class="agl-cta-row agl-home-cta">
    <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ route('agents-landing') }}">{{ __('agents_landing.home_cta') }}</a>
    <button type="button" class="ag-btn ag-btn-lg agl-live-btn" data-agl-live hidden>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>{{ __('agents_landing.home_talk') }}
    </button>
  </div>
</section>
