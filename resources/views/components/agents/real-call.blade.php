{{-- A call on its own stage: the caller's words, the agent's words lit as they are said, the orb
     following the voice (js/listen-orbs.js reads the stage's loudness), and what the business is left
     with at the end. Before it plays, the first lines show.

     On the Arabic pages it is a real call with Listen's own agent, recorded live (Saudi and
     Egyptian, the agent's own voice). The English pages show English voices only, so there it plays
     an English call as it goes, in words (American and British): no recording is passed off as one. --}}
@props(['size' => 'home', 'visual' => 'orb'])
@php
  $english = app()->getLocale() === 'en';
  $data = [];
  if (! $english) {
    $calls = __('agents_landing.real_calls');
    $order = array_values(array_filter((array) __('agents_landing.real_order'), fn ($k) => isset($calls[$k])));
    foreach ($order as $key) {
      $c = $calls[$key];
      $data[$key] = [
        'label' => $c['label'],
        'agent' => $c['agent'],
        'dialect' => $c['dialect'],
        'avatar' => asset('images/speakers/'.$c['avatar'].'.webp'),
        'dir' => 'rtl', 'lang' => 'ar',
        'turns' => array_map(fn ($t) => [
          'role' => $t[0],
          'text' => $t[1],
          'gloss' => '',
          'audio' => ! empty($t[3]) ? asset('audio/agents/calls/'.$t[3].'.mp3').'?v='.@filemtime(public_path('audio/agents/calls/'.$t[3].'.mp3')) : null,
        ], $c['turns']),
        'result' => $c['result'],
      ];
    }
    $labels = ['play' => __('agents_landing.real_play'), 'again' => __('agents_landing.real_again'), 'note' => __('agents_landing.real_note')];
  } else {
    $scenes = __('agents_landing.scenes');
    $order = array_values(array_filter((array) __('agents_landing.scene_order_home'), fn ($k) => isset($scenes[$k])));
    foreach ($order as $key) {
      $c = $scenes[$key];
      $data[$key] = [
        'label' => $c['label'],
        'agent' => $c['agent'],
        'dialect' => $c['business'],
        'avatar' => asset('images/speakers/'.$c['avatar'].'.webp').'?v=1',
        'dir' => 'ltr', 'lang' => 'en',
        'turns' => array_map(fn ($l) => ['role' => $l[0], 'text' => $l[1], 'gloss' => '', 'audio' => null], $c['lines']),
        'result' => [$c['chip'], ''],
      ];
    }
    $labels = ['play' => __('agents_landing.scene_play'), 'again' => __('agents_landing.scene_again'), 'note' => __('agents_landing.scene_note')];
  }
  $first = $data[$order[0]];
  // At rest, the first three lines (the third fades out at the card's edge: the call goes on).
  $rest = 3;
@endphp
{{-- visual "face": the agent's photo with rings that follow the voice, for a page that already has its one orb. --}}
<div {{ $attributes->merge(['class' => 'agl-real is-'.$size.($english ? ' is-scene' : '').($visual === 'face' ? ' has-face' : '')]) }} data-agl-real data-vs-cast>
  <div class="agl-real-top">
    <span class="agl-real-face" aria-hidden="true"><img src="{{ $first['avatar'] }}" alt="" width="46" height="46" data-agl-real-face></span>
    <div class="agl-real-who">
      <b data-agl-real-name>{{ $first['agent'] }}</b>
      <small data-agl-real-dialect>{{ $first['dialect'] }}</small>
    </div>
    <div class="agl-real-switch" role="radiogroup" aria-label="{{ __('agents_landing.real_switch') }}">
      @foreach($order as $key)
        <button type="button" role="radio" aria-checked="{{ $loop->first ? 'true' : 'false' }}" data-agl-real-pick="{{ $key }}">{{ $data[$key]['label'] }}</button>
      @endforeach
    </div>
  </div>

  <div class="agl-real-stage">
    @if($visual === 'face')
      <span class="agl-real-avatar" aria-hidden="true"><i></i><i></i><img src="{{ $first['avatar'] }}" alt="" width="170" height="170" data-agl-real-photo></span>
    @else
      <x-orb name="listen" class="agl-real-orb" />
    @endif
    <button type="button" class="agl-real-play" data-agl-real-play aria-pressed="false"
            data-label-play="{{ $labels['play'] }}" data-label-pause="{{ __('agents_landing.real_pause') }}" data-label-again="{{ $labels['again'] }}">
      <span class="agl-real-play-icon" aria-hidden="true">
        <svg class="is-play" viewBox="0 0 24 24"><path d="M8 5.5v13l10.5-6.5z"/></svg>
        <svg class="is-pause" viewBox="0 0 24 24"><path d="M7.5 5.5h3v13h-3zM13.5 5.5h3v13h-3z"/></svg>
      </span>
      <span data-agl-real-label>{{ $labels['play'] }}</span>
    </button>
    <span class="agl-real-progress" aria-hidden="true"><i data-agl-real-bar></i></span>
  </div>

  <ol class="agl-real-lines" data-agl-real-lines>
    @foreach(array_slice($first['turns'], 0, $rest) as $turn)
      <li class="agl-real-line is-{{ $turn['role'] }} is-shown">
        <p dir="{{ $first['dir'] }}" lang="{{ $first['lang'] }}">{{ $turn['text'] }}</p>
      </li>
    @endforeach
  </ol>

  <p class="agl-real-result" data-agl-real-result aria-live="polite">
    <span class="agl-real-result-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
    <b data-agl-real-result-title>{{ $first['result'][0] }}</b>
    <span data-agl-real-result-text>{{ $first['result'][1] }}</span>
  </p>
  <p class="agl-real-note">{{ $labels['note'] }}</p>
  <script type="application/json" data-agl-real-data>{!! json_encode(['order' => $order, 'calls' => $data, 'rest' => $rest], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
</div>
