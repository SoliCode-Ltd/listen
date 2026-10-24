@php
  // Design preview of the pricing page (local only): pick minutes on a voice track, get the plan.
  $ar = app()->getLocale() === 'ar';
  $t = fn (string $arText, string $enText): string => $ar ? $arText : $enText;
  $plans = collect($pricingPlans)
    ->filter(fn ($p) => empty($p['is_pack']) && (int) ($p['price_monthly_cents'] ?? 0) > 0)
    ->sortBy('monthly_minutes')->values();
  $planData = $plans->map(fn ($p) => [
    'slug' => $p['slug'],
    'name' => $p['name'],
    'minutes' => (int) $p['monthly_minutes'],
    'price_m' => $p['display_price_monthly_formatted'],
    'price_y' => $p['display_price_yearly_monthly_formatted'],
    'was_m' => ! empty($p['has_discount']) ? ($p['original_price_monthly_formatted'] ?? null) : null,
    'then_m' => $p['discount_terms']['then_monthly'] ?? null,
    'then_y' => $p['discount_terms']['then_yearly'] ?? null,
    'offer_m' => $p['discount_terms']['offer_monthly'] ?? null,
    'yearly_total' => $p['display_price_yearly_total_formatted'],
    'lines' => collect($p['features'] ?? [])->reject(fn ($l) => in_array($l['icon'] ?? '', ['clock'], true) && str_contains((string) $l['label'], __('ui.pricing.lines.label_voice_minutes')))->take(4)->values(),
    'orb' => \App\Support\Orbs::forPlan((string) $p['slug']),
    'href' => route('billing.page', ['plan' => $p['slug'], 'billing_cycle' => 'monthly']),
    'ends' => $p['discount_ends_at'] ?? null,
  ])->values();
  $start = $planData->firstWhere('slug', 'starter') ?? $planData->first();
  $faces = ['ar-ahmed', 'ar-amal', 'en-amelia', 'ar-ali', 'ar-aisha', 'en-adrian'];
  $compareLabels = $plans->flatMap(fn ($p) => collect($p['compare_lines'] ?? [])->pluck('label'))->unique()->values();
  $endsLabel = function (?string $iso) use ($ar) {
    if (! $iso) return null;
    return \Illuminate\Support\Carbon::parse($iso)->locale($ar ? 'ar' : 'en')->translatedFormat($ar ? 'l j F' : 'l j F');
  };
@endphp
@include('components.page-head', ['pageTitle' => __('ui.seo.pricing_title'), 'page' => 'pricing'])
<style>
  .pv { --violet: var(--brand-accent); --violet-soft: color-mix(in srgb, var(--brand-accent) 9%, var(--surface)); }
  .pv-wrap { width: min(1180px, calc(100% - 32px)); margin: 0 auto; }

  /* Hero: the site's two-line title, the second line in brand violet */
  .pv-hero { position: relative; text-align: center; padding: clamp(2rem, 4vw, 2.8rem) 0 1.8rem; }
  .pv-hero::before { content: ""; position: absolute; inset: -80px 0 auto; height: 520px; z-index: -1; pointer-events: none;
    background: radial-gradient(40% 55% at 50% 30%, color-mix(in srgb, var(--brand-accent) 13%, transparent), transparent 70%); }
  .pv-hero h1 { margin: 0; font-size: clamp(2.1rem, 3.8vw, 3.3rem); font-weight: 700; line-height: 1.25; letter-spacing: 0; }
  .pv-hero h1 span { display: block; color: var(--brand-accent); }
  .pv-hero p { max-width: 64ch; margin: .8rem auto 0; color: var(--muted); font-size: 1.08rem; line-height: 1.8; }
  .pv-faces { display: inline-flex; align-items: center; gap: .8rem; margin-top: 1rem; color: var(--muted); font-size: .92rem; }
  .pv-proof { text-align: center; }
  .pv-faces span { display: flex; }
  .pv-faces img { width: 34px; height: 34px; border-radius: 50%; border: 2px solid var(--surface); margin-inline-start: -10px; background: var(--surface2); box-shadow: 0 2px 8px rgba(0,0,0,.12); }
  .pv-faces img:first-child { margin-inline-start: 0; }

  /* The studio: one card, the track on one side and the plan on the other */
  .pv-studio { display: grid; grid-template-columns: minmax(0, 1.12fr) minmax(0, .88fr); border-radius: 28px; overflow: hidden;
    background: var(--surface); border: 1px solid var(--line);
    box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 40px 80px -40px color-mix(in srgb, var(--brand-accent) 38%, transparent); }
  .pv-mixer { padding: clamp(1.4rem, 3vw, 2.4rem); }
  .pv-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
  .pv-label { color: var(--muted); font-size: .95rem; }
  .pv-cycle { display: inline-flex; padding: 4px; border-radius: 999px; background: var(--surface2); border: 1px solid var(--line); }
  .pv-cycle button { border: 0; background: none; color: var(--muted); font: inherit; font-size: .9rem; padding: .42rem 1rem; border-radius: 999px; cursor: pointer; }
  .pv-cycle button[aria-pressed="true"] { background: var(--surface); color: var(--text); font-weight: 600; box-shadow: 0 1px 3px rgba(16,24,40,.12); }
  .pv-cycle small { color: var(--ok); font-weight: 700; margin-inline-start: .35rem; font-size: .78rem; }

  .pv-amount { display: flex; align-items: baseline; gap: .7rem; margin-top: 1rem; flex-wrap: wrap; }
  .pv-amount input { width: 4.1ch; padding: 0; border: 0; background: none; color: var(--text); font: inherit; font-weight: 700;
    font-size: clamp(3.6rem, 7vw, 5.4rem); line-height: 1; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
  .pv-amount input:focus-visible { outline: none; text-decoration: underline 3px var(--brand-accent); text-underline-offset: 10px; }
  .pv-amount b { font-size: 1.3rem; font-weight: 600; }
  .pv-amount em { font-style: normal; color: var(--muted); font-size: .98rem; }

  /* The track: the site's player waveform, with the plans as orbs along it */
  .pv-track { position: relative; margin-top: 1.6rem; padding: 14px 18px 0; border-radius: 22px; background: var(--surface2); border: 1px solid var(--line); }
  .pv-wave { position: relative; height: 86px; }
  .pv-wave svg { display: block; width: 100%; height: 100%; transform: scaleX(-1); }
  html[dir="ltr"] .pv-wave svg { transform: none; }
  .pv-wave input { position: absolute; inset: -8px 0; width: 100%; height: calc(100% + 16px); margin: 0; opacity: 0; cursor: ew-resize; }
  .pv-head { position: absolute; top: 50%; width: 30px; height: 30px; margin: -15px 0 0 -15px; border-radius: 50%; background: var(--brand-accent); pointer-events: none;
    box-shadow: 0 0 0 5px var(--surface), 0 0 0 6px color-mix(in srgb, var(--brand-accent) 35%, transparent), 0 10px 22px -6px var(--brand-accent);
    transition: left .18s ease; }
  .pv-head::after { content: ""; position: absolute; inset: 10px; border-radius: 50%; background: #fff; }
  .pv-wave input:focus-visible ~ .pv-head { box-shadow: 0 0 0 5px var(--surface), 0 0 0 8px var(--brand-accent); }
  .pv-stations { position: relative; height: 66px; margin-top: 4px; }
  .pv-station { position: absolute; top: 4px; transform: translateX(50%); display: grid; justify-items: center; gap: 4px; border: 0; background: none; padding: 0; font: inherit; cursor: pointer; color: var(--muted); }
  html[dir="ltr"] .pv-station { transform: translateX(-50%); }
  .pv-station .orb { --orb-size: 22px; box-shadow: none; transition: transform .25s ease; }
  .pv-station span { font-size: .78rem; white-space: nowrap; line-height: 1.3; text-align: center; }
  .pv-station span small { display: block; font-variant-numeric: tabular-nums; opacity: .75; }
  .pv-station.is-on { color: var(--text); font-weight: 600; }
  .pv-station.is-on .orb { transform: scale(1.35); }

  .pv-presets { display: flex; gap: .45rem; flex-wrap: wrap; margin-top: 1.1rem; }
  .pv-presets button { border: 1px solid var(--line); background: var(--surface); color: var(--text); font: inherit; font-size: .88rem; padding: .45rem .95rem; border-radius: 999px; cursor: pointer; }
  .pv-presets button[aria-pressed="true"] { border-color: var(--brand-accent); background: var(--violet-soft); color: var(--accent-text); font-weight: 600; }

  /* The plan that fits: its orb, its price and one button */
  .pv-plan { position: relative; display: flex; flex-direction: column; padding: clamp(1.4rem, 3vw, 2.4rem); background:
      radial-gradient(70% 55% at 50% 0%, color-mix(in srgb, var(--brand-accent) 12%, transparent), transparent 70%), var(--violet-soft);
    border-inline-start: 1px solid var(--line); }
  .pv-orbs { position: relative; width: 108px; height: 108px; margin: 0 auto .7rem; }
  .pv-orbs .orb { --orb-size: 108px; position: absolute; inset: 0; opacity: 0; transform: scale(.9); transition: opacity .45s ease, transform .45s ease; }
  .pv-orbs .orb.is-on { opacity: 1; transform: none; }
  .pv-plan-kicker { text-align: center; color: var(--ok); font-size: .9rem; font-weight: 600; }
  .pv-plan h2 { margin: .15rem 0 0; text-align: center; font-size: 1.9rem; font-weight: 700; }
  .pv-price { display: flex; justify-content: center; align-items: baseline; gap: .6rem; margin-top: 1rem; }
  .pv-price b { font-size: 2.6rem; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
  .pv-price s { color: var(--muted); font-size: 1rem; }
  .pv-then { text-align: center; margin-top: .45rem; color: var(--text); font-size: .95rem; }
  .pv-ends { align-self: center; display: inline-flex; align-items: center; gap: .45rem; margin-top: .75rem; padding: .3rem .8rem; border-radius: 999px;
    background: color-mix(in srgb, #F2B544 18%, var(--surface)); color: color-mix(in srgb, #8A5A0E 70%, var(--text)); font-size: .84rem; font-weight: 500; }
  .pv-ends::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: #F2B544; }
  .pv-cta { display: block; margin-top: 1.3rem; padding: 1rem 1.2rem; border-radius: 999px; background: var(--brand-accent); color: #fff; text-align: center; text-decoration: none; font-weight: 600; font-size: 1.05rem;
    box-shadow: var(--lift); transition: background .15s ease, transform .15s ease; }
  .pv-cta:hover { background: var(--brand-hover); transform: translateY(-1px); }
  .pv-pay { margin: .7rem 0 0; text-align: center; color: var(--muted); font-size: .84rem; line-height: 1.6; }
  .pv-lines { list-style: none; margin: 1.3rem 0 0; padding: 1.1rem 0 0; border-top: 1px solid color-mix(in srgb, var(--brand-accent) 16%, var(--line)); display: grid; gap: .6rem; }
  .pv-lines li { display: flex; justify-content: space-between; gap: 1rem; font-size: .92rem; }
  .pv-lines li span { color: var(--muted); }
  .pv-lines li b { font-weight: 600; }

  /* Every plan, the same orbs */
  .pv-section { padding-top: clamp(3.5rem, 7vw, 5.5rem); }
  .pv-section > h2 { margin: 0 0 1.4rem; text-align: center; font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 700; }
  .pv-plans { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; }
  .pv-card { display: grid; justify-items: center; text-align: center; gap: .2rem; padding: 1.5rem 1rem 1.3rem; border-radius: 22px; border: 1px solid var(--line); background: var(--surface); cursor: pointer; font: inherit; color: inherit;
    transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease; }
  .pv-card:hover { transform: translateY(-2px); box-shadow: 0 18px 36px -24px color-mix(in srgb, var(--brand-accent) 55%, transparent); }
  .pv-card.is-on { border: 2px solid var(--brand-accent); box-shadow: 0 18px 36px -22px color-mix(in srgb, var(--brand-accent) 70%, transparent); }
  .pv-card .orb { --orb-size: 64px; margin-bottom: .7rem; }
  .pv-card b { font-size: 1.1rem; font-weight: 700; }
  .pv-card span { color: var(--muted); font-size: .9rem; }
  .pv-card strong { margin-top: .6rem; font-size: 1.25rem; font-variant-numeric: tabular-nums; }

  .pv-compare { overflow-x: auto; border: 1px solid var(--line); border-radius: 22px; background: var(--surface); }
  .pv-compare table { width: 100%; min-width: 760px; border-collapse: collapse; font-size: .93rem; }
  .pv-compare th, .pv-compare td { padding: 1rem; text-align: start; border-bottom: 1px solid var(--line); }
  .pv-compare thead th { font-weight: 700; }
  .pv-compare thead .orb { --orb-size: 20px; vertical-align: middle; margin-inline-end: .45rem; box-shadow: none; }
  .pv-compare tbody th { font-weight: 500; color: var(--muted); }
  .pv-compare tr:last-child > * { border-bottom: 0; }
  .pv-compare .is-on { background: var(--violet-soft); }

  .pv-faq { max-width: 820px; margin: 0 auto; }
  .pv-faq details { border-bottom: 1px solid var(--line); padding: 1.15rem 0; }
  .pv-faq summary { cursor: pointer; font-weight: 600; list-style: none; display: flex; justify-content: space-between; gap: 1rem; }
  .pv-faq summary::after { content: "+"; color: var(--accent-text); font-weight: 400; font-size: 1.3rem; line-height: 1; }
  .pv-faq details[open] summary::after { content: "−"; }
  .pv-faq summary::-webkit-details-marker { display: none; }
  .pv-faq p { margin: .7rem 0 0; color: var(--muted); line-height: 1.8; }

  .pv-sticky { display: none; }
  .pv-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

  @media (max-width: 960px) {
    .pv-studio { grid-template-columns: 1fr; }
    .pv-plan { border-inline-start: 0; border-top: 1px solid var(--line); }
    .pv-plans { grid-template-columns: 1fr; gap: 10px; }
    .pv-card { grid-template-columns: auto 1fr auto; justify-items: start; text-align: start; column-gap: 1rem; padding: 1rem 1.1rem; }
    .pv-card .orb { --orb-size: 44px; grid-row: span 2; margin: 0; }
    .pv-card strong { grid-column: 3; grid-row: 1 / span 2; align-self: center; margin: 0; }
    .pv-station span { font-size: .7rem; }
    .pv-station:not(.is-on) span b { display: none; }
    .pv-sticky { display: flex; position: fixed; inset: auto 0 0; z-index: 30; align-items: center; justify-content: space-between; gap: 1rem;
      padding: .7rem 1rem calc(.7rem + env(safe-area-inset-bottom)); background: color-mix(in srgb, var(--surface) 92%, transparent);
      backdrop-filter: blur(12px); border-top: 1px solid var(--line); transform: translateY(110%); transition: transform .25s ease; }
    .pv-sticky.is-shown { transform: none; }
    /* The chat button moves up while the bar is shown, so it never covers the button. */
    body.pv-bar .wa-widget { bottom: calc(84px + env(safe-area-inset-bottom)) !important; transition: bottom .25s ease; }
    .pv-sticky b { display: block; } .pv-sticky span { color: var(--muted); font-size: .85rem; }
    .pv-sticky .pv-cta { margin: 0; padding: .7rem 1.3rem; }
  }
  @media (prefers-reduced-motion: reduce) { .pv *, .pv-sticky { transition: none !important; } }
</style>
</head>
<body>
  @include('components.header')

  <main class="pv">
    <div class="pv-wrap">
      <section class="pv-hero">
        <h1>{{ $t('ادفع حسب الدقائق', 'Pay for the minutes') }}<span>{{ $t('التي تحتاجها كل شهر', 'you use each month') }}</span></h1>
        <p>{{ $t('اختر عدد دقائق الصوت التي تحتاجها، وستظهر لك الباقة المناسبة وسعرها. يمكنك تغيير باقتك أو إلغاؤها في أي وقت.', 'Choose how many minutes of voice you need and see the plan that fits, with its price. Change or cancel your plan at any time.') }}</p>

      </section>

      <section class="pv-studio" aria-label="{{ $t('اختر دقائقك', 'Choose your minutes') }}">
        <div class="pv-mixer">
          <div class="pv-row">
            <label class="pv-label" for="pv-mins">{{ $t('دقائق الصوت كل شهر', 'Minutes of voice each month') }}</label>
            <div class="pv-cycle" role="group" aria-label="{{ $t('مدة الدفع', 'Billing') }}">
              <button type="button" data-cycle="m" aria-pressed="true">{{ $t('شهري', 'Monthly') }}</button>
              <button type="button" data-cycle="y" aria-pressed="false">{{ $t('سنوي', 'Yearly') }}<small>−{{ (int) $yearlyDiscountPercent }}%</small></button>
            </div>
          </div>
          <div class="pv-amount">
            <input id="pv-mins" inputmode="numeric" value="{{ number_format($start['minutes']) }}">
            <b>{{ $t('دقيقة', 'minutes') }}</b>
            <em id="pv-eq"></em>
          </div>

          <div class="pv-track">
            <div class="pv-wave">
              <svg id="pv-bars" viewBox="0 0 1000 86" preserveAspectRatio="none" aria-hidden="true"></svg>
              <input id="pv-slider" type="range" min="0" step="1" aria-label="{{ $t('شريط الدقائق', 'Minutes') }}">
              <span class="pv-head" id="pv-head"></span>
            </div>
            <div class="pv-stations" id="pv-stations">
              @foreach($planData as $p)
                <button type="button" class="pv-station" data-slug="{{ $p['slug'] }}" tabindex="-1">
                  <x-orb :name="$p['orb']" />
                  <span><b>{{ $p['name'] }}</b><small>{{ number_format($p['minutes']) }}</small></span>
                </button>
              @endforeach
            </div>
          </div>

          <div class="pv-presets" id="pv-presets" role="group" aria-label="{{ $t('اختيارات سريعة', 'Quick picks') }}"></div>
        </div>

        <aside class="pv-plan" aria-live="off">
          <div class="pv-orbs">
            @foreach($planData as $p)<x-orb :name="$p['orb']" data-slug="{{ $p['slug'] }}" />@endforeach
          </div>
          <div class="pv-plan-kicker" id="pv-kicker"></div>
          <h2 id="pv-name"></h2>
          <div class="pv-price"><b id="pv-price"></b><s id="pv-was"></s></div>
          <div class="pv-then" id="pv-then"></div>
          <div class="pv-ends" id="pv-ends" hidden></div>
          <a class="pv-cta" id="pv-cta" href="#"></a>
          <p class="pv-pay">{{ $t('الدفع بالبطاقة، أو بالمحفظة الإلكترونية داخل مصر.', 'Pay by card, or by mobile wallet in Egypt.') }}</p>
          <ul class="pv-lines" id="pv-lines"></ul>
        </aside>
      </section>
      <div class="pv-proof">
<div class="pv-faces">
          <span>@foreach($faces as $face)<img src="{{ asset('images/speakers/'.$face.'.webp') }}" alt="" width="34" height="34">@endforeach</span>
          {{ $t('أكثر من 200 لهجة ولكنة في كل الباقات', 'More than 200 dialects and accents on every plan') }}
        </div>
      </div>
      <p class="pv-sr" aria-live="polite" id="pv-live"></p>

      <section class="pv-section">
        <h2>{{ $t('كل الباقات', 'All plans') }}</h2>
        <div class="pv-plans">
          @foreach($planData as $p)
            <button type="button" class="pv-card" data-slug="{{ $p['slug'] }}">
              <x-orb :name="$p['orb']" />
              <b>{{ $p['name'] }}</b>
              <span>{{ number_format($p['minutes']) }} {{ $t('دقيقة شهريًا', 'min a month') }}</span>
              <strong data-price="{{ $p['slug'] }}">{{ $p['price_m'] }}</strong>
            </button>
          @endforeach
        </div>
      </section>

      <section class="pv-section">
        <h2>{{ $t('مقارنة الباقات', 'Compare plans') }}</h2>
        <div class="pv-compare">
          <table>
            <thead><tr><th scope="col"><span class="pv-sr">{{ $t('الميزة', 'Feature') }}</span></th>
              @foreach($plans as $p)<th scope="col" data-col="{{ $p['slug'] }}"><x-orb :name="\App\Support\Orbs::forPlan((string) $p['slug'])" />{{ $p['name'] }}</th>@endforeach
            </tr></thead>
            <tbody>
              @foreach($compareLabels as $label)
                <tr><th scope="row">{{ $label }}</th>
                  @foreach($plans as $p)
                    @php $line = collect($p['compare_lines'] ?? [])->firstWhere('label', $label); @endphp
                    <td data-col="{{ $p['slug'] }}">{{ $line['value'] ?? '—' }}</td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>

      <section class="pv-section" style="padding-bottom: 5rem">
        <h2>{{ $t('أسئلة شائعة', 'Questions') }}</h2>
        <div class="pv-faq">
          @foreach([1, 2, 5, 6] as $n)
            <details><summary>{{ __('ui.pricing.faq'.$n.'_q') }}</summary><p>{{ __('ui.pricing.faq'.$n.'_a') }}</p></details>
          @endforeach
        </div>
      </section>
    </div>
  </main>

  <div class="pv-sticky" id="pv-sticky" aria-hidden="true">
    <div><b id="pv-s-name"></b><span id="pv-s-price"></span></div>
    <a class="pv-cta" id="pv-s-cta" href="#" tabindex="-1">{{ $t('اشترك', 'Subscribe') }}</a>
  </div>

  @include('components.footer')
  @include('components.scripts')
  @include('components.orb-script')
  <script>
  (function () {
    const AR = @json($ar);
    const PLANS = @json($planData);
    const T = {
      fits: AR ? 'الأنسب لـ :m دقيقة شهريًا' : 'Fits :m minutes a month',
      sub: AR ? 'اشترك في :p' : 'Get :p',
      perMonth: AR ? 'شهريًا' : 'a month',
      firstMonth: AR ? 'للشهر الأول' : 'first month',
      yearly: AR ? 'شهريًا، تُدفع سنويًا (:t)' : 'a month, billed yearly (:t)',
      until: AR ? 'سعر الشهر الأول ساري حتى :d' : 'First-month price until :d',
      approx: AR ? 'نحو :h' : 'about :h',
      live: AR ? ':m دقيقة شهريًا، باقة :p' : ':m minutes a month, :p plan',
    };
    const ENDS = @json($planData->mapWithKeys(fn ($p) => [$p['slug'] => $endsLabel($p['ends'])]));
    const fmt = n => n.toLocaleString('en');
    const max = PLANS[PLANS.length - 1].minutes, min = 5;
    const STOPS = [...new Set([5, 10, 15, 30, 60, 100, 150, 200, 300, 400, 500, 750, 1000, 1250, 1750, 2500, ...PLANS.map(p => p.minutes)])].filter(m => m <= max).sort((a, b) => a - b);
    // Each plan gets an equal stretch of the track; minutes run on a log scale inside it.
    const ANCH = [min, ...PLANS.map(p => p.minutes)];
    const posRaw = m => {
      for (let i = 1; i < ANCH.length; i++) {
        if (m <= ANCH[i]) return (i - 1 + Math.log(m / ANCH[i - 1]) / Math.log(ANCH[i] / ANCH[i - 1])) / (ANCH.length - 1);
      }
      return 1;
    };
    const pos = m => .045 + .91 * posRaw(m);
    const $ = id => document.getElementById(id);
    const slider = $('pv-slider'); slider.max = STOPS.length - 1;
    let cycle = 'm', mins = PLANS.find(p => p.slug === @json($start['slug'])).minutes;

    // The waveform, drawn once
    const svg = $('pv-bars'), N = 110, bars = [];
    for (let i = 0; i < N; i++) {
      const env = .32 + .68 * Math.abs(Math.sin(i * .075 + .6));
      const h = 6 + 70 * env * Math.abs(Math.sin(i * .63) * Math.cos(i * .21 + 1.1));
      const r = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
      r.setAttribute('x', i * 1000 / N + 2.2); r.setAttribute('width', 1000 / N - 4.4); r.setAttribute('y', 43 - h / 2); r.setAttribute('height', h); r.setAttribute('rx', 3);
      svg.appendChild(r); bars.push(r);
    }
    document.querySelectorAll('.pv-station').forEach(el => {
      const p = PLANS.find(x => x.slug === el.dataset.slug);
      el.style[AR ? 'right' : 'left'] = (pos(p.minutes) * 100) + '%';
      el.addEventListener('click', () => set(p.minutes));
    });
    const PRESETS = AR
      ? [[15, 'تجربة سريعة'], [60, 'مقاطع أسبوعية'], [200, 'محتوى يومي'], [500, 'قناة نشطة'], [max, 'إنتاج كبير']]
      : [[15, 'A quick try'], [60, 'Weekly clips'], [200, 'Daily posts'], [500, 'A busy channel'], [max, 'Large production']];
    $('pv-presets').innerHTML = PRESETS.map(([m, l]) => '<button type="button" data-m="' + m + '">' + l + '</button>').join('');
    $('pv-presets').addEventListener('click', e => { const b = e.target.closest('button'); if (b) set(+b.dataset.m); });
    document.querySelectorAll('.pv-card').forEach(c => c.addEventListener('click', () => { set(PLANS.find(p => p.slug === c.dataset.slug).minutes); $('pv-mins').scrollIntoView({ behavior: 'smooth', block: 'center' }); }));
    document.querySelectorAll('.pv-cycle button').forEach(b => b.addEventListener('click', () => {
      cycle = b.dataset.cycle;
      document.querySelectorAll('.pv-cycle button').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
      set(mins);
    }));

    const hours = m => {
      const h = Math.floor(m / 60), r = m % 60;
      if (!h) return AR ? m + ' دقيقة' : m + ' min';
      if (!AR) return h + ' h' + (r ? ' ' + r + ' min' : '');
      const hw = h === 1 ? 'ساعة' : h === 2 ? 'ساعتين' : h <= 10 ? h + ' ساعات' : h + ' ساعة';
      return hw + (r ? ' و' + r + ' دقيقة' : '');
    };

    function set(m) {
      m = Math.max(min, Math.min(max, Math.round(m) || min));
      mins = m;
      const k = STOPS.reduce((a, s, i) => Math.abs(pos(s) - pos(m)) < Math.abs(pos(STOPS[a]) - pos(m)) ? i : a, 0);
      slider.value = k;
      const plan = PLANS.find(p => m <= p.minutes) || PLANS[PLANS.length - 1];
      const x = pos(m);
      bars.forEach((b, i) => b.setAttribute('fill', (i + .5) / N <= x ? 'var(--brand-accent)' : 'color-mix(in srgb, var(--muted) 30%, transparent)'));
      $('pv-head').style.left = (AR ? 100 - x * 100 : x * 100) + '%';
      if (document.activeElement !== $('pv-mins')) $('pv-mins').value = fmt(m);
      $('pv-eq').textContent = T.approx.replace(':h', hours(m));
      document.querySelectorAll('.pv-station, .pv-card').forEach(el => el.classList.toggle('is-on', el.dataset.slug === plan.slug));
      document.querySelectorAll('.pv-orbs .orb').forEach(el => el.classList.toggle('is-on', el.dataset.slug === plan.slug));
      document.querySelectorAll('[data-col]').forEach(el => el.classList.toggle('is-on', el.dataset.col === plan.slug));
      document.querySelectorAll('#pv-presets button').forEach(b => b.setAttribute('aria-pressed', String(+b.dataset.m === m)));
      document.querySelectorAll('[data-price]').forEach(el => { const p = PLANS.find(x => x.slug === el.dataset.price); el.textContent = cycle === 'y' ? p.price_y : (p.was_m || p.price_m); });

      $('pv-kicker').textContent = '✓ ' + T.fits.replace(':m', fmt(plan.minutes));
      $('pv-name').textContent = plan.name;
      const offer = cycle === 'm' && plan.was_m;
      $('pv-price').textContent = cycle === 'y' ? plan.price_y : plan.price_m;
      $('pv-was').textContent = offer ? plan.was_m : '';
      $('pv-then').textContent = cycle === 'y'
        ? T.yearly.replace(':t', plan.yearly_total)
        : (offer ? T.firstMonth + (AR ? '، ثم ' : ', then ') + plan.was_m + ' ' + T.perMonth : T.perMonth);
      const ends = offer && ENDS[plan.slug];
      $('pv-ends').hidden = !ends; if (ends) $('pv-ends').textContent = T.until.replace(':d', ends);
      $('pv-cta').textContent = T.sub.replace(':p', plan.name);
      $('pv-cta').href = $('pv-s-cta').href = plan.href.replace('monthly', cycle === 'y' ? 'yearly' : 'monthly');
      $('pv-lines').innerHTML = plan.lines.map(l => '<li><span>' + l.label + '</span><b>' + l.value + '</b></li>').join('');
      $('pv-s-name').textContent = plan.name; $('pv-s-price').textContent = cycle === 'y' ? plan.price_y : plan.price_m;
      slider.setAttribute('aria-valuetext', T.live.replace(':m', fmt(m)).replace(':p', plan.name));
      clearTimeout(set.t); set.t = setTimeout(() => { $('pv-live').textContent = T.live.replace(':m', fmt(m)).replace(':p', plan.name); }, 500);
    }
    slider.addEventListener('input', () => set(STOPS[+slider.value]));
    $('pv-mins').addEventListener('change', e => set(+String(e.target.value).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[^\d]/g, '')));
    // On phones: the plan and its button stay in reach once the plan's own button scrolls away
    const sticky = $('pv-sticky');
    new IntersectionObserver(([e]) => {
      const show = !e.isIntersecting && matchMedia('(max-width: 960px)').matches;
      sticky.classList.toggle('is-shown', show); document.body.classList.toggle('pv-bar', show);
    }).observe($('pv-cta'));
    set(mins);
  })();
  </script>
</body>
</html>
