{{-- The voice tour: one orb for each kind of voice Listen makes. Picking an orb lifts it and plays its
     sample (through the shared voice player), and the caption below says what it is. --}}
@if(! empty($voiceTour))
  <section class="section tour" aria-labelledby="tourTitle">
    <div class="container">
      <h2 id="tourTitle">{{ __('ui.landing.tour_title') }}</h2>
      <p class="section-lead">{{ __('ui.landing.tour_lead') }}</p>

      <ul class="tour-row" data-tour>
        @foreach($voiceTour as $i => $item)
          @php $tourName = __('ui.landing.tour_'.$item['key'].'_title'); @endphp
          <li class="tour-item{{ $i === 0 ? ' is-picked' : '' }}" data-tour-item="{{ $i }}" data-vp-track @if($item['src'] !== '') data-src="{{ $item['src'] }}" @endif>
            <button type="button" class="tour-pick" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}" aria-controls="tourCaption"
              @if($item['src'] !== '')
                data-vp-play
                data-label-play="{{ __('ui.landing.tour_play', ['name' => $tourName]) }}"
                data-label-pause="{{ __('ui.landing.tour_pause', ['name' => $tourName]) }}"
                aria-label="{{ __('ui.landing.tour_play', ['name' => $tourName]) }}"
              @else
                aria-label="{{ $tourName }}"
              @endif
            >
              <x-orb :name="$item['key']" class="tour-orb" />
              @if($item['src'] !== '')
                <span class="tour-play" aria-hidden="true">
                  <svg class="tour-icon-play" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.4-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg>
                  <svg class="tour-icon-pause" viewBox="0 0 24 24" fill="currentColor"><rect x="6.5" y="5" width="4" height="14" rx="1.5"/><rect x="13.5" y="5" width="4" height="14" rx="1.5"/></svg>
                </span>
              @endif
            </button>
            <span class="tour-name">{{ $tourName }}</span>
          </li>
        @endforeach
      </ul>

      <div class="tour-caption" id="tourCaption" aria-live="polite">
        @foreach($voiceTour as $i => $item)
          <div class="tour-copy" data-tour-copy="{{ $i }}" @if($i !== 0) hidden @endif>
            <h3>{{ __('ui.landing.tour_'.$item['key'].'_title') }}</h3>
            <p>{{ __('ui.landing.tour_'.$item['key'].'_body') }}</p>
            <a class="btn tour-more" href="{{ $item['more'] }}">{{ $item['key'] === 'cloning' ? __('ui.landing.tour_cloning_cta') : __('ui.landing.tour_more') }}</a>
          </div>
        @endforeach
      </div>
    </div>
  </section>

  <script>
  (function () {
    var row = document.querySelector('[data-tour]');
    if (!row) return;
    // Picking an orb lifts it and shows what it is; its sample plays through the shared player.
    row.addEventListener('click', function (event) {
      var pick = event.target.closest('.tour-pick');
      if (!pick) return;
      var item = pick.closest('[data-tour-item]');
      var index = item.getAttribute('data-tour-item');
      row.querySelectorAll('[data-tour-item]').forEach(function (el) {
        var on = el === item;
        el.classList.toggle('is-picked', on);
        el.querySelector('.tour-pick').setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      document.querySelectorAll('[data-tour-copy]').forEach(function (el) { el.hidden = el.getAttribute('data-tour-copy') !== index; });
      // On a phone the row scrolls: bring the picked orb to the middle.
      if (row.scrollWidth > row.clientWidth + 4 && item.scrollIntoView) {
        item.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
      }
    });
  })();
  </script>
@endif
