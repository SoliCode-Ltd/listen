{{-- The questions at the foot of the page, for search engines and AI answers as well (FAQPage). --}}
@include('components.page-head', ['pageTitle' => __('agents_landing.meta_title'), 'page' => 'agents_landing', 'faqItems' => \App\Support\SeoHelper::voiceAgentFaq()])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/agents-landing.css') }}?v={{ filemtime(public_path('css/agents-landing.css')) }}">
</head>
<body>
  @include('components.header')
  @php
    $ar = app()->getLocale() === 'ar';
    $hasAgents = auth()->check() && auth()->user()->agents()->exists();
    // Listen's own agent by the name it has now (a man's voice brings his name, HouseAgent::texts).
    $house = \App\Support\Agents\HouseAgent::for(app()->getLocale());
    $houseName = trim((string) ($house ? \App\Models\Agent::query()->where('public_id', $house['agent'] ?? '')->value('name') : ''));
    $houseName = $houseName !== '' ? $houseName : ($ar ? 'سارة' : 'Sara');
    $startUrl = $hasAgents ? route('voice-agents.index') : (auth()->check() ? route('voice-agents.create') : route('register', ['next' => 'agents']));
    $steps = __('agents_landing.steps');
    $ui = __('agents_landing.steps_ui');
    $app = __('agents_landing.how_app');
    $inbox = __('agents_landing.inbox');
    $speaker = fn (string $slug) => \App\Support\SpeakerAvatar::bySlug($slug);
    $check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
    // Every dialect (Arabic page) or accent (English page) the agent speaks, in the studio's own
    // regions (VoiceFlag::region), each with a few of its countries' round flags.
    $regionText = (array) __('agents_landing.speaks_regions');
    $regionFlags = [
      'nile' => ['eg', 'sd'], 'gulf' => ['sa', 'ae', 'kw', 'qa'], 'levant' => ['lb', 'sy', 'jo', 'ps'], 'iraq_yemen' => ['iq', 'ye'], 'maghreb' => ['ma', 'dz', 'tn', 'ly'], 'fusha' => [],
      'americas' => ['us', 'ca', 'jm'], 'uk_ie' => ['gb', 'ie', 'gb-sct', 'gb-wls'], 'africa' => ['za', 'ng', 'gh', 'ke'], 'asia_pacific' => ['au', 'nz', 'in', 'sg'], 'other' => ['fr', 'es', 'de', 'jp'],
    ];
    $speechRows = $ar ? \App\Support\ArabicDialects::ALL : \App\Support\EnglishAccents::ALL;
    $speechCount = count($speechRows);
    $speechGroups = collect($speechRows)
      ->map(fn ($d, $code) => ['name' => $d[$ar ? 'ar' : 'en'], 'region' => \App\Support\VoiceFlag::region($code)])
      ->groupBy('region')
      ->sortBy(fn ($g, $r) => ($at = array_search($r, array_keys($regionText), true)) === false ? 99 : $at)
      ->map(fn ($g, $r) => [
          'key' => $r,
          'title' => $regionText[$r][0] ?? $r,
          'examples' => $regionText[$r][1] ?? '',
          'names' => $g->pluck('name')->all(),
          'flags' => array_values(array_filter($regionFlags[$r] ?? [], fn ($f) => is_file(public_path('images/flags/'.$f.'.svg')))),
      ])->values()->all();
    $micIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>';
    // The plans that include agents, with what each gives (Plans → Voice agents), and the rate.
    $rate = \App\Support\Agents\AgentAccess::creditsPerMinute();
    // The free test minutes, as the admin set them (Agents → Settings).
    $freeMinutes = intdiv(\App\Support\Agents\AgentAccess::freeTestSeconds(), 60);
    $agentPlans = \App\Support\Agents\AgentAccess::plansOnSale();
    $factIcons = [
      '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
      '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
      '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
    ];
    $doesIcons = [
      '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
      '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
      '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M9 15l2 2 4-4"/>',
      '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14a6.5 6.5 0 0 1 3 6"/>',
    ];
    $safeIcons = [
      '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 15h3"/><path d="M4 4l16 16"/>',
      '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/><path d="M17 4l3 3-3 3"/>',
      '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 8h18"/><rect x="9" y="11" width="6" height="5" rx="1"/><path d="M10.5 11V9.8a1.5 1.5 0 0 1 3 0V11"/>',
      '<circle cx="12" cy="13" r="7.5"/><path d="M12 9.5V13l2.5 1.5M9.5 3h5"/>',
      '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
      '<path d="M6 3h9l3 3v15H6z"/><path d="M9 9h6M9 13h6M9 17h4"/>',
    ];
    $channelIcons = [
      '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 8h18M15 15h3"/>',
      '<path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/>',
      '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3M21 14v.01M14 21h7v-4"/>',
      '<path d="M14 3h7v7M10 14L21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
    ];
    // The studio window's side menu (one item per step, then the calls) and the facts it finds.
    $menuIcons = [
      '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v15H6.5A1.5 1.5 0 0 0 5 19.5z"/><path d="M5 19.5A1.5 1.5 0 0 0 6.5 21H19v-3"/>',
      '<path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/>',
      '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
      '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
      '<path d="M4 13l2.5-8h11L20 13v6H4z"/><path d="M4 13h4.5l1 2h5l1-2H20"/>',
    ];
    $knowIcons = [
      '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
      '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
      '<path d="M12 21s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/>',
      '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
    ];
    // The voices shown in "Pick its voice": the library's own speakers of the calls on this page.
    $voices = $ar
      ? [['ar-fatima', 'فاطمة'], ['ar-hamed', 'حامد'], ['ar-sumaya', 'سمية'], ['ar-raed', 'رائد'], ['ar-tamim', 'تميم'], ['ar-turki', 'تركي']]
      : [['en-caleb', 'Caleb'], ['en-alice', 'Alice'], ['en-sienna', 'Sienna'], ['en-george', 'George'], ['en-finn', 'Finn'], ['en-oliver', 'Oliver']];
    // A voice sample's shape, drawn (nothing plays).
    $wave = [22, 34, 52, 70, 46, 30, 58, 80, 92, 68, 44, 32, 60, 84, 96, 72, 50, 36, 24, 40, 62, 78, 58, 42, 28, 46, 70, 86, 64, 46, 32, 22, 38, 56, 44, 30, 20, 28, 16, 12];
    $logo = '<svg viewBox="0 0 128 128"><rect x="5" y="59" width="10" height="10" rx="5"/><rect x="23" y="42" width="10" height="44" rx="5"/><rect x="41" y="28" width="10" height="72" rx="5"/><rect x="59" y="14" width="10" height="100" rx="5"/><rect x="77" y="28" width="10" height="72" rx="5"/><rect x="95" y="42" width="10" height="44" rx="5"/><rect x="113" y="59" width="10" height="10" rx="5"/></svg>';
    // A real QR code (version 3, level M) for this page on Listen's own site, drawn once with js/vendor/qrcode.js.
    $qr = 'M0 0h7v1h-7zM8 0h1v1h-1zM11 0h3v1h-3zM15 0h1v1h-1zM17 0h4v1h-4zM22 0h7v1h-7zM0 1h1v1h-1zM6 1h1v1h-1zM8 1h2v1h-2zM11 1h1v1h-1zM13 1h1v1h-1zM16 1h1v1h-1zM18 1h1v1h-1zM22 1h1v1h-1zM28 1h1v1h-1zM0 2h1v1h-1zM2 2h3v1h-3zM6 2h1v1h-1zM15 2h2v1h-2zM18 2h2v1h-2zM22 2h1v1h-1zM24 2h3v1h-3zM28 2h1v1h-1zM0 3h1v1h-1zM2 3h3v1h-3zM6 3h1v1h-1zM8 3h1v1h-1zM10 3h1v1h-1zM14 3h6v1h-6zM22 3h1v1h-1zM24 3h3v1h-3zM28 3h1v1h-1zM0 4h1v1h-1zM2 4h3v1h-3zM6 4h1v1h-1zM12 4h1v1h-1zM14 4h3v1h-3zM19 4h1v1h-1zM22 4h1v1h-1zM24 4h3v1h-3zM28 4h1v1h-1zM0 5h1v1h-1zM6 5h1v1h-1zM9 5h2v1h-2zM13 5h1v1h-1zM16 5h4v1h-4zM22 5h1v1h-1zM28 5h1v1h-1zM0 6h7v1h-7zM8 6h1v1h-1zM10 6h1v1h-1zM12 6h1v1h-1zM14 6h1v1h-1zM16 6h1v1h-1zM18 6h1v1h-1zM20 6h1v1h-1zM22 6h7v1h-7zM8 7h1v1h-1zM10 7h1v1h-1zM12 7h1v1h-1zM16 7h3v1h-3zM0 8h1v1h-1zM2 8h2v1h-2zM5 8h3v1h-3zM13 8h1v1h-1zM17 8h3v1h-3zM22 8h1v1h-1zM25 8h1v1h-1zM27 8h2v1h-2zM1 9h2v1h-2zM5 9h1v1h-1zM9 9h3v1h-3zM13 9h2v1h-2zM16 9h1v1h-1zM19 9h6v1h-6zM28 9h1v1h-1zM0 10h2v1h-2zM4 10h1v1h-1zM6 10h1v1h-1zM8 10h1v1h-1zM10 10h1v1h-1zM13 10h1v1h-1zM18 10h1v1h-1zM21 10h1v1h-1zM23 10h1v1h-1zM26 10h2v1h-2zM0 11h1v1h-1zM2 11h1v1h-1zM5 11h1v1h-1zM7 11h1v1h-1zM9 11h2v1h-2zM12 11h1v1h-1zM15 11h1v1h-1zM19 11h5v1h-5zM28 11h1v1h-1zM2 12h1v1h-1zM4 12h1v1h-1zM6 12h1v1h-1zM9 12h1v1h-1zM11 12h2v1h-2zM14 12h1v1h-1zM17 12h1v1h-1zM20 12h1v1h-1zM23 12h1v1h-1zM25 12h2v1h-2zM1 13h1v1h-1zM4 13h1v1h-1zM7 13h3v1h-3zM12 13h1v1h-1zM14 13h3v1h-3zM19 13h1v1h-1zM22 13h1v1h-1zM26 13h3v1h-3zM0 14h1v1h-1zM4 14h4v1h-4zM9 14h1v1h-1zM11 14h1v1h-1zM13 14h1v1h-1zM17 14h3v1h-3zM21 14h1v1h-1zM23 14h2v1h-2zM26 14h3v1h-3zM0 15h1v1h-1zM2 15h2v1h-2zM5 15h1v1h-1zM9 15h4v1h-4zM14 15h3v1h-3zM20 15h3v1h-3zM24 15h1v1h-1zM27 15h1v1h-1zM0 16h2v1h-2zM3 16h1v1h-1zM6 16h1v1h-1zM8 16h1v1h-1zM10 16h1v1h-1zM14 16h1v1h-1zM18 16h1v1h-1zM21 16h1v1h-1zM24 16h2v1h-2zM27 16h1v1h-1zM4 17h1v1h-1zM8 17h1v1h-1zM10 17h1v1h-1zM14 17h2v1h-2zM17 17h1v1h-1zM20 17h1v1h-1zM23 17h1v1h-1zM25 17h3v1h-3zM0 18h1v1h-1zM4 18h3v1h-3zM11 18h6v1h-6zM18 18h1v1h-1zM20 18h2v1h-2zM23 18h2v1h-2zM26 18h1v1h-1zM2 19h2v1h-2zM7 19h3v1h-3zM13 19h9v1h-9zM23 19h2v1h-2zM26 19h1v1h-1zM1 20h1v1h-1zM3 20h2v1h-2zM6 20h1v1h-1zM8 20h2v1h-2zM13 20h2v1h-2zM16 20h11v1h-11zM8 21h1v1h-1zM16 21h1v1h-1zM18 21h3v1h-3zM24 21h5v1h-5zM0 22h7v1h-7zM8 22h3v1h-3zM14 22h1v1h-1zM16 22h1v1h-1zM19 22h2v1h-2zM22 22h1v1h-1zM24 22h2v1h-2zM27 22h1v1h-1zM0 23h1v1h-1zM6 23h1v1h-1zM8 23h1v1h-1zM13 23h2v1h-2zM16 23h1v1h-1zM18 23h1v1h-1zM20 23h1v1h-1zM24 23h2v1h-2zM28 23h1v1h-1zM0 24h1v1h-1zM2 24h3v1h-3zM6 24h1v1h-1zM10 24h3v1h-3zM15 24h1v1h-1zM17 24h1v1h-1zM20 24h5v1h-5zM26 24h1v1h-1zM0 25h1v1h-1zM2 25h3v1h-3zM6 25h1v1h-1zM8 25h1v1h-1zM10 25h1v1h-1zM15 25h3v1h-3zM21 25h1v1h-1zM23 25h3v1h-3zM28 25h1v1h-1zM0 26h1v1h-1zM2 26h3v1h-3zM6 26h1v1h-1zM8 26h1v1h-1zM10 26h1v1h-1zM18 26h1v1h-1zM20 26h1v1h-1zM23 26h1v1h-1zM26 26h1v1h-1zM28 26h1v1h-1zM0 27h1v1h-1zM6 27h1v1h-1zM9 27h1v1h-1zM11 27h2v1h-2zM15 27h2v1h-2zM20 27h3v1h-3zM24 27h2v1h-2zM27 27h1v1h-1zM0 28h7v1h-7zM8 28h1v1h-1zM10 28h2v1h-2zM15 28h1v1h-1zM20 28h2v1h-2zM24 28h1v1h-1zM27 28h1v1h-1z';
    $initial = fn (string $name) => mb_substr($name, 0, 1);
  @endphp

  <main class="agl" id="agents-landing">
    {{-- The hero: what it is in two lines, and a real call to hear right away. The page's one orb is in it. --}}
    <section class="agl-hero">
      <div class="agl-hero-text">
        @php
          // The dialect words in the brand colour, as the homepage's hero sets its own.
          $heroTitle = (string) __('agents_landing.hero_title');
          $heroMark = (string) __('agents_landing.hero_title_mark');
          $heroAt = $heroMark !== '' ? mb_strpos($heroTitle, $heroMark) : false;
        @endphp
        <h1>@if($heroAt !== false){{ mb_substr($heroTitle, 0, $heroAt) }}<span class="brand-text">{{ $heroMark }}</span>{{ mb_substr($heroTitle, $heroAt + mb_strlen($heroMark)) }}@else{{ $heroTitle }}@endif</h1>
        <p class="agl-lead">{{ __('agents_landing.hero_text') }}</p>
        <div class="agl-cta-row">
          <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ $startUrl }}">{{ $hasAgents ? __('agents_landing.hero_cta_member') : __('agents_landing.hero_cta') }}</a>
          <button type="button" class="ag-btn ag-btn-lg agl-live-btn" data-agl-live hidden>{!! $micIcon !!}{{ __('agents_landing.talk_live', ['name' => $houseName]) }}</button>
        </div>
        <p class="agl-note" data-agl-live-note hidden>{{ __('agents_landing.talk_live_note') }}</p>
        <ul class="agl-facts">
          @foreach(__('agents_landing.home_facts') as $i => $fact)
            <li><svg viewBox="0 0 24 24" aria-hidden="true">{!! $factIcons[$i] ?? '' !!}</svg><span>{{ $fact }}</span></li>
          @endforeach
          {{-- The free test minutes, one of the list (the owner's choice), not a note under the button. --}}
          @if($freeMinutes > 0)
            <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12v8.5a.5.5 0 0 1-.5.5h-15a.5.5 0 0 1-.5-.5V12M2.5 7.5h19V12h-19zM12 21V7.5M12 7.5H8a2.5 2.5 0 1 1 0-5c3 0 4 5 4 5zm0 0h4a2.5 2.5 0 1 0 0-5c-3 0-4 5-4 5z"/></svg><span>{{ rtrim(trans_choice('agents_landing.hero_note', $freeMinutes, ['count' => $freeMinutes]), '.') }}</span></li>
          @endif
        </ul>
      </div>
      <div class="agl-hero-call" id="agl-real">
        <x-agents.real-call size="hero" />
      </div>
    </section>

    {{-- Every dialect (or accent) it speaks: one tile per region; a tile opens the full list, searchable, in a dialog. --}}
    <section class="agl-speaks" aria-labelledby="agl-speaks-title">
      <div class="agl-speaks-head">
        <div class="agl-speaks-copy">
          <h2 id="agl-speaks-title">{{ __('agents_landing.speaks_title') }}</h2>
          <p>{{ __('agents_landing.speaks_text', ['count' => $speechCount]) }}</p>
        </div>
      </div>
      <ul class="agl-regions" style="--cols: {{ count($speechGroups) }}">
        @foreach($speechGroups as $group)
          <li>
            <button type="button" class="agl-region" aria-haspopup="dialog" aria-controls="agl-all-speech" data-agl-more data-agl-region="{{ $group['key'] }}" aria-label="{{ __('agents_landing.speaks_open', ['region' => $group['title']]) }}: {{ trans_choice('agents_landing.speaks_unit', count($group['names']), ['count' => count($group['names'])]) }}">
              <span class="agl-region-top" aria-hidden="true">
                <span class="agl-region-n"><b dir="ltr">{{ count($group['names']) }}</b><small>{{ trans_choice('agents_landing.speaks_unit_word', count($group['names'])) }}</small></span>
                <span class="agl-region-flags">
                  @forelse($group['flags'] as $flag)
                    <img src="{{ asset('images/flags/'.$flag.'.svg') }}" alt="" width="22" height="22" loading="lazy">
                  @empty
                    @if(__('agents_landing.speaks_fusha_badge') !== '')<span class="agl-region-badge">{{ __('agents_landing.speaks_fusha_badge') }}</span>@endif
                  @endforelse
                </span>
              </span>
              <span class="agl-region-name" aria-hidden="true">{{ $group['title'] }}</span>
              <span class="agl-region-list" aria-hidden="true">{{ $group['examples'] }}</span>
            </button>
          </li>
        @endforeach
      </ul>

      <dialog class="agl-dialects" id="agl-all-speech" aria-labelledby="agl-dialects-title" data-agl-all>
        <div class="agl-dialects-box">
          <div class="agl-dialects-head">
            <div>
              <h3 id="agl-dialects-title">{{ __('agents_landing.dialects_all') }} <span class="agl-count" dir="ltr">{{ $speechCount }}</span></h3>
              <p>{{ __('agents_landing.speaks_dialog_text') }}</p>
            </div>
            <button type="button" class="agl-dialects-close" data-agl-close aria-label="{{ __('agents_landing.speaks_close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
          </div>
          <div class="agl-dialects-tools">
            <label class="agl-dialects-search">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
              <input type="search" placeholder="{{ __('agents_landing.speaks_search') }}" aria-label="{{ __('agents_landing.speaks_search') }}" autocomplete="off" spellcheck="false" data-agl-search>
            </label>
            <div class="agl-dialects-filter" role="group" aria-label="{{ __('agents_landing.speaks_regions_label') }}">
              <button type="button" aria-pressed="true" data-agl-filter="">{{ __('agents_landing.speaks_any') }} <span dir="ltr">{{ $speechCount }}</span></button>
              @foreach($speechGroups as $group)
                <button type="button" aria-pressed="false" data-agl-filter="{{ $group['key'] }}">{{ $group['title'] }} <span dir="ltr">{{ count($group['names']) }}</span></button>
              @endforeach
            </div>
          </div>
          <div class="agl-dialects-body" data-agl-groups>
            @foreach($speechGroups as $group)
              <section class="agl-dialects-group" data-agl-group="{{ $group['key'] }}" aria-labelledby="agl-dg-{{ $group['key'] }}">
                <h4 id="agl-dg-{{ $group['key'] }}">{{ $group['title'] }} <span dir="ltr">{{ count($group['names']) }}</span></h4>
                <ul>@foreach($group['names'] as $name)<li>{{ $name }}</li>@endforeach</ul>
              </section>
            @endforeach
            <p class="agl-dialects-none" data-agl-none hidden>{{ __('agents_landing.speaks_none') }}</p>
          </div>
          <p class="agl-dialects-foot">{{ __('agents_landing.speaks_note') }}</p>
        </div>
      </dialog>
    </section>

    {{-- How it works: four steps on a rail, and beside them one studio window whose screen follows the
         step in view (drawn, so it is never out of date). On phones each step shows its own screen. --}}
    <section class="agl-section agl-steps" aria-labelledby="agl-steps-title">
      <div class="agl-head">
        <h2 id="agl-steps-title">{{ __('agents_landing.steps_title') }}</h2>
        <p>{{ __('agents_landing.steps_text') }}</p>
      </div>
      <div class="agl-how" data-agl-how>
        <span class="agl-how-rail" aria-hidden="true" data-agl-how-rail><i data-agl-how-fill></i></span>
        @foreach($steps as $i => [$title, $text])
          <div class="agl-how-text{{ $i === 0 ? ' is-on' : '' }}" style="--n: {{ $i + 1 }}" data-agl-how-step>
            <span class="agl-how-n" aria-hidden="true"><b>{{ $i + 1 }}</b>{!! $check !!}</span>
            <h3><span class="agl-sr">{{ __('agents_landing.how_step_of', ['n' => $i + 1, 'total' => count($steps)]) }}: </span>{{ $title }}</h3>
            <p>{{ $text }}</p>
          </div>
          <div class="agl-how-win{{ $i === 0 ? ' is-on' : '' }}" style="--n: {{ $i + 1 }}" data-agl-how-win aria-hidden="true">
            <div class="agl-app">
              <div class="agl-app-bar">
                <span class="agl-app-mark">{!! $logo !!}</span>
                <span class="agl-app-crumb">{{ $app['crumb'] }}<i>/</i><b>{{ $app['agent'] }}</b><em>{{ $app['business'] }}</em></span>
                <span class="agl-app-state{{ $i === 3 ? ' is-live' : '' }}"><i></i>{{ $i === 3 ? $app['live'] : $app['draft'] }}</span>
              </div>
              <div class="agl-app-body">
                <ul class="agl-app-side">
                  @foreach($app['menu'] as $m => $item)
                    <li class="{{ $m === $i ? 'is-on' : '' }}{{ $m < $i ? ' is-done' : '' }}{{ $m === 4 ? ' is-after' : '' }}">
                      <svg viewBox="0 0 24 24">{!! $menuIcons[$m] ?? '' !!}</svg><span>{{ $item }}</span>
                      @if($m < $i)<svg class="agl-app-tick" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>@endif
                    </li>
                  @endforeach
                </ul>
                <div class="agl-app-main agl-scr-{{ $i + 1 }}">
                  <p class="agl-app-h"><b>{{ $ui['screens'][$i][0] ?? $title }}</b><span>{{ $ui['screens'][$i][1] ?? '' }}</span></p>
                  @switch($i)
                    @case(0)
                      {{-- Your website, read: what it found, ready to correct. --}}
                      <div class="agl-k-url">
                        <svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        <i></i>
                        <b>{{ $ui['read'] }}</b>
                      </div>
                      <div class="agl-k-done"><span>{!! $check !!}{{ $ui['found'] }}</span><i><b></b></i></div>
                      <ul class="agl-k-facts">
                        @foreach($ui['facts'] as $f => [$label, $value])
                          <li style="--d: {{ $f }}">
                            <span class="agl-k-ic"><svg viewBox="0 0 24 24">{!! $knowIcons[$f] ?? '' !!}</svg></span>
                            <span class="agl-k-label">{{ $label }}</span>
                            <span class="agl-k-value">{{ $value }}</span>
                          </li>
                        @endforeach
                      </ul>
                      <p class="agl-k-add"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>{{ $ui['add'] }}</p>
                      @break
                    @case(1)
                      {{-- Real voices, one picked, its sample drawn. --}}
                      <div class="agl-chips"><span class="is-on">{{ $ui['voice_filters'][0] }}</span><span>{{ $ui['voice_filters'][1] }}</span><span>{{ $ui['voice_filters'][2] }}</span></div>
                      <ul class="agl-v-grid">
                        @foreach($voices as $v => [$slug, $name])
                          <li class="{{ $v === 0 ? 'is-on' : '' }}" style="--d: {{ $v }}">
                            <span class="agl-v-face"><img src="{{ $speaker($slug) }}" alt="" width="36" height="36" loading="lazy">@if($v === 0)<i>{!! $check !!}</i>@endif</span>
                            <span class="agl-v-who"><b>{{ $name }}</b><small>{{ $ui['voice_tones'][$v] ?? '' }}</small></span>
                          </li>
                        @endforeach
                      </ul>
                      <div class="agl-v-player">
                        <span class="agl-v-play"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l10.5-6.5z"/></svg></span>
                        <span class="agl-v-wave">@foreach($wave as $w => $h)<i class="{{ $w < 15 ? 'is-on' : '' }}" style="--h: {{ $h }}%"></i>@endforeach</span>
                        <span class="agl-v-time" dir="ltr">{{ $ui['voice_length'] }}</span>
                        <p>{{ $ui['voice_line'] }}</p>
                      </div>
                      @break
                    @case(2)
                      {{-- A test call, and a wrong answer corrected once. --}}
                      <div class="agl-t-bar">
                        <img src="{{ $speaker($voices[0][0]) }}" alt="" width="30" height="30" loading="lazy">
                        <span><b>{{ $ui['test_with'] }}</b><small><i></i><bdi dir="ltr">{{ $ui['test_time'] }}</bdi></small></span>
                        <em>{{ $ui['test_end'] }}</em>
                      </div>
                      <div class="agl-t-chat">
                        <p class="is-caller" style="--d: 0"><small>{{ $ui['you'] }}</small>{{ $ui['talk_q'] }}</p>
                        <p class="is-agent" style="--d: 1"><small>{{ $app['agent'] }}</small>{{ $ui['talk_a'] }}</p>
                        <div class="agl-t-fix" style="--d: 2">
                          <p class="agl-t-fix-head"><svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>{{ $ui['talk_fix'] }}</p>
                          <p class="agl-t-fix-text">{{ $ui['fix_text'] }}</p>
                          <p class="agl-t-saved">{!! $check !!}{{ $ui['fix_saved'] }}</p>
                        </div>
                      </div>
                      @break
                    @default
                      {{-- Live: a button on the website, its link and its code (a real one, for this page). --}}
                      <div class="agl-l-grid">
                        <div class="agl-l-site">
                          <span class="agl-l-site-top"><i></i><i></i><i></i></span>
                          <span class="agl-l-site-page"><i></i><i></i><i></i><i></i><span class="agl-l-site-btn"><b></b>{{ __('agents_landing.widget_label') }}</span></span>
                          <span class="agl-l-site-cap"><b>{{ $ui['live'][2] }}</b><small>{!! $check !!}{{ $ui['site_seen'] }}</small></span>
                        </div>
                        <div class="agl-l-side">
                          <div class="agl-l-card">
                            <span class="agl-l-ic"><svg viewBox="0 0 24 24">{!! $channelIcons[1] !!}</svg></span>
                            <span class="agl-l-what"><b>{{ $ui['live'][0] }}</b><i></i></span>
                            <span class="agl-l-btn"><svg viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>{{ $ui['copy'] }}</span>
                          </div>
                          <div class="agl-l-card is-qr">
                            <span class="agl-l-qr"><svg viewBox="-2 -2 33 33" shape-rendering="crispEdges"><rect x="-2" y="-2" width="33" height="33" fill="#fff"/><path d="{{ $qr }}"/></svg></span>
                            <span class="agl-l-what"><b>{{ $ui['live'][1] }}</b><span class="agl-l-btn"><svg viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>{{ $ui['download'] }}</span></span>
                          </div>
                        </div>
                      </div>
                  @endswitch
                </div>
              </div>
            </div>
            <p class="agl-how-foot"><span>{{ __('agents_landing.how_step_of', ['n' => $i + 1, 'total' => count($steps)]) }}<i></i>{{ $title }}</span><span class="agl-how-dots">@foreach($steps as $d => $unused)<i class="{{ $d <= $i ? 'is-on' : '' }}"></i>@endforeach</span></p>
          </div>
        @endforeach
      </div>
    </section>

    {{-- What it does on every call: the month at a glance and four things it does. --}}
    <section class="agl-section agl-does" data-agl-reveal aria-labelledby="agl-does-title">
      <div class="agl-head">
        <h2 id="agl-does-title">{{ __('agents_landing.bento_title') }}</h2>
        <p>{{ __('agents_landing.bento_text') }}</p>
      </div>
      <div class="agl-does-grid">
        <article class="agl-month">
          <h3>{{ __('agents_landing.bento_stats_title') }}</h3>
          <div class="agl-month-nums">
            @foreach(__('agents_landing.bento_stats') as [$number, $label])
              <div><b data-agl-count="{{ $number }}">{{ $number }}</b><span>{{ $label }}</span></div>
            @endforeach
          </div>
          <div class="agl-month-bars" aria-hidden="true">
            @foreach([38, 52, 44, 61, 57, 70, 48, 66, 74, 59, 81, 69, 88, 76] as $b => $h)<i style="--h: {{ $h }}%; --d: {{ $b }}"></i>@endforeach
          </div>
          <p>{{ __('agents_landing.bento_stats_caption') }}</p>
        </article>
        <ul class="agl-does-list">
          @foreach(__('agents_landing.does') as $i => [$title, $text])
            <li>
              <span class="agl-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $doesIcons[$i] ?? '' !!}</svg></span>
              <div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
            </li>
          @endforeach
        </ul>
      </div>
      <p class="agl-honest"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/></svg>{{ __('agents_landing.honest') }}</p>
    </section>

    {{-- One agent, every channel: where callers come from, the agent, a live call. --}}
    <x-agents.channels />

    {{-- Every call written down: the owner's inbox. Pick a call on the list to read it on the other side. --}}
    <section class="agl-section agl-calls" aria-labelledby="agl-calls-title">
      <div class="agl-head">
        <h2 id="agl-calls-title">{{ __('agents_landing.calls_title') }}</h2>
        <p>{{ __('agents_landing.calls_text') }}</p>
      </div>
      <div class="agl-app agl-inbox" data-agl-inbox>
        <div class="agl-app-bar">
          <span class="agl-app-mark" aria-hidden="true">{!! $logo !!}</span>
          <span class="agl-app-crumb"><b>{{ $inbox['title'] }}</b><em>{{ $app['business'] }}</em></span>
          <span class="agl-inbox-count"><span>{{ $inbox['month'] }}</span><i aria-hidden="true"></i><b>{{ $inbox['callbacks'] }}</b></span>
        </div>
        <div class="agl-inbox-body">
          <div class="agl-inbox-list" role="tablist" aria-orientation="vertical" aria-label="{{ $inbox['label'] }}">
            @foreach($inbox['calls'] as $c => $call)
              <button type="button" class="agl-in-row" role="tab" id="agl-call-tab-{{ $c }}" aria-controls="agl-call-{{ $c }}" aria-selected="{{ $c === 0 ? 'true' : 'false' }}" tabindex="{{ $c === 0 ? '0' : '-1' }}">
                <span class="agl-in-av" aria-hidden="true">{{ $initial($call['name']) }}</span>
                <span class="agl-in-main">
                  <span class="agl-in-top"><b>{{ $call['name'] }}</b><time>{{ $call['when'] }}</time></span>
                  <span class="agl-in-sum">{{ $call['summary'] }}</span>
                  <span class="agl-in-meta">
                    <span class="agl-tag is-{{ $call['tone'] }}">{{ $call['tag'] }}</span>
                    <span class="agl-in-ch"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $channelIcons[$call['channel']] ?? '' !!}</svg>{{ $call['channel_name'] }}</span>
                  </span>
                </span>
              </button>
            @endforeach
          </div>
          <div class="agl-inbox-panels">
            @foreach($inbox['calls'] as $c => $call)
              <div class="agl-in-panel{{ $c === 0 ? ' is-on' : '' }}" role="tabpanel" id="agl-call-{{ $c }}" aria-labelledby="agl-call-tab-{{ $c }}" tabindex="0">
                <div class="agl-in-head">
                  <span class="agl-in-av is-lg" aria-hidden="true">{{ $initial($call['name']) }}</span>
                  <span class="agl-in-who">
                    <b>{{ $call['name'] }}</b>
                    <span>{{ $call['female'] ? $inbox['left_f'] : $inbox['left'] }} <bdi dir="ltr">{{ $call['phone'] }}</bdi></span>
                  </span>
                  <span class="agl-in-call" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $menuIcons[2] !!}</svg>{{ $inbox['call'] }}</span>
                </div>
                <ul class="agl-in-facts">
                  <li><span class="agl-tag is-{{ $call['tone'] }}">{{ $call['tag'] }}</span></li>
                  <li><svg viewBox="0 0 24 24" aria-hidden="true">{!! $channelIcons[$call['channel']] ?? '' !!}</svg>{{ $call['channel_name'] }}</li>
                  <li><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ $call['length'] }}</li>
                  <li>{{ $call['when'] }}</li>
                </ul>
                <div class="agl-in-block">
                  <p class="agl-in-label">{{ $inbox['summary'] }}</p>
                  <p class="agl-in-summary">{{ $call['summary'] }}</p>
                  <p class="agl-in-outcome is-{{ $call['tone'] }}">
                    @if($call['tone'] === 'act')
                      <svg viewBox="0 0 24 24" aria-hidden="true">{!! $menuIcons[2] !!}</svg>
                    @elseif($call['tone'] === 'info')
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H8l-4 4z"/><path d="M8.5 10.5l2.2 2.2 4.8-4.8"/></svg>
                    @else
                      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M9 15l2 2 4-4"/></svg>
                    @endif
                    {{ $call['outcome'] }}
                  </p>
                </div>
                <div class="agl-in-block">
                  <p class="agl-in-label">{{ $inbox['said'] }}</p>
                  <ol class="agl-in-lines">
                    @foreach($call['lines'] as [$role, $text])
                      <li class="is-{{ $role }}"><small>{{ $role === 'agent' ? $inbox['agent'] : $call['name'] }}</small>{{ $text }}</li>
                    @endforeach
                  </ol>
                </div>
              </div>
            @endforeach
          </div>
        </div>
      </div>
    </section>

    {{-- Safe by design: the rules every call keeps, and the limits the owner holds. --}}
    <section class="agl-section agl-safe" aria-labelledby="agl-safe-title">
      <div class="agl-head">
        <h2 id="agl-safe-title">{{ __('agents_landing.safe_title') }}</h2>
        <p>{{ __('agents_landing.safe_text') }}</p>
      </div>
      <ul class="agl-safe-list">
        @foreach(__('agents_landing.safe') as $i => [$title, $text])
          <li>
            <span class="agl-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $safeIcons[$i] ?? '' !!}</svg></span>
            <div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
          </li>
        @endforeach
      </ul>
    </section>

    {{-- What it costs: the rate in plain words, then each plan that includes agents with what it gives.
         Every number is read from the plans' own settings (Admin → Plans → Voice agents) and the call
         rate, so the section changes with them. --}}
    <section class="agl-section agl-price" aria-labelledby="agl-price-title">
      <div class="agl-price-head">
        <div class="agl-price-copy">
          <h2 id="agl-price-title">{{ __('agents_landing.plans_title') }}</h2>
          <p>{{ __('agents_landing.plans_text', ['rate' => number_format($rate)]) }}</p>
        </div>
        <a class="ag-btn ag-btn-lg" href="{{ route('pricing') }}">{{ __('agents_landing.price_cta') }}</a>
      </div>
      @if($agentPlans !== [])
        <ul class="agl-plans" style="--cols: {{ min(4, count($agentPlans)) }}">
          @foreach($agentPlans as $plan)
            <li class="agl-plan {{ $plan['featured'] ? 'is-featured' : '' }}">
              <div class="agl-plan-head">
                <h3>{{ $plan['name'] }}</h3>
                @if($plan['featured'])<span class="agl-plan-tag">{{ __('ui.pricing.most_popular') }}</span>@endif
              </div>
              <p class="agl-plan-minutes">
                <small>{{ __('agents_landing.plans_up_to') }}</small>
                <b dir="ltr">{{ number_format($plan['minutes']) }}</b>
                <span>{{ __('agents_landing.plans_minutes') }}</span>
              </p>
              <ul class="agl-plan-facts">
                <li><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>{{ trans_choice('agents_landing.plans_agents', $plan['agents'], ['count' => $plan['agents']]) }}</li>
                <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>{{ trans_choice('agents_landing.plans_concurrent', $plan['concurrent'], ['count' => $plan['concurrent']]) }}</li>
                <li><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ trans_choice('agents_landing.plans_longest', $plan['longest'], ['count' => $plan['longest']]) }}</li>
              </ul>
            </li>
          @endforeach
        </ul>
      @endif
      <p class="agl-price-free"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z"/></svg>{{ __('agents_landing.plans_free', ['seconds' => number_format(\App\Support\Agents\AgentAccess::freeTestSeconds())]) }}</p>
    </section>

    <section class="agl-final" aria-labelledby="agl-final-title">
      <div class="agl-final-band">
        {{-- Listen's mark, not a second orb: the page has one orb, in the call. --}}
        <span class="agl-flow-logo agl-final-logo" aria-hidden="true">{!! $logo !!}</span>
        <h2 id="agl-final-title">{{ __('agents_landing.final_title') }}</h2>
        <div class="agl-cta-row agl-cta-center">
          <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ $startUrl }}">{{ __('agents_landing.final_cta') }}</a>
          <button type="button" class="ag-btn ag-btn-lg agl-live-btn" data-agl-live hidden>{!! $micIcon !!}{{ __('agents_landing.talk_live', ['name' => $houseName]) }}</button>
        </div>
        @if($freeMinutes > 0)<p class="agl-note">{{ trans_choice('agents_landing.hero_note', $freeMinutes, ['count' => $freeMinutes]) }}</p>@endif
      </div>
    </section>

    {{-- Questions, last: what owners ask, and where to ask anything else. --}}
    <section class="agl-section agl-faq" aria-labelledby="agl-faq-title">
      <div class="agl-faq-grid">
        <div class="agl-faq-aside">
          <h2 id="agl-faq-title">{{ __('agents_landing.faq_title') }}</h2>
          <p>{{ __('agents_landing.faq_text') }}</p>
          <div class="agl-faq-ask">
            <span class="agl-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v11H8l-4 4z"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.2M12 16v.01"/></svg></span>
            <div class="agl-faq-ask-text">
              <b>{{ __('agents_landing.faq_more_title') }}</b>
              <span data-agl-watch>{{ __('agents_landing.faq_more_text') }}</span>
              <span data-agl-live-note hidden>{{ __('agents_landing.faq_more_live', ['name' => $houseName]) }}</span>
            </div>
            <div class="agl-faq-ask-actions">
              <button type="button" class="ag-btn ag-btn-sm ag-btn-primary agl-faq-live" data-agl-live hidden>{!! $micIcon !!}{{ __('agents_landing.talk_live', ['name' => $houseName]) }}</button>
              <a class="ag-btn ag-btn-sm" href="{{ route('contact') }}">{{ __('agents_landing.faq_contact') }}</a>
            </div>
          </div>
        </div>
        <div class="agl-faq-list">
          @foreach(__('agents_landing.faq') as $i => [$q, $a])
            <details class="agl-faq-item" {{ $i === 0 ? 'open' : '' }}>
              <summary><span class="agl-faq-q">{{ $q }}</span><span class="agl-faq-toggle" aria-hidden="true"></span></summary>
              <div class="agl-faq-a"><p>{{ str_replace(':rate', number_format($rate), $a) }}</p></div>
            </details>
          @endforeach
        </div>
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.orb-script')
  <script src="{{ asset('js/agents-landing.js') }}?v={{ filemtime(public_path('js/agents-landing.js')) }}" defer></script>
</body>
</html>
