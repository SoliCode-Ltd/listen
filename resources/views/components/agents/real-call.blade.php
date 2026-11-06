{{-- A call on its own stage, read like a messenger: the caller on one side, the agent on the other,
     the agent's words lit as they are said, the orb following the voice (js/listen-orbs.js reads the
     stage's loudness), and what the business is left with at the end, as a saved contact.

     Real calls, recorded live with sample businesses (php artisan agents:live-setup --sample=...):
     the agent's own voice from the voice library, women and men. Each page has its own language's
     calls only: Saudi, Emirati, Egyptian and Lebanese on the Arabic pages; American, British and
     Irish on the English pages. --}}
@props(['size' => 'home', 'visual' => 'orb'])
@php
  $english = app()->getLocale() === 'en';
  $calls = (array) __('agents_landing.real_calls');
  $order = array_values(array_filter((array) __('agents_landing.real_order'), fn ($k) => isset($calls[$k])));
  $data = [];
  // Each line as a page shows it: numbers read back digit by digit written as numbers, and on the
  // Arabic page every spaced number kept in its own order (App\Support\Agents\SpokenNumbers).
  $show = fn (string $text) => \App\Support\Agents\SpokenNumbers::forDisplay($text, ! $english);
  foreach ($order as $key) {
    $c = $calls[$key];
    [$title, $name, $phone, $note] = array_pad((array) $c['result'], 4, '');
    $data[$key] = [
      'flag' => is_file(public_path('images/flags/'.$key.'.svg')) ? asset('images/flags/'.$key.'.svg') : null,
      'label' => $c['label'],
      'agent' => $c['agent'],
      'business' => $c['business'] ?? '',
      'dialect' => $c['dialect'],
      'avatar' => \App\Support\SpeakerAvatar::bySlug($c['avatar']),
      'dir' => $english ? 'ltr' : 'rtl', 'lang' => $english ? 'en' : 'ar',
      'turns' => array_map(fn ($t) => [
        'role' => $t[0],
        'text' => $show($t[1]),
        'audio' => ! empty($t[3]) ? asset('audio/agents/calls/'.$t[3].'.mp3').'?v='.@filemtime(public_path('audio/agents/calls/'.$t[3].'.mp3')) : null,
      ], $c['turns']),
      // What the business is left with: a saved contact (the phone always left to right).
      'result' => ['title' => $title, 'name' => $name, 'phone' => $phone, 'note' => $show($note)],
    ];
  }
  $labels = ['play' => __('agents_landing.real_play'), 'again' => __('agents_landing.real_again'), 'note' => __('agents_landing.real_note')];
  $svg = [
    'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    'person' => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/>',
    'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
    'note' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
  ];
  $first = $data[$order[0]];
  // At rest, the first three lines (the third fades out at the card's edge: the call goes on).
  $rest = 3;
@endphp
{{-- visual "face": the agent's photo with rings that follow the voice, for a page that already has its one orb. --}}
<div {{ $attributes->merge(['class' => 'agl-real is-'.$size.($visual === 'face' ? ' has-face' : '')]) }} data-agl-real data-vs-cast>
  {{-- Which call: one tab each, a row of its own so every name has room. --}}
  <div class="agl-real-switch" role="radiogroup" aria-label="{{ __('agents_landing.real_switch') }}">
    @foreach($order as $key)
      <button type="button" role="radio" aria-checked="{{ $loop->first ? 'true' : 'false' }}" data-agl-real-pick="{{ $key }}">{{ $data[$key]['label'] }}</button>
    @endforeach
  </div>

  <div class="agl-real-top">
    <span class="agl-real-face" aria-hidden="true"><img src="{{ $first['avatar'] }}" alt="" width="48" height="48" data-agl-real-face></span>
    <div class="agl-real-who">
      <b data-agl-real-name>{{ $first['agent'] }}</b>
      <span data-agl-real-biz>{{ $first['business'] }}</span>
    </div>
    <span class="agl-real-dialect"><img src="{{ $first['flag'] }}" alt="" width="18" height="18" data-agl-real-flag @if(! $first['flag']) hidden @endif><span data-agl-real-dialect>{{ $first['dialect'] }}</span></span>
  </div>

  {{-- The voice, and on it the one control: a white play button (pause while it plays, play again at the end). --}}
  <div class="agl-real-stage">
    <div class="agl-real-voice">
      @if($visual === 'face')
        <span class="agl-real-avatar" aria-hidden="true"><i></i><i></i><img src="{{ $first['avatar'] }}" alt="" width="170" height="170" data-agl-real-photo></span>
      @else
        <x-orb name="listen" class="agl-real-orb" />
      @endif
      <button type="button" class="agl-real-play" data-agl-real-play aria-pressed="false" data-state="play"
              data-label-play="{{ $labels['play'] }}" data-label-pause="{{ __('agents_landing.real_pause') }}" data-label-again="{{ $labels['again'] }}">
        <svg class="is-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>
        <svg class="is-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 5.5h3v13h-3zM13.5 5.5h3v13h-3z"/></svg>
        <svg class="is-again" viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3"/><path d="M4.5 4.5v4h4"/></svg>
        <span class="agl-sr" data-agl-real-label>{{ $labels['play'] }}</span>
      </button>
    </div>
  </div>

  {{-- The call, like a messenger: the agent's lines on the start side, the caller's on the other. --}}
  <ol class="agl-real-lines" data-agl-real-lines>
    @foreach(array_slice($first['turns'], 0, $rest) as $turn)
      <li class="agl-real-line is-{{ $turn['role'] }} is-shown">
        <p dir="{{ $first['dir'] }}" lang="{{ $first['lang'] }}">{{ $turn['text'] }}</p>
      </li>
    @endforeach
  </ol>

  {{-- What the business is left with: a new contact in its inbox, as the agent saved it. --}}
  <div class="agl-real-result" data-agl-real-result aria-live="polite">
    <div class="agl-real-result-head">
      <span class="agl-real-result-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $svg['check'] !!}</svg></span>
      <b data-agl-real-result-title>{{ $first['result']['title'] }}</b>
      <span class="agl-real-result-new">{{ __('agents_landing.real_new') }}</span>
    </div>
    <dl class="agl-real-result-rows">
      <div class="agl-real-result-row"><dt><svg viewBox="0 0 24 24" aria-hidden="true">{!! $svg['person'] !!}</svg><span class="agl-sr">{{ __('agents_landing.real_name_label') }}</span></dt><dd data-agl-real-result-name>{{ $first['result']['name'] }}</dd></div>
      <div class="agl-real-result-row"><dt><svg viewBox="0 0 24 24" aria-hidden="true">{!! $svg['phone'] !!}</svg><span class="agl-sr">{{ __('agents_landing.real_phone_label') }}</span></dt><dd><bdi dir="ltr" data-agl-real-result-phone>{{ $first['result']['phone'] }}</bdi></dd></div>
      <div class="agl-real-result-row is-wide"><dt><svg viewBox="0 0 24 24" aria-hidden="true">{!! $svg['note'] !!}</svg><span class="agl-sr">{{ __('agents_landing.real_note_label') }}</span></dt><dd data-agl-real-result-note>{{ $first['result']['note'] }}</dd></div>
    </dl>
  </div>
  <p class="agl-real-note">{{ $labels['note'] }}</p>
  <script type="application/json" data-agl-real-data>{!! json_encode(['order' => $order, 'calls' => $data, 'rest' => $rest], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
</div>
