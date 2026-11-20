{{-- Extra credits (App\Services\Billing\ExtraCredits): the studio's dialog when a paid
     member's credits run out. One title, one sentence, the minutes as chips, what they
     cost in the member's own currency, and how they pay: the card they pay with or
     another card, and, for members who pay their plan by hand, InstaPay or Vodafone Cash.
     Opened by window.ListenExtraCredits.open() (the studio, when a voice is refused for
     credits; the "Add credits" chip; a link with ?extra_credits=1). Only members who can
     buy them get it: the server checks again on every step. --}}
@php
  $xcUserId = (int) auth()->id();
  $xcStatus = $xcUserId > 0 ? app(\App\Services\Billing\ExtraCredits::class)->status($xcUserId) : null;
@endphp
@if($xcStatus === \App\Services\Billing\ExtraCredits::STATUS_OK)
<dialog class="xc" id="xcDialog" aria-labelledby="xcTitle"
  data-offer-url="{{ route('billing.extra-credits') }}"
  data-card-url="{{ route('billing.extra-credits.card') }}"
  data-confirm-url="{{ route('billing.extra-credits.card.confirm') }}"
  data-transfer-url="{{ route('billing.extra-credits.transfer') }}">
  <div class="xc-stack" id="xcStack">

    {{-- How much, and how they pay --}}
    <section class="xc-view is-on" data-xc-view="buy">
      <div class="xc-head">
        <h2 class="xc-title" id="xcTitle"></h2>
        <button type="button" class="xc-icon" data-xc-close aria-label="{{ __('ui.extra_credits.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      </div>
      <p class="xc-lead" id="xcLead"></p>
      <div class="xc-chips" id="xcChips" role="radiogroup" aria-label="{{ __('ui.extra_credits.presets_label') }}"></div>
      <div class="xc-summary">
        <span class="xc-summary-t"><b id="xcCredits"></b><small id="xcMinutes"></small></span>
        <b class="xc-summary-price" id="xcPrice"></b>
      </div>
      <div class="xc-payline">
        <span class="xc-cardglyph" id="xcGlyph" aria-hidden="true"></span>
        <span id="xcPayWith"></span>
        <b class="xc-ltr" dir="ltr" id="xcPayLast"></b>
        <span class="xc-sp"></span>
        <button type="button" class="xc-link" id="xcChange">{{ __('ui.extra_credits.change') }}</button>
      </div>
      <p class="xc-note" id="xcBuyNote" role="alert" hidden></p>
      <div class="xc-btns">
        <button type="button" class="xc-btn" data-xc-close>{{ __('ui.extra_credits.not_now') }}</button>
        <button type="button" class="xc-btn is-primary" id="xcPay"><span class="xc-spin" aria-hidden="true"></span><span id="xcPayLabel"></span></button>
      </div>
      <p class="xc-fine" id="xcFine"></p>
    </section>

    {{-- Payment method --}}
    <section class="xc-view" data-xc-view="method" hidden>
      <div class="xc-head">
        <button type="button" class="xc-icon xc-back" data-xc-back aria-label="{{ __('ui.extra_credits.back') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
        <h2 class="xc-title xc-grow">{{ __('ui.extra_credits.method_title') }}</h2>
        <button type="button" class="xc-icon" data-xc-close aria-label="{{ __('ui.extra_credits.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      </div>
      <div class="xc-methods" id="xcMethods" role="radiogroup" aria-label="{{ __('ui.extra_credits.method_title') }}">
        <div class="xc-opt" data-xc-method="saved" hidden>
          <label class="xc-m">
            <input type="radio" name="xcMethod" value="saved">
            <span class="xc-radio" aria-hidden="true"></span>
            <span class="xc-m-lab"><span class="xc-cardglyph" aria-hidden="true"></span><span class="xc-ltr" dir="ltr" id="xcSavedLast"></span><small id="xcSavedExp"></small></span>
          </label>
        </div>
        <div class="xc-opt" data-xc-method="new" hidden>
          <label class="xc-m">
            <input type="radio" name="xcMethod" value="new">
            <span class="xc-radio" aria-hidden="true"></span>
            <span class="xc-m-lab" id="xcNewLabel">{{ __('ui.extra_credits.another_card') }}</span>
          </label>
          <div class="xc-newcard" id="xcNewCard" hidden>
            <x-card-fields id="xc-card" />
            <label class="xc-save"><input type="checkbox" id="xcSaveCard" checked> <span>{{ __('ui.extra_credits.save_card') }}</span></label>
          </div>
        </div>
        @foreach(['instapay', 'vodafone_cash'] as $xcMethod)
          <div class="xc-opt" data-xc-method="{{ $xcMethod }}" hidden>
            <label class="xc-m">
              <input type="radio" name="xcMethod" value="{{ $xcMethod }}">
              <span class="xc-radio" aria-hidden="true"></span>
              <span class="xc-m-lab xc-m-stack"><span>{{ __('ui.billing.payment_method_'.$xcMethod) }}</span><small>{{ __('ui.extra_credits.transfer_sub') }}</small></span>
            </label>
          </div>
        @endforeach
        <div class="xc-opt" data-xc-method="card" hidden>
          <label class="xc-m">
            <input type="radio" name="xcMethod" value="card">
            <span class="xc-radio" aria-hidden="true"></span>
            <span class="xc-m-lab xc-m-stack"><span class="xc-inline"><span class="xc-cardglyph" aria-hidden="true"></span>{{ __('ui.extra_credits.card') }}</span><small>{{ __('ui.extra_credits.card_instant') }}</small></span>
          </label>
        </div>
      </div>
      <p class="xc-secure" id="xcSecure" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><span>{{ __('ui.extra_credits.secure') }}</span></p>
      <p class="xc-note" id="xcMethodNote" role="alert" hidden></p>
      <div class="xc-btns"><button type="button" class="xc-btn is-primary xc-wide" id="xcUse"><span class="xc-spin" aria-hidden="true"></span><span>{{ __('ui.extra_credits.continue') }}</span></button></div>
    </section>

    {{-- A transfer by InstaPay / Vodafone Cash --}}
    <section class="xc-view" data-xc-view="transfer" hidden>
      <div class="xc-head">
        <button type="button" class="xc-icon xc-back" data-xc-back aria-label="{{ __('ui.extra_credits.back') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
        <h2 class="xc-title xc-grow">{{ __('ui.extra_credits.transfer_title') }}</h2>
        <button type="button" class="xc-icon" data-xc-close aria-label="{{ __('ui.extra_credits.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      </div>
      <div class="xc-amount"><small>{{ __('ui.extra_credits.amount') }}</small><b id="xcTransferAmount"></b></div>
      <a class="xc-dest" id="xcTransferLink" href="#" target="_blank" rel="noopener" hidden><span id="xcTransferLinkText"></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8M10 6H6a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4"/></svg></a>
      <figure class="xc-qr" id="xcTransferQr" hidden><img id="xcTransferQrImg" alt="" width="148" height="148"><figcaption id="xcTransferQrText"></figcaption></figure>
      <p class="xc-instructions" id="xcTransferInstructions" hidden></p>
      <label class="xc-upload" id="xcUpload">
        <input type="file" id="xcProof" accept="image/*">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V5M7.5 9.5L12 5l4.5 4.5M5 19h14"/></svg>
        <span id="xcUploadText">{{ __('ui.extra_credits.attach') }}</span>
      </label>
      <p class="xc-note" id="xcTransferNote" role="alert" hidden></p>
      <div class="xc-btns"><button type="button" class="xc-btn is-primary xc-wide" id="xcSend"><span class="xc-spin" aria-hidden="true"></span><span id="xcSendLabel">{{ __('ui.extra_credits.send') }}</span></button></div>
      <p class="xc-fine">{{ __('ui.extra_credits.transfer_fine') }}</p>
    </section>

    {{-- Added --}}
    <section class="xc-view" data-xc-view="done" hidden>
      <div class="xc-head">
        <span class="xc-mark is-good" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
        <button type="button" class="xc-icon" data-xc-close aria-label="{{ __('ui.extra_credits.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      </div>
      <h2 class="xc-title xc-after-mark" id="xcDoneTitle"></h2>
      <p class="xc-lead">{{ __('ui.extra_credits.done_lead') }}</p>
      <div class="xc-btns"><button type="button" class="xc-btn is-primary xc-wide" id="xcGenerate">{{ __('ui.extra_credits.generate_now') }}</button></div>
    </section>

    {{-- A transfer sent for the team to check --}}
    <section class="xc-view" data-xc-view="wait" hidden>
      <div class="xc-head">
        <span class="xc-mark is-wait" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
        <button type="button" class="xc-icon" data-xc-close aria-label="{{ __('ui.extra_credits.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      </div>
      <h2 class="xc-title xc-after-mark">{{ __('ui.extra_credits.wait_title') }}</h2>
      <p class="xc-lead" id="xcWaitLead"></p>
      <div class="xc-btns"><button type="button" class="xc-btn is-primary xc-wide" data-xc-close>{{ __('ui.extra_credits.ok') }}</button></div>
    </section>
  </div>
</dialog>

<style>
  /* A quiet dialog: one title, one sentence, plain choices and two buttons. Violet only
     marks what is chosen and what to press; the site's own colours, light and dark. */
  .xc {
    --xc-tint: color-mix(in oklab, var(--brand-accent, #7659FA) 9%, var(--surface, #fff));
    --xc-good: #12804F; --xc-good-tint: color-mix(in oklab, #12804F 12%, var(--surface, #fff));
    --xc-wait: #B26A00; --xc-wait-tint: color-mix(in oklab, #E89A1A 16%, var(--surface, #fff));
    width: min(440px, calc(100vw - 32px)); max-height: calc(100dvh - 32px); padding: 0; margin: auto;
    border: 1px solid var(--line); border-radius: 20px; background: var(--surface); color: var(--text);
    box-shadow: 0 24px 60px rgba(17, 15, 26, .22); overflow: hidden auto; overscroll-behavior: contain;
  }
  html[data-theme="dark"] .xc, html:not([data-theme]) .xc { --xc-good: #3FCB8B; --xc-wait: #F0B04A; box-shadow: 0 30px 80px -20px rgba(0, 0, 0, .7); }
  .xc::backdrop { background: rgba(17, 15, 26, .46); }
  .xc[open] { animation: xc-in .26s cubic-bezier(.2, .8, .2, 1); }
  .xc[open]::backdrop { animation: xc-fade .22s ease; }
  .xc.is-closing { animation: xc-out .2s ease forwards; }
  .xc.is-closing::backdrop { animation: xc-fade .2s ease reverse forwards; }
  @keyframes xc-in { from { opacity: 0; transform: translateY(8px) scale(.97); } }
  @keyframes xc-out { to { opacity: 0; transform: scale(.98); } }
  @keyframes xc-fade { from { opacity: 0; } }
  .xc-stack { position: relative; overflow: hidden; }
  .xc-view { padding: 20px 20px 18px; }
  .xc-view[hidden] { display: none; }
  .xc-view.is-leaving { position: absolute; inset: 0 0 auto; }
  .xc-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 30px; }
  .xc-title { margin: 0; font-size: 1.19rem; font-weight: 700; line-height: 1.4; }
  .xc-grow { flex: 1; }
  .xc-after-mark { margin-top: 14px; }
  .xc-icon { width: 32px; height: 32px; flex: none; display: grid; place-items: center; padding: 0; border: 0; border-radius: 9px; background: transparent; color: var(--muted); cursor: pointer; }
  .xc-icon:hover { background: var(--surface2); color: var(--text); }
  .xc-icon svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  html[dir="rtl"] .xc-back svg { transform: scaleX(-1); }
  .xc-lead { margin: 8px 0 0; font-size: .92rem; line-height: 1.75; color: var(--muted); }
  .xc-lead b { color: var(--text); font-weight: 600; }
  .xc-chips { margin-top: 18px; display: grid; grid-template-columns: repeat(var(--xc-n, 5), minmax(0, 1fr)); gap: 6px; }
  .xc-chip { height: 44px; border-radius: 11px; border: 1px solid var(--line-strong, var(--line)); background: var(--surface); color: var(--text); font: inherit; font-size: .98rem; font-weight: 600; font-variant-numeric: tabular-nums; cursor: pointer; transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease; }
  .xc-chip:hover { border-color: color-mix(in oklab, var(--brand-accent) 45%, var(--line)); }
  .xc-chip[aria-checked="true"] { border-color: var(--brand-accent); box-shadow: inset 0 0 0 1px var(--brand-accent); background: var(--xc-tint); color: var(--accent-text, var(--brand-accent)); }
  .xc-summary { margin-top: 12px; display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 12px; background: var(--surface2); }
  .xc-summary-t { display: grid; line-height: 1.4; flex: 1; min-width: 0; }
  .xc-summary-t b, .xc-summary-price { font-size: 1.18rem; font-weight: 700; font-variant-numeric: tabular-nums; }
  .xc-summary-t small { font-size: .8rem; color: var(--muted); }
  .xc-payline { margin-top: 14px; display: flex; align-items: center; gap: 8px; font-size: .86rem; color: var(--muted); }
  .xc-payline b { color: var(--text); font-weight: 600; }
  .xc-sp { flex: 1; }
  .xc-ltr { direction: ltr; unicode-bidi: isolate; }
  .xc-cardglyph { position: relative; width: 26px; height: 18px; flex: none; border: 1.5px solid var(--line-strong, var(--line)); border-radius: 4px; }
  .xc-cardglyph::after { content: ""; position: absolute; inset: 4px 3px auto; height: 2.5px; border-radius: 2px; background: var(--line-strong, var(--line)); }
  .xc-link { padding: 4px 2px; border: 0; background: none; color: var(--accent-text, var(--brand-accent)); font: inherit; font-size: .86rem; font-weight: 600; cursor: pointer; }
  .xc-link:hover { text-decoration: underline; }
  .xc-btns { margin-top: 18px; display: flex; gap: 10px; }
  .xc-btn { flex: 1; min-height: 46px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0 14px; border: 1px solid var(--line-strong, var(--line)); border-radius: 12px; background: var(--surface); color: var(--text); font: inherit; font-size: .95rem; font-weight: 600; cursor: pointer; transition: background-color .15s ease, border-color .15s ease; }
  .xc-btn:hover { background: var(--surface2); }
  .xc-btn.is-primary { flex: 1.5; border-color: var(--brand-accent); background: var(--brand-accent); color: #fff; }
  .xc-btn.is-primary:hover { background: var(--brand-hover, #5B3DF5); }
  .xc-btn.xc-wide { flex: 1; }
  .xc-btn:disabled { cursor: default; opacity: .7; }
  .xc-btn.is-busy { opacity: 1; background: var(--brand-hover, #5B3DF5); }
  .xc-spin { display: none; width: 17px; height: 17px; border-radius: 50%; border: 2.4px solid rgba(255, 255, 255, .35); border-top-color: #fff; animation: xc-spin .8s linear infinite; }
  .xc-btn.is-busy .xc-spin { display: block; }
  @keyframes xc-spin { to { transform: rotate(360deg); } }
  .xc-btn:focus-visible, .xc-chip:focus-visible, .xc-link:focus-visible, .xc-icon:focus-visible, .xc-dest:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
  /* Keyboard focus only: a click on a row shows its radio, not a ring. */
  .xc-opt:has(input[name="xcMethod"]:focus-visible), .xc-upload:has(input:focus-visible) { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
  .xc-fine { margin: 14px 0 0; font-size: .8rem; line-height: 1.65; color: var(--muted); }
  .xc-note { margin: 12px 0 0; padding: 10px 12px; border-radius: 10px; font-size: .84rem; line-height: 1.6; background: color-mix(in oklab, var(--danger, #ee4a77) 10%, var(--surface)); color: var(--text); }
  .xc-note.is-info { background: var(--surface2); }
  /* Payment method */
  .xc-methods { margin-top: 16px; display: grid; gap: 8px; }
  /* Each way to pay is a box of its own, with room around it; the card form opens inside its box. */
  .xc-opt { border: 1px solid var(--line); border-radius: 12px; background: var(--surface); transition: border-color .15s ease; }
  .xc-opt:hover, .xc-opt:has(input[name="xcMethod"]:checked) { border-color: var(--line-strong, var(--line)); }
  .xc-opt[hidden], .xc-newcard[hidden] { display: none; }
  .xc-m { display: flex; align-items: center; gap: 12px; min-height: 56px; padding: 14px 16px; cursor: pointer; }
  .xc-m input { position: absolute; opacity: 0; pointer-events: none; }
  .xc-radio { width: 18px; height: 18px; flex: none; display: grid; place-items: center; border: 1.5px solid var(--line-strong, var(--line)); border-radius: 50%; background: var(--surface); }
  .xc-radio::after { content: ""; width: 8px; height: 8px; border-radius: 50%; background: #fff; transform: scale(0); transition: transform .15s ease; }
  .xc-m input:checked + .xc-radio { border-color: var(--brand-accent); background: var(--brand-accent); }
  .xc-m input:checked + .xc-radio::after { transform: scale(1); }
  .xc-m-lab { flex: 1; display: flex; align-items: center; gap: 8px; min-width: 0; font-size: .92rem; font-weight: 600; }
  .xc-m-lab small { font-size: .8rem; font-weight: 400; color: var(--muted); }
  .xc-m-stack { display: grid; gap: 2px; }
  .xc-inline { display: inline-flex; align-items: center; gap: 8px; }
  .xc-newcard { padding: 0 16px 16px; overflow: hidden; }
  .xc-newcard .card-fields { gap: .7rem; }
  .xc-newcard .card-field-label { font-size: .8rem; margin-bottom: .35rem; }
  .xc-newcard .card-input { min-height: 44px; padding: .72rem .8rem; border-radius: 10px; }
  .xc-save { margin-top: 10px; display: flex; align-items: center; gap: 8px; font-size: .82rem; color: var(--text); cursor: pointer; }
  .xc-save input { width: 16px; height: 16px; accent-color: var(--brand-accent); }
  .xc-secure { margin: 12px 0 0; display: flex; align-items: center; gap: 6px; font-size: .78rem; color: var(--muted); }
  .xc-secure[hidden] { display: none; }
  .xc-secure svg { width: 13px; height: 13px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; }
  /* Transfer */
  .xc-amount { margin-top: 16px; display: grid; gap: 4px; padding: 16px; border-radius: 12px; background: var(--surface2); }
  .xc-amount small { font-size: .8rem; color: var(--muted); }
  .xc-amount b { font-size: 1.6rem; font-weight: 700; font-variant-numeric: tabular-nums; }
  .xc-dest { margin-top: 10px; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 13px 14px; border: 1px solid var(--line); border-radius: 12px; color: var(--accent-text, var(--brand-accent)); font-weight: 600; font-size: .9rem; text-decoration: none; }
  .xc-dest[hidden], .xc-qr[hidden], .xc-instructions[hidden] { display: none; }
  .xc-dest svg { width: 17px; height: 17px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  html[dir="rtl"] .xc-dest svg { transform: scaleX(-1); }
  .xc-qr { margin: 10px 0 0; display: flex; align-items: center; gap: 14px; padding: 12px; border: 1px solid var(--line); border-radius: 12px; }
  .xc-qr img { width: 96px; height: 96px; border-radius: 8px; background: #fff; object-fit: contain; }
  .xc-qr figcaption { font-size: .84rem; color: var(--muted); line-height: 1.6; }
  .xc-instructions { margin: 10px 0 0; font-size: .84rem; line-height: 1.7; color: var(--muted); white-space: pre-line; }
  .xc-upload { margin-top: 10px; min-height: 54px; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 0 14px; border: 1.5px dashed var(--line-strong, var(--line)); border-radius: 12px; color: var(--muted); font-size: .88rem; cursor: pointer; }
  .xc-upload input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .xc-upload svg { width: 18px; height: 18px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  .xc-upload.is-ok { justify-content: flex-start; border-style: solid; border-color: var(--xc-good); background: var(--xc-good-tint); color: var(--xc-good); }
  .xc-upload span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  /* Done / waiting */
  .xc-mark { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 50%; animation: xc-pop .36s cubic-bezier(.2, .8, .2, 1.2); }
  .xc-mark svg { width: 20px; height: 20px; fill: none; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
  .xc-mark.is-good { background: var(--xc-good-tint); } .xc-mark.is-good svg { stroke: var(--xc-good); }
  .xc-mark.is-wait { background: var(--xc-wait-tint); } .xc-mark.is-wait svg { stroke: var(--xc-wait); }
  @keyframes xc-pop { from { transform: scale(.6); opacity: .4; } }
  @media (max-width: 380px) {
    .xc-view { padding: 18px 16px 16px; }
    .xc-chips { gap: 5px; }
    .xc-chip { font-size: .92rem; }
  }
  @media (prefers-reduced-motion: reduce) {
    .xc[open], .xc[open]::backdrop, .xc.is-closing, .xc.is-closing::backdrop, .xc-mark, .xc-spin { animation: none; }
  }
</style>

<script>
  (function () {
    const dialog = document.getElementById('xcDialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const urls = { offer: dialog.dataset.offerUrl, card: dialog.dataset.cardUrl, confirm: dialog.dataset.confirmUrl, transfer: dialog.dataset.transferUrl };
    const t = @json(__('ui.extra_credits'));
    const isRtl = document.documentElement.dir === 'rtl';
    const isArabicPage = String(document.documentElement.lang || '').toLowerCase().startsWith('ar');
    const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (id) => document.getElementById(id);
    const views = {};
    dialog.querySelectorAll('[data-xc-view]').forEach((el) => { views[el.dataset.xcView] = el; });
    const stack = $('xcStack');

    let offer = null;          // what the server offers (GET offer)
    let minutes = 30;
    let method = 'saved';      // saved | new | instapay | vodafone_cash
    let newCard = null;        // {id, last4}: the card typed in, once it is ready
    let context = { remaining: 0, needed: 0 };
    let state = { purchased: false, onClose: null, onGenerate: null };
    let current = 'buy';
    let stripe = null, stripePromise = null, cardForm = null, cardComplete = false;
    let proof = null;

    const fill = (text, values) => String(text || '').replace(/:(\w+)/g, (m, key) => (values && key in values ? values[key] : m));
    const fmt = (n) => Math.round(Number(n) || 0).toLocaleString('en-US');
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const preset = () => (offer?.presets || []).find((p) => p.minutes === minutes) || (offer?.presets || [])[0];
    const byHand = () => method === 'instapay' || method === 'vodafone_cash';
    const manual = (m) => (offer?.manual || []).find((x) => x.method === m);

    function note(id, text, info) {
      const el = $(id);
      if (!el) return;
      el.hidden = !text;
      el.textContent = text || '';
      el.classList.toggle('is-info', Boolean(info));
    }

    function busy(button, on, label) {
      button.disabled = on;
      button.classList.toggle('is-busy', on);
      if (label !== undefined) {
        const target = button.querySelector('[id$="Label"]') || button.lastElementChild;
        if (target) target.textContent = label;
      }
    }

    // ---------- moving between views: the new one slides in, the dialog grows to fit it
    function show(name, direction) {
      if (name === current) return;
      const from = views[current], to = views[name];
      const startHeight = stack.offsetHeight;
      to.hidden = false;
      to.classList.add('is-on');
      if (reduced()) {
        from.hidden = true;
        from.classList.remove('is-on');
        current = name;
        focusFirst(to);
        return;
      }
      from.classList.add('is-leaving');
      const endHeight = to.offsetHeight;
      const shift = 28 * (direction || 1) * (isRtl ? 1 : -1);
      const ease = 'cubic-bezier(.2, .8, .2, 1)';
      stack.animate([{ height: startHeight + 'px' }, { height: endHeight + 'px' }], { duration: 320, easing: ease });
      from.animate([{ opacity: 1, transform: 'translateX(0)' }, { opacity: 0, transform: `translateX(${-shift}px)` }], { duration: 200, easing: 'ease-in', fill: 'forwards' })
        .onfinish = () => { from.hidden = true; from.classList.remove('is-leaving', 'is-on'); from.getAnimations().forEach((a) => a.cancel()); };
      to.animate([{ opacity: 0, transform: `translateX(${shift}px)` }, { opacity: 1, transform: 'translateX(0)' }], { duration: 320, delay: 60, easing: ease, fill: 'backwards' });
      current = name;
      focusFirst(to);
    }

    function focusFirst(view) {
      const target = view.querySelector('.xc-chip[aria-checked="true"], input:checked, .xc-btn.is-primary, button');
      if (target) setTimeout(() => target.focus({ preventScroll: true }), reduced() ? 0 : 120);
    }

    // ---------- the first view
    function paintBuy() {
      const p = preset();
      if (!p) return;
      const remaining = Number(context.remaining || 0);
      $('xcTitle').textContent = context.needed > 0 && remaining > 0 ? t.title_short : (remaining > 0 ? t.title_add : t.title_out);
      const date = `<b>${esc(offer.renews_on)}</b>`;
      $('xcLead').innerHTML = offer.plan_ends
        ? fill(esc(t.lead_plan_ends), { date })
        : (context.needed > 0 && remaining > 0
          ? fill(esc(t.lead_short), { remaining: `<b>${fmt(remaining)}</b>` })
          : (remaining > 0 ? fill(esc(t.lead_add), { remaining: `<b>${fmt(remaining)}</b>`, date }) : fill(esc(t.lead_out), { date })));
      $('xcChips').querySelectorAll('.xc-chip').forEach((chip) => {
        const on = Number(chip.dataset.minutes) === p.minutes;
        chip.setAttribute('aria-checked', on ? 'true' : 'false');
        chip.tabIndex = on ? 0 : -1;
      });
      $('xcCredits').textContent = p.credits_label;
      $('xcMinutes').textContent = p.minutes_label;
      const price = byHand() ? (p.transfer_price || p.price) : p.price;
      $('xcPrice').innerHTML = `<bdi>${esc(price)}</bdi>`;
      // How they pay
      $('xcGlyph').hidden = byHand();
      if (byHand()) {
        $('xcPayWith').innerHTML = fill(esc(t.pay_with), { method: `<b>${esc(manual(method)?.label || '')}</b>` });
        $('xcPayLast').textContent = '';
      } else {
        $('xcPayWith').textContent = t.pay_by_card;
        const last = method === 'saved' ? offer.card?.last4 : newCard?.last4;
        $('xcPayLast').textContent = last ? '•••• ' + last : '';
      }
      $('xcPayLabel').innerHTML = fill(esc(t.pay), { amount: `<bdi>${esc(price)}</bdi>` });
      $('xcFine').textContent = t.fine;
      note('xcBuyNote', byHand() && offer.pending ? t.manual_pending_exists : '', true);
      $('xcPay').disabled = byHand() && Boolean(offer.pending);
    }

    function buildChips() {
      const box = $('xcChips');
      box.style.setProperty('--xc-n', String(Math.min(5, offer.presets.length)));
      box.innerHTML = offer.presets.map((p) => `<button type="button" class="xc-chip" role="radio" data-minutes="${p.minutes}" aria-label="${esc(p.minutes_label)}">${p.minutes}</button>`).join('');
      box.querySelectorAll('.xc-chip').forEach((chip) => chip.addEventListener('click', () => { minutes = Number(chip.dataset.minutes); paintBuy(); }));
    }

    // Arrow keys move between the minutes, as in any group of radio buttons.
    $('xcChips').addEventListener('keydown', (event) => {
      const keys = isRtl ? { ArrowLeft: 1, ArrowRight: -1, ArrowDown: 1, ArrowUp: -1 } : { ArrowLeft: -1, ArrowRight: 1, ArrowDown: 1, ArrowUp: -1 };
      const step = keys[event.key];
      if (!step || !offer) return;
      event.preventDefault();
      const list = offer.presets.map((p) => p.minutes);
      minutes = list[(list.indexOf(minutes) + step + list.length) % list.length];
      paintBuy();
      $('xcChips').querySelector(`[data-minutes="${minutes}"]`)?.focus();
    });

    // ---------- payment method
    function paintMethods() {
      const rows = {
        saved: Boolean(offer.card_ready && offer.card),
        new: Boolean(offer.card_ready) && (offer.manual || []).length === 0,
        instapay: Boolean(manual('instapay')),
        vodafone_cash: Boolean(manual('vodafone_cash')),
        card: Boolean(offer.card_ready) && (offer.manual || []).length > 0,
      };
      dialog.querySelectorAll('[data-xc-method]').forEach((row) => { row.hidden = !rows[row.dataset.xcMethod]; });
      if (offer.card) {
        $('xcSavedLast').textContent = '•••• ' + offer.card.last4;
        $('xcSavedExp').textContent = offer.card.expires ? fill(t.expires, { date: offer.card.expires }) : '';
      }
      // For members who pay by hand, "Card" is the card form (their saved card, if any, comes first).
      const value = method === 'new' && rows.card ? 'card' : method;
      const radio = dialog.querySelector(`input[name="xcMethod"][value="${value}"]`);
      if (radio) radio.checked = true;
      openCardForm(method === 'new');
    }

    function chosenMethod() {
      const value = dialog.querySelector('input[name="xcMethod"]:checked')?.value || method;
      return value === 'card' ? 'new' : value;
    }

    dialog.querySelectorAll('input[name="xcMethod"]').forEach((radio) => radio.addEventListener('change', () => {
      note('xcMethodNote', '');
      openCardForm(chosenMethod() === 'new');
    }));

    function openCardForm(open) {
      const box = $('xcNewCard');
      const wasOpen = !box.hidden;
      box.hidden = !open;
      $('xcSecure').hidden = !open;
      if (open) {
        // The card form opens inside its own box ("Another card", or "Card").
        const option = dialog.querySelector('[data-xc-method="card"]:not([hidden])') || dialog.querySelector('[data-xc-method="new"]');
        if (option && box.parentElement !== option) option.appendChild(box);
        mountCard();
      }
      if (open !== wasOpen && !reduced()) {
        const h = box.scrollHeight;
        if (open) box.animate([{ height: '0px', opacity: 0 }, { height: h + 'px', opacity: 1 }], { duration: 260, easing: 'cubic-bezier(.2, .8, .2, 1)' });
      }
    }

    function loadStripe() {
      if (stripePromise) return stripePromise;
      stripePromise = new Promise((resolve) => {
        const make = () => { try { resolve(window.Stripe && offer?.stripe_key ? window.Stripe(offer.stripe_key) : null); } catch (_e) { resolve(null); } };
        if (window.Stripe) return make();
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onload = make;
        script.onerror = () => resolve(null);
        document.head.appendChild(script);
      }).then((s) => { stripe = s; if (!s) stripePromise = null; return s; });
      return stripePromise;
    }

    const isLight = () => document.documentElement.getAttribute('data-theme') === 'light';
    const cardStyle = () => isLight()
      ? { base: { color: '#0F172A', iconColor: '#5F6673', fontSize: '15px', '::placeholder': { color: '#8A90A0' } }, invalid: { color: '#c1244f', iconColor: '#c1244f' } }
      : { base: { color: '#FFFFFF', iconColor: '#A1A1AA', fontSize: '15px', '::placeholder': { color: '#7C7C88' } }, invalid: { color: '#ff7a9a', iconColor: '#ff7a9a' } };

    async function mountCard() {
      if (cardForm) return;
      const s = await loadStripe();
      if (!s || cardForm || typeof window.ListenCardFields !== 'function') {
        if (!s) note('xcMethodNote', t.card_unavailable);
        return;
      }
      cardForm = window.ListenCardFields(window.ListenCardElements(s), 'xc-card', cardStyle(), (st) => {
        cardComplete = st.complete;
        newCard = null;
        note('xcMethodNote', st.error ? stripeText(st.error, t.card_incomplete) : '');
      });
      new MutationObserver(() => cardForm.update({ style: cardStyle() })).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    // Stripe's own words read well on English pages; Arabic pages get ours.
    function stripeText(error, fallback) {
      const raw = String(error?.message || '').trim();
      if (raw) console.info('Card payment:', raw);
      return isArabicPage && !/[؀-ۿ]/.test(raw) ? fallback : (raw || fallback);
    }

    $('xcChange').addEventListener('click', () => { note('xcMethodNote', ''); paintMethods(); show('method', 1); });

    $('xcUse').addEventListener('click', async () => {
      const chosen = chosenMethod();
      if (chosen === 'new') {
        if (!cardForm || !stripe) { note('xcMethodNote', t.card_unavailable); return; }
        if (!cardComplete) { note('xcMethodNote', t.card_incomplete); return; }
        if (!newCard) {
          const button = $('xcUse');
          busy(button, true);
          try {
            const made = await stripe.createPaymentMethod({ type: 'card', card: cardForm.card });
            if (made.error) { note('xcMethodNote', stripeText(made.error, t.card_failed)); return; }
            newCard = { id: made.paymentMethod.id, last4: made.paymentMethod.card?.last4 || '' };
          } catch (_e) {
            note('xcMethodNote', t.card_failed);
            return;
          } finally {
            busy(button, false);
          }
        }
      }
      method = chosen;
      paintBuy();
      show('buy', -1);
    });

    // ---------- paying
    async function post(url, body) {
      const send = window.listenFetchWithFreshCsrf || ((u, o) => fetch(u, o));
      const isForm = body instanceof FormData;
      const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
      if (!isForm) headers['Content-Type'] = 'application/json';
      if (!window.listenFetchWithFreshCsrf) headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]')?.content || '';
      let response;
      try {
        response = await send(url, { method: 'POST', headers, body: isForm ? body : JSON.stringify(body) });
      } catch (_e) {
        return { status: 0, data: { ok: false, message: t.network_error } };
      }
      const data = await response.json().catch(() => null);
      return { status: response.status, data: data || { ok: false, message: t.card_failed } };
    }

    function firstError(data, fallback) {
      if (data?.message) return data.message;
      const errors = data?.errors ? Object.values(data.errors) : [];
      return errors.length && errors[0][0] ? errors[0][0] : fallback;
    }

    function added(data) {
      state.purchased = true;
      $('xcDoneTitle').textContent = fill(t.done_title, { credits: fmt(data.credits) });
      if (data.usage) window.dispatchEvent(new CustomEvent('listen:usage', { detail: { usage: data.usage } }));
      show('done', 1);
    }

    async function payByCard() {
      const p = preset();
      const pay = $('xcPay');
      const label = $('xcPayLabel').innerHTML;
      busy(pay, true, t.paying);
      note('xcBuyNote', '');
      const restore = () => { busy(pay, false); $('xcPayLabel').innerHTML = label; };
      const body = { minutes: p.minutes };
      if (method === 'new' && newCard) { body.payment_method = newCard.id; body.save_card = $('xcSaveCard').checked; }

      let reply = await post(urls.card, body);
      if (reply.status === 402 && reply.data.requires_action) {
        const s = stripe || await loadStripe();
        if (!s) { restore(); note('xcBuyNote', t.card_unavailable); return; }
        const check = await s.confirmCardPayment(reply.data.client_secret);
        if (check.error) { restore(); note('xcBuyNote', stripeText(check.error, t.card_failed)); return; }
        reply = await post(urls.confirm, { purchase: reply.data.purchase });
      }
      if (reply.data.ok) { restore(); added(reply.data); return; }
      if (reply.status === 202 && reply.data.processing) {
        // Added once the bank finishes: Pay stays off so it is never paid twice.
        busy(pay, false);
        pay.disabled = true;
        $('xcPayLabel').innerHTML = label;
        note('xcBuyNote', reply.data.message || t.card_processing, true);
        return;
      }
      restore();
      if (reply.data.reason) console.info('Card payment:', reply.data.reason);
      // A card that is no longer there: the member picks another.
      newCard = method === 'new' ? null : newCard;
      note('xcBuyNote', firstError(reply.data, t.card_failed));
    }

    function openTransfer() {
      const p = preset();
      const m = manual(method);
      $('xcTransferAmount').innerHTML = `<bdi>${esc(p.transfer_price || p.price)}</bdi>`;
      const link = $('xcTransferLink');
      link.hidden = !m?.payment_link;
      if (m?.payment_link) { link.href = m.payment_link; $('xcTransferLinkText').textContent = fill(t.open_link, { method: m.label }); }
      $('xcTransferQr').hidden = !m?.qr_image_url;
      if (m?.qr_image_url) { $('xcTransferQrImg').src = m.qr_image_url; $('xcTransferQrText').textContent = fill(t.scan_code, { method: m.label }); }
      $('xcTransferInstructions').hidden = !m?.instructions;
      $('xcTransferInstructions').textContent = m?.instructions || '';
      note('xcTransferNote', '');
      show('transfer', 1);
    }

    $('xcProof').addEventListener('change', (event) => {
      proof = event.target.files && event.target.files[0] ? event.target.files[0] : null;
      $('xcUpload').classList.toggle('is-ok', Boolean(proof));
      $('xcUploadText').textContent = proof ? proof.name : t.attach;
      if (proof) note('xcTransferNote', '');
    });

    $('xcSend').addEventListener('click', async () => {
      if (!proof) { note('xcTransferNote', t.choose_receipt); return; }
      const send = $('xcSend');
      busy(send, true, t.sending);
      const form = new FormData();
      form.append('minutes', String(preset().minutes));
      form.append('payment_method', method);
      form.append('proof_image', proof);
      const reply = await post(urls.transfer, form);
      busy(send, false, t.send);
      if (!reply.data.ok) { note('xcTransferNote', firstError(reply.data, t.card_failed)); return; }
      offer.pending = { minutes: preset().minutes, method };
      $('xcWaitLead').textContent = fill(t.transfer_sent, { credits: fmt(reply.data.credits) });
      window.dispatchEvent(new CustomEvent('listen:extra-credits-pending'));
      show('wait', 1);
    });

    $('xcPay').addEventListener('click', () => {
      if ($('xcPay').disabled) return;
      if (byHand()) { openTransfer(); return; }
      if (method === 'saved' && !offer.card) { method = 'new'; paintMethods(); show('method', 1); return; }
      if (method === 'new' && !newCard) { paintMethods(); show('method', 1); return; }
      payByCard();
    });

    $('xcGenerate').addEventListener('click', () => {
      const generate = state.onGenerate;
      close();
      if (typeof generate === 'function') setTimeout(generate, reduced() ? 0 : 220);
    });

    dialog.querySelectorAll('[data-xc-back]').forEach((b) => b.addEventListener('click', () => show('buy', -1)));
    dialog.querySelectorAll('[data-xc-close]').forEach((b) => b.addEventListener('click', () => close()));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });

    function close() {
      if (!dialog.open || dialog.classList.contains('is-closing')) return;
      const finish = () => {
        dialog.classList.remove('is-closing');
        dialog.close();
        const done = state.onClose;
        const purchased = state.purchased;
        state = { purchased: false, onClose: null, onGenerate: null };
        if (typeof done === 'function') done(purchased);
      };
      if (reduced()) { finish(); return; }
      dialog.classList.add('is-closing');
      setTimeout(finish, 190);
    }

    function reset() {
      Object.entries(views).forEach(([name, el]) => {
        el.getAnimations().forEach((a) => a.cancel());
        el.hidden = name !== 'buy';
        el.classList.toggle('is-on', name === 'buy');
        el.classList.remove('is-leaving');
      });
      current = 'buy';
      ['xcBuyNote', 'xcMethodNote', 'xcTransferNote'].forEach((id) => note(id, ''));
      busy($('xcPay'), false);
      proof = null;
      $('xcProof').value = '';
      $('xcUpload').classList.remove('is-ok');
      $('xcUploadText').textContent = t.attach;
    }

    /**
     * Opens the dialog when the member can buy extra credits now; resolves to false
     * (and opens nothing) otherwise, so the caller shows its own message.
     * options: { remaining, needed, onClose(purchased), onGenerate() }
     */
    async function open(options = {}) {
      if (dialog.open) return true;
      let data = null;
      try {
        const response = await fetch(urls.offer, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        data = response.ok ? await response.json() : null;
      } catch (_e) {
        data = null;
      }
      if (!data || data.status !== 'ok' || !Array.isArray(data.presets) || data.presets.length === 0) return false;

      const firstTime = !offer;
      offer = data;
      context = { remaining: Number(options.remaining ?? data.balance?.remaining ?? 0), needed: Number(options.needed || 0) };
      state = { purchased: false, onClose: options.onClose || null, onGenerate: options.onGenerate || null };
      if (firstTime || !offer.presets.some((p) => p.minutes === minutes)) minutes = Number(data.default_minutes) || offer.presets[0].minutes;
      // Members who pay by hand start on their own way of paying; everyone else on their card.
      const manualFirst = (offer.manual || [])[0]?.method;
      if (firstTime || (byHand() && !manual(method))) method = manualFirst || (offer.card ? 'saved' : 'new');
      if (method === 'saved' && !offer.card) method = 'new';
      if (!offer.card_ready && !byHand()) method = manualFirst || method;

      buildChips();
      reset();
      paintBuy();
      $('xcChange').hidden = !offer.card_ready && (offer.manual || []).length < 2;
      if (offer.card_ready) loadStripe();
      dialog.showModal();
      focusFirst(views.buy);
      return true;
    }

    window.ListenExtraCredits = { open, close, isOpen: () => dialog.open };

    // A link from the developer docs or an API error: /dashboard?extra_credits=1.
    const params = new URLSearchParams(window.location.search);
    if (params.get('extra_credits') === '1') {
      params.delete('extra_credits');
      const rest = params.toString();
      history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : '') + window.location.hash);
      window.addEventListener('load', () => open());
    }
    document.addEventListener('click', (event) => {
      const opener = event.target.closest('[data-extra-credits-open]');
      if (!opener) return;
      event.preventDefault();
      open();
    });
  })();
</script>
@endif
