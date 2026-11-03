{{-- The homepage's Agents section: what a voice agent does for a business, shown working,
     with a real call with Listen's own agent when it is on the page. --}}
<section class="section agl agl-home" data-agl-reveal aria-labelledby="agentsHomeTitle">
  <div class="agl-home-head" data-agl-rise>
    <div>
      <h2 id="agentsHomeTitle">{{ __('agents_landing.home_title') }}</h2>
      <p class="section-lead">{{ __('agents_landing.home_text') }}</p>
    </div>
    <div class="agl-cta-row">
      <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ route('agents-landing') }}">{{ __('agents_landing.home_cta') }}</a>
      <button type="button" class="ag-btn ag-btn-lg agl-live-btn" data-agl-live hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>{{ __('agents_landing.home_talk') }}
      </button>
    </div>
  </div>
  <x-agents.bento />
</section>
