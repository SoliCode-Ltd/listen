{{-- Talk to the agent: one orb in the middle that moves with its voice, and a halo that ripples
     while it speaks (the speakers' faces are only for choosing the voice). What was said shows
     below; each answer can be corrected. --}}
@props(['agent' => null, 'avatar' => null, 'freeTestLeft' => 0, 'perMinute' => 900, 'showCost' => true, 'female' => false, 'note' => null])
<div class="ag-talk" data-ag-talk>
  <div class="ag-talk-stage" data-vp-track data-ag-stage>
    <span class="ag-talk-face" data-ag-talk-avatar>
      <span class="ag-talk-halo" aria-hidden="true"></span>
      @if($callback ?? false)
        {{-- Waiting for a line: a ring round the orb fills over the minute the page waits. --}}
        <svg class="ag-talk-ring" viewBox="0 0 120 120" aria-hidden="true"><circle class="t" cx="60" cy="60" r="58"/><circle class="p" cx="60" cy="60" r="58" pathLength="100"/></svg>
      @endif
      <span class="ag-talk-orb" data-ag-talk-orb><x-orb name="listen" :colors="$agent?->widgets()->oldest('id')->first()?->style()['colors']" /></span>
    </span>
  </div>
  {{-- The button right under the orb; what the call is doing shows under the button, only during and after a call. --}}
  <div class="ag-talk-buttons">
    <button type="button" class="ag-btn ag-btn-primary ag-btn-lg ag-call-btn" data-ag-call>
      <svg class="ag-i-mic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>
      <svg class="ag-i-end" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 15.2c5-4.6 13-4.6 18 0l-2.3 2.7-3.7-1.4v-2.8a12.4 12.4 0 0 0-6 0v2.8l-3.7 1.4z"/></svg>
      <span data-ag-call-label>{{ __('agents.talk_start') }}</span>
    </button>
    <button type="button" class="ag-btn" data-ag-mute hidden>{{ __('agents.talk_mute') }}</button>
  </div>
  {{-- A word right under the button (the demo: the call uses the microphone). --}}
  @if($note)
    <p class="ag-talk-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>{{ $note }}</p>
  @endif
  <p class="ag-talk-state" data-ag-talk-state aria-live="polite"></p>
  {{-- The link page ($callback): every line busy, the caller's place in line and a way to leave a
       number instead; still busy, the number itself, then the thanks (agents.js talk()). --}}
  @if($callback ?? false)
    <span class="ag-talk-pos" data-ag-talk-pos hidden><i aria-hidden="true"></i><span data-ag-talk-pos-text aria-live="polite"></span></span>
    <p class="ag-talk-sub" data-ag-talk-sub hidden>{{ __('agents.lines.hold') }}</p>
    <button type="button" class="ag-btn ag-btn-primary ag-btn-lg ag-talk-leave" data-ag-talk-leave hidden>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2zM15 3h6v6M21 3l-6.5 6.5"/></svg>
      {{ __('agents.lines.leave') }}
    </button>
    <form class="ag-callback" data-ag-callback hidden novalidate>
      <h2>{{ __('agents.callback.title') }}</h2>
      <p>{{ __('agents.callback.text', ['business' => $agent->business_name]) }}</p>
      <label class="ag-callback-field"><span class="ag-label">{{ __('agents.callback.name') }}</span><input class="ag-input" name="name" autocomplete="name" maxlength="80" dir="auto"></label>
      <label class="ag-callback-field"><span class="ag-label">{{ __('agents.callback.phone') }}</span><input class="ag-input" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="24" dir="ltr" required></label>
      <p class="ag-callback-error" data-ag-callback-error role="alert" hidden></p>
      <button type="submit" class="ag-btn ag-btn-primary ag-btn-lg" data-ag-callback-send>{{ __('agents.callback.send') }}</button>
      <button type="button" class="ag-btn ag-btn-ghost" data-ag-callback-again>{{ __('agents.callback.again') }}</button>
    </form>
    <div class="ag-callback ag-callback-done" data-ag-callback-done role="status" tabindex="-1" hidden>
      <span class="ag-callback-tick" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      <h2 data-ag-callback-title dir="auto"></h2>
      <p data-ag-callback-text></p>
    </div>
  @endif
  @if($showCost)
    <p class="ag-free" data-ag-free>
      @if($freeTestLeft > 0)
        {{ trans_choice('agents.talk_free_left', $freeTestLeft, ['count' => $freeTestLeft]) }}
      @else
        {{ __('agents.per_minute', ['credits' => number_format($perMinute)]) }}
      @endif
    </p>
  @endif
  <div class="ag-transcript" data-ag-transcript aria-live="polite"></div>
</div>
