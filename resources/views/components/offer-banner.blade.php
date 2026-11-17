{{-- The yearly offer bar above the header (App\Support\YearlyOfferBanner): a slim dark line
     that reads "Yearly plans | 50% off | Ends 31 December", with a small white button to the
     pricing page on yearly. The chosen discount sits bright in the middle of a picker, the
     numbers either side of it small and fading out above and below. Screen readers hear one
     sentence. Off until the admin turns it on. --}}
@if(! empty($offerBanner))
<style>
  .offer-bar {
    position: relative;
    z-index: 70;
    color: #fff;
    background:
      radial-gradient(40% 200% at 50% 50%, rgba(118, 89, 250, .38), rgba(118, 89, 250, .10) 55%, rgba(118, 89, 250, 0) 80%),
      #0B0B0F;
    box-shadow: inset 0 -1px 0 rgba(169, 156, 255, .24);
  }
  html:not([data-theme="light"]) .offer-bar {
    background:
      radial-gradient(40% 200% at 50% 50%, rgba(118, 89, 250, .36), rgba(118, 89, 250, .09) 55%, rgba(118, 89, 250, 0) 80%),
      #15151C;
  }
  /* Sized like the site's header: a 60px line, 16px text, a 40px button. */
  .offer-bar-in {
    min-height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 18px;
    padding: 10px 20px;
    font-size: 16px;
    line-height: 24px;
  }
  .offer-bar-label { color: #fff; font-weight: 500; white-space: nowrap; }
  .offer-bar-ends { color: #B9B9C6; font-weight: 400; white-space: nowrap; }
  .offer-bar-sep { flex: none; width: 1px; height: 18px; background: rgba(255, 255, 255, .2); }
  .offer-bar-offer { flex: none; display: inline-flex; align-items: center; gap: 7px; color: #C9BFFF; font-weight: 600; }
  /* The picker: the chosen number bright in the middle, ten either side small and fading out. */
  .offer-bar-reel {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 60px;
    margin-block: -10px;
    overflow: hidden;
    -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 32%, #000 68%, transparent 100%);
    mask-image: linear-gradient(to bottom, transparent 0, #000 32%, #000 68%, transparent 100%);
  }
  .offer-bar-n { display: block; white-space: nowrap; font-variant-numeric: tabular-nums; unicode-bidi: isolate; }
  .offer-bar-n.is-near { font-size: 12px; line-height: 15px; font-weight: 600; color: rgba(201, 191, 255, .5); }
  .offer-bar-n.is-on { font-size: 18px; line-height: 24px; font-weight: 700; color: #CFC6FF; text-shadow: 0 0 20px rgba(118, 89, 250, .8); }
  .offer-bar-n.is-gap { visibility: hidden; }
  .offer-bar-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .offer-bar-btn {
    flex: none;
    margin-inline-start: 6px;
    padding: 9px 20px;
    border-radius: 999px;
    background: #fff;
    box-shadow: 0 0 0 1px rgba(255, 255, 255, .5), 0 1px 2px rgba(0, 0, 0, .25);
    color: #0B0B0F;
    font-size: 15px;
    font-weight: 600;
    line-height: 22px;
    text-decoration: none;
    white-space: nowrap;
  }
  .offer-bar-btn:hover { background: #ECE8FF; color: #0B0B0F; }
  .offer-bar-btn:focus-visible { outline: 2px solid #A99CFF; outline-offset: 3px; }
  @media (max-width: 1024px) {
    .offer-bar-in { gap: 14px; font-size: 15px; }
  }
  @media (max-width: 760px) {
    .offer-bar { background: radial-gradient(70% 170% at 50% 50%, rgba(118, 89, 250, .36), rgba(118, 89, 250, 0) 80%), #0B0B0F; }
    .offer-bar-in { gap: 12px; padding: 8px 14px; min-height: 52px; font-size: 14px; line-height: 20px; }
    /* On a phone: the discount, the last day and the button, on one line. */
    .offer-bar-label, .offer-bar-sep.is-label { display: none; }
    .offer-bar-reel { height: 52px; margin-block: -8px; }
    .offer-bar-n.is-on { font-size: 16px; line-height: 22px; }
    .offer-bar-n.is-near { font-size: 11px; line-height: 13px; }
    .offer-bar-btn { margin-inline-start: 0; padding: 7px 15px; font-size: 14px; line-height: 20px; }
  }
  @media (max-width: 360px) {
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
