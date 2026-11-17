{{-- The yearly offer bar above the header (App\Support\YearlyOfferBanner): a soft violet line
     that reads "Yearly plans | 50% off | Ends 31 December", with a small white button to the
     pricing page on yearly. The chosen discount sits bright in the middle of a picker, the
     numbers either side of it small and fading out above and below. Screen readers hear one
     sentence. Off until the admin turns it on. --}}
@if(! empty($offerBanner))
<style>
  /* A soft wash of the brand colour that the page sits on: deep violet in dark, pale violet
     in light. One text size, no bold, no glow; the header's top corners are rounded onto it. */
  .offer-bar {
    position: relative;
    z-index: 70;
    color: #fff;
    background:
      radial-gradient(60% 220% at 50% 0%, rgba(118, 89, 250, .42), rgba(118, 89, 250, .14) 55%, rgba(118, 89, 250, 0) 85%),
      #17141F;
  }
  html[data-theme="light"] .offer-bar {
    color: #17132B;
    background:
      radial-gradient(60% 220% at 50% 0%, rgba(118, 89, 250, .26), rgba(118, 89, 250, .10) 55%, rgba(118, 89, 250, 0) 85%),
      #F4F1FF;
  }
  /* The page's rounded top edge, in the header's own colour. */
  .offer-bar::after { content: ""; display: block; height: 20px; border-radius: 20px 20px 0 0; background: var(--bg); }
  html[data-theme="light"] .offer-bar::after { background: #fff; }
  .offer-bar-in {
    min-height: 58px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 9px 20px;
    font-size: 16px;
    line-height: 24px;
  }
  .offer-bar-label { font-weight: 500; white-space: nowrap; }
  .offer-bar-ends { color: #A9A6B8; font-weight: 400; white-space: nowrap; }
  html[data-theme="light"] .offer-bar-ends { color: #6E6A85; }
  .offer-bar-sep { flex: none; width: 1px; height: 18px; background: rgba(255, 255, 255, .2); }
  html[data-theme="light"] .offer-bar-sep { background: rgba(23, 19, 43, .18); }
  .offer-bar-offer { flex: none; display: inline-flex; align-items: center; gap: 7px; color: #CFC6FF; font-weight: 500; }
  html[data-theme="light"] .offer-bar-offer { color: #5236E0; }
  /* The picker: the chosen number in the middle, ten either side small and faded. */
  .offer-bar-reel {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 58px;
    margin-block: -9px;
    overflow: hidden;
    -webkit-mask-image: linear-gradient(to bottom, transparent 4%, #000 30%, #000 70%, transparent 96%);
    mask-image: linear-gradient(to bottom, transparent 4%, #000 30%, #000 70%, transparent 96%);
  }
  .offer-bar-n { display: block; white-space: nowrap; font-variant-numeric: tabular-nums; unicode-bidi: isolate; }
  .offer-bar-n.is-near { font-size: 12px; line-height: 14px; font-weight: 500; opacity: .45; }
  .offer-bar-n.is-on { font-size: 17px; line-height: 22px; font-weight: 600; }
  .offer-bar-n.is-gap { visibility: hidden; }
  .offer-bar-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .offer-bar-btn {
    flex: none;
    margin-inline-start: 4px;
    padding: 8px 18px;
    border-radius: 999px;
    background: #fff;
    box-shadow: 0 0 0 1px rgba(23, 19, 43, .12), 0 1px 2px rgba(23, 19, 43, .06);
    color: #17132B;
    font-size: 15px;
    font-weight: 500;
    line-height: 22px;
    text-decoration: none;
    white-space: nowrap;
  }
  .offer-bar-btn:hover { background: #F1EEFF; color: #17132B; }
  .offer-bar-btn:focus-visible { outline: 2px solid #7659FA; outline-offset: 3px; }
  @media (max-width: 760px) {
    .offer-bar::after { height: 16px; border-radius: 16px 16px 0 0; }
    .offer-bar-in { gap: 10px; padding: 7px 12px; min-height: 50px; font-size: 14px; line-height: 20px; }
    /* On a phone: the discount, the last day and the button, on one line. */
    .offer-bar-label, .offer-bar-sep.is-label { display: none; }
    .offer-bar-sep { height: 14px; }
    .offer-bar-reel { height: 50px; margin-block: -7px; }
    .offer-bar-n.is-on { font-size: 15px; line-height: 20px; }
    .offer-bar-n.is-near { font-size: 11px; line-height: 13px; }
    .offer-bar-btn { margin-inline-start: 0; padding: 6px 12px; font-size: 13px; line-height: 20px; }
  }
  @media (max-width: 380px) {
    .offer-bar-ends, .offer-bar-sep.is-ends { display: none; }
  }
</style>
<div class="offer-bar" role="region" aria-label="{{ $offerBanner['aria'] }}">
  <div class="offer-bar-in">
    <span class="offer-bar-label">{{ $offerBanner['label'] }}</span>
    <span class="offer-bar-sep is-label" aria-hidden="true"></span>
    <span class="offer-bar-offer">
      @if($offerBanner['before'] !== '')<span aria-hidden="true">{{ $offerBanner['before'] }}</span>@endif
      <span class="offer-bar-reel" aria-hidden="true">
        <span class="offer-bar-n is-near{{ $offerBanner['above'] === null ? ' is-gap' : '' }}">{{ $offerBanner['above'] ?? $offerBanner['percent'] }}%</span>
        <span class="offer-bar-n is-on">{{ $offerBanner['percent'] }}%</span>
        <span class="offer-bar-n is-near{{ $offerBanner['below'] === null ? ' is-gap' : '' }}">{{ $offerBanner['below'] ?? $offerBanner['percent'] }}%</span>
      </span>
      @if($offerBanner['after'] !== '')<span aria-hidden="true">{{ $offerBanner['after'] }}</span>@endif
      <span class="offer-bar-sr">{{ $offerBanner['aria'] }}</span>
    </span>
    @if($offerBanner['ends'])
      <span class="offer-bar-sep is-ends" aria-hidden="true"></span>
      <span class="offer-bar-ends">{{ $offerBanner['ends'] }}</span>
    @endif
    <a class="offer-bar-btn" href="{{ $offerBanner['url'] }}">{{ $offerBanner['button'] }}</a>
  </div>
</div>
@endif
