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
      radial-gradient(42% 180% at 50% 50%, rgba(118, 89, 250, .42), rgba(118, 89, 250, .12) 55%, rgba(118, 89, 250, 0) 78%),
      #0B0B0F;
    box-shadow: inset 0 -1px 0 rgba(169, 156, 255, .28);
  }
  html:not([data-theme="light"]) .offer-bar {
    background:
      radial-gradient(42% 180% at 50% 50%, rgba(118, 89, 250, .40), rgba(118, 89, 250, .10) 55%, rgba(118, 89, 250, 0) 78%),
      #15151C;
  }
  .offer-bar-in {
    min-height: 46px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    padding: 7px 16px;
    font-size: 14px;
    line-height: 20px;
  }
  .offer-bar-label { color: #fff; font-weight: 500; white-space: nowrap; }
  .offer-bar-ends { color: #B4B4BF; white-space: nowrap; }
  .offer-bar-sep { flex: none; width: 1px; height: 14px; background: rgba(255, 255, 255, .22); }
  .offer-bar-offer { flex: none; display: inline-flex; align-items: center; gap: 6px; color: #C4B9FF; font-weight: 700; }
  /* The picker: the chosen number bright in the middle, ten either side small and fading out. */
  .offer-bar-reel {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 46px;
    margin-block: -7px;
    overflow: hidden;
    -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 34%, #000 66%, transparent 100%);
    mask-image: linear-gradient(to bottom, transparent 0, #000 34%, #000 66%, transparent 100%);
  }
  .offer-bar-n { display: block; white-space: nowrap; font-variant-numeric: tabular-nums; unicode-bidi: isolate; }
  .offer-bar-n.is-near { font-size: 11px; line-height: 13px; font-weight: 600; color: rgba(196, 185, 255, .55); }
  .offer-bar-n.is-on { font-size: 15px; line-height: 19px; font-weight: 700; color: #C4B9FF; text-shadow: 0 0 18px rgba(118, 89, 250, .85); }
  .offer-bar-n.is-gap { visibility: hidden; }
  .offer-bar-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .offer-bar-btn {
    flex: none;
    margin-inline-start: 4px;
    padding: 7px 14px;
    border-radius: 999px;
    background: #fff;
    color: #0B0B0F;
    font-size: 13px;
    font-weight: 600;
    line-height: 16px;
    text-decoration: none;
    white-space: nowrap;
  }
  .offer-bar-btn:hover { background: #ECE8FF; color: #0B0B0F; }
  .offer-bar-btn:focus-visible { outline: 2px solid #A99CFF; outline-offset: 2px; }
  @media (max-width: 760px) {
    .offer-bar { background: radial-gradient(70% 160% at 50% 50%, rgba(118, 89, 250, .40), rgba(118, 89, 250, 0) 80%), #0B0B0F; }
    .offer-bar-in { gap: 10px; padding: 7px 12px; min-height: 44px; font-size: 13px; }
    /* On a phone: the discount, the last day and the button, on one line. */
    .offer-bar-label, .offer-bar-sep.is-label { display: none; }
    .offer-bar-reel { height: 44px; }
    .offer-bar-n.is-on { font-size: 14px; }
    .offer-bar-btn { margin-inline-start: 0; padding: 6px 12px; font-size: 12.5px; }
  }
  @media (max-width: 340px) {
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
