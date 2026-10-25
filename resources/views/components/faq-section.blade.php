{{--
  Frequently asked questions: the title and a line beside the questions, each
  question on a hairline with its answer opening under it (pricing, home). The
  page passes the same items to components.page-head, so its FAQ structured data
  holds exactly the questions shown here.
--}}
@props(['id' => 'faq', 'title' => '', 'subtitle' => '', 'items' => [], 'contact' => true, 'more' => null])
<section class="faq-section" aria-labelledby="{{ $id }}Title">
  <div class="faq-intro">
    <h2 class="faq-heading" id="{{ $id }}Title">{{ $title }}</h2>
    @if($subtitle !== '')
      <p>{{ $subtitle }}</p>
    @endif
    @if($more)
      <a class="faq-contact" href="{{ $more['href'] }}">{{ $more['label'] }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    @endif
    @if($contact)
      <a class="faq-contact" href="{{ route('contact') }}">{{ __('ui.pricing.faq_contact') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    @endif
  </div>
  <div class="faq-list">
    @foreach($items as $item)
      <details class="faq-item" @if($loop->first) open @endif>
        <summary>
          <span>{{ $item['question'] }}</span>
          <svg class="faq-toggle" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        </summary>
        <p>{{ $item['answer'] }}</p>
      </details>
    @endforeach
  </div>
</section>

@once
<style>
  .faq-section {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.7fr);
    gap: 3rem;
    align-items: start;
    padding: 6.5rem 0 1rem;
  }
  .faq-intro { position: sticky; top: 6rem; }
  .faq-heading {
    margin: 0 0 .55rem;
    font-size: clamp(1.7rem, 3.2vw, 2.4rem);
    font-weight: 600;
    letter-spacing: -.03em;
    text-align: start;
  }
  html[dir="rtl"] .faq-heading { letter-spacing: 0; }
  .faq-intro p { margin: 0 0 1rem; color: var(--muted); font-size: 1rem; line-height: 1.6; }
  .faq-contact {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 44px;
    color: var(--accent-text);
    font-weight: 700;
    text-decoration: none;
  }
  .faq-contact:hover { text-decoration: underline; }
  .faq-contact + .faq-contact { display: flex; }
  .faq-contact svg { width: 1rem; height: 1rem; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
  html[dir="rtl"] .faq-contact svg { transform: scaleX(-1); }
  /* Questions on hairlines: no boxes, the answer opens under its question. */
  .faq-list { border-top: 1px solid var(--line); }
  .faq-item { border-bottom: 1px solid var(--line); }
  .faq-item summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
    min-height: 44px;
    padding: 1.35rem 0;
    font-size: 1.05rem;
    font-weight: 600;
    cursor: pointer;
    list-style: none;
  }
  .faq-item summary::-webkit-details-marker { display: none; }
  .faq-item summary:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; border-radius: .5rem; }
  .faq-toggle {
    flex: none;
    width: 2rem;
    height: 2rem;
    padding: .5rem;
    border-radius: 50%;
    border: 1px solid var(--line-strong);
    fill: none;
    stroke: var(--text);
    stroke-width: 2;
    stroke-linecap: round;
    transition: transform .2s ease, background-color .2s ease, border-color .2s ease;
  }
  .faq-item summary:hover .faq-toggle { border-color: color-mix(in oklab, var(--text) 35%, var(--line)); }
  .faq-item[open] .faq-toggle { transform: rotate(45deg); background: color-mix(in oklab, var(--text) 5%, transparent); }
  .faq-item p { margin: 0; padding: 0 0 1.5rem; padding-inline-end: 3.5rem; color: var(--muted); font-size: .97rem; line-height: 1.7; max-width: 68ch; }
  @media (max-width: 860px) {
    .faq-section { grid-template-columns: 1fr; gap: 1.25rem; }
    .faq-intro { position: static; text-align: center; }
    .faq-heading { text-align: center; }
  }
  @media (max-width: 600px) {
    .faq-section { padding-top: 4rem; }
    .faq-item p { padding-inline-end: 0; }
  }
</style>
@endonce
