{{-- How every marketing page ends: one deep-ink panel with the Listen orb alive in the middle,
     a title, a line and one or two buttons. The page loads components.orb-script.
     primary / secondary: ['href' => ..., 'label' => ...]. --}}
@props(['title', 'body' => null, 'primary', 'secondary' => null])
<section {{ $attributes->merge(['class' => 'closing-panel reveal']) }} data-orb-host>
  <x-orb name="listen" class="closing-orb is-live" />
  <h2>{{ $title }}</h2>
  @if($body)
    <p>{{ $body }}</p>
  @endif
  <div class="closing-actions">
    <a class="closing-btn is-solid" href="{{ $primary['href'] }}">{{ $primary['label'] }}</a>
    @if($secondary)
      <a class="closing-btn is-ghost" href="{{ $secondary['href'] }}">{{ $secondary['label'] }}</a>
    @endif
  </div>
</section>
