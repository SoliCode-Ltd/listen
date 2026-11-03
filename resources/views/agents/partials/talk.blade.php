{{-- Talk to the agent: its face in the middle, a halo that ripples while it speaks, and the brand's
     voice waves either side, following its voice (and the caller's, softer). What was said shows
     below; each answer can be corrected. --}}
@props(['agent' => null, 'avatar' => null, 'freeTestLeft' => 0, 'perMinute' => 900, 'showCost' => true])
<div class="ag-talk" data-ag-talk>
  <div class="ag-talk-stage" data-vp-track data-ag-stage>
    <span class="ag-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
    <span class="ag-talk-face" data-ag-talk-avatar>
      <span class="ag-talk-halo" aria-hidden="true"></span>
      <img src="{{ $avatar }}" alt="" width="120" height="120" data-ag-talk-avatar-img @if(! $avatar) hidden @endif>
      <span class="ag-talk-orb" data-ag-talk-orb @if($avatar) hidden @endif><x-orb :name="$agent?->orbName() ?? 'listen'" /></span>
    </span>
    <span class="ag-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
  </div>
  <p class="ag-talk-state" data-ag-talk-state aria-live="polite">{{ __('agents.talk_states.idle') }}</p>
  <div class="ag-talk-buttons">
    <button type="button" class="ag-btn ag-btn-primary ag-btn-lg ag-call-btn" data-ag-call>
      <svg class="ag-i-mic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>
      <svg class="ag-i-end" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 15.2c5-4.6 13-4.6 18 0l-2.3 2.7-3.7-1.4v-2.8a12.4 12.4 0 0 0-6 0v2.8l-3.7 1.4z"/></svg>
      <span data-ag-call-label>{{ __('agents.talk_start') }}</span>
    </button>
    <button type="button" class="ag-btn" data-ag-mute hidden>{{ __('agents.talk_mute') }}</button>
  </div>
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
