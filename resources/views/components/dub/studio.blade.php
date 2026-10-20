{{--
  The dubbing studio: the voice studio's own dialect, style and emotion
  pickers and voice library around a video instead of a script. The same
  studio sits on the "Dub my video" page, in the demo and on the dashboard
  (beside the text studio, js/dub-studio.js switches between them).

  $dub: App\Support\VideoDubbing::studio(), which says what this visitor may
  do now (state), what they have left (usage) and the studio's choices.
  The left card goes from the drop zone to the chosen video, its upload and
  dubbing progress, and the finished video; what may not be done yet shows
  in its place, with the pickers and voices still there to try.
--}}
@props(['dub', 'hidden' => false, 'compactMeter' => false])
@php
  $dubAr = app()->getLocale() === 'ar';
  $dubConfig = [
      'state' => $dub['state'],
      // On the dashboard the account above says what is left: the studio says only what the chosen video uses.
      'compactMeter' => $compactMeter,
      'free' => $dub['free'],
      'email' => $dub['email'],
      'usage' => $dub['usage'],
      'upgrade' => $dub['upgrade'],
      'minSeconds' => $dub['min_seconds'],
      'maxSeconds' => $dub['max_seconds'],
      'maxMb' => $dub['max_mb'],
      'creditsPerSecond' => $dub['credits_per_second'],
      // Arabic (its dialects) and English (its accents): each with its voices.
      'languages' => collect($dub['languages'])->map(fn (array $l): array => [
          'voices' => collect($l['voices'])->map(fn (array $v): array => [
              'key' => $v['key'], 'label' => $dubAr && filled($v['label_ar'] ?? null) ? $v['label_ar'] : $v['label'], 'avatar' => $v['avatar'] ?? null, 'gender' => $v['gender'] ?? '',
          ])->values()->all(),
          'defaultVoice' => $l['default_voice'],
          'dialects' => $l['dialects'],
          'defaultDialect' => $l['default_dialect'],
      ])->all(),
      'language' => $dub['language'],
      'dubs' => $dub['dubs'],
      'urls' => $dub['urls'],
      'i18n' => __('ui.dub.js'),
  ];
@endphp
<section class="dub-studio" data-dub-studio data-studio-pane="dub" @if($hidden) hidden @endif aria-label="{{ __('ui.dub.studio_label') }}">
  <form class="vs-layout dub-layout" data-dub-form novalidate>
    <div class="vs-main">
      <div class="vs-editor-card dub-card">
        <x-voice-studio.setbar prefix="dub_" :styles="$dub['styles']" :emotions="$dub['emotions']"
          :style-default="__('ui.dub.match_video')" :emotion-default="__('ui.dub.match_video')" :default-desc="__('ui.dub.match_video_desc')">
          {{-- As in the text studio: Arabic (its dialects below) or English (its accents). --}}
          <div>
            <label class="vs-field-label" for="dub_target_language">{{ __('ui.dub.dub_into') }}</label>
            <select id="dub_target_language" name="language" data-dub-language>
              <option value="ar" @selected($dub['language'] === 'ar')>{{ __('ui.dashboard.arabic') }}</option>
              <option value="en" @selected($dub['language'] === 'en')>{{ __('ui.dashboard.english') }}</option>
            </select>
          </div>
        </x-voice-studio.setbar>

        <div class="dub-stage" data-dub-stage>
          {{-- Nothing chosen yet --}}
          <div class="dub-drop" data-dub-view="drop" role="button" tabindex="0" aria-label="{{ __('ui.dub.drop_title') }}">
            <input type="file" name="video" data-dub-file tabindex="-1" aria-hidden="true" accept="video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-m4v,video/3gpp,.mp4,.mov,.webm,.mkv,.m4v,.3gp">
            <span class="dub-drop-art" aria-hidden="true">
              <svg viewBox="0 0 48 48"><rect x="6" y="11" width="28" height="26" rx="5"/><path d="M34 21l8-5v16l-8-5"/><path class="dub-drop-wave" d="M13 24h2M18 20v8M22 22v4M26 19v10"/></svg>
            </span>
            <span class="dub-drop-title">{{ __('ui.dub.drop_title') }}</span>
            <span class="dub-drop-or">{!! __('ui.dub.drop_or', ['choose' => '<u>'.e(__('ui.dub.drop_choose')).'</u>']) !!}</span>
            <span class="dub-drop-limits" data-dub-limits>
              <span>{{ __('ui.dub.drop_formats') }}</span>
              <span>{{ __('ui.dub.drop_seconds', ['seconds' => $dub['max_seconds']]) }}</span>
              <span>{{ __('ui.dub.drop_size', ['mb' => $dub['max_mb']]) }}</span>
            </span>
          </div>

          {{-- A video chosen, ready to send --}}
          <div class="dub-chosen" data-dub-view="chosen" hidden>
            <video data-dub-preview controls playsinline preload="metadata"></video>
            <div class="dub-file-row">
              <span class="dub-file-name" data-dub-file-name></span>
              <span class="dub-file-length" data-dub-file-length dir="ltr"></span>
              <button type="button" class="dub-link" data-dub-change>{{ __('ui.dub.change_video') }}</button>
            </div>
          </div>

          {{-- Sending it, then dubbing it --}}
          <div class="dub-working" data-dub-view="working" hidden aria-live="polite">
            <div class="dub-working-head">
              <span class="dub-spinner" aria-hidden="true"></span>
              <div>
                <strong data-dub-working-title></strong>
                <small data-dub-working-note></small>
              </div>
              <span class="dub-percent" data-dub-percent dir="ltr">0%</span>
            </div>
            <div class="dub-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" data-dub-bar><span data-dub-bar-fill></span></div>
            <ol class="dub-steps">
              <li data-dub-step="upload">{{ __('ui.dub.js.step_upload') }}</li>
              <li data-dub-step="listening">{{ __('ui.dub.js.step_listening') }}</li>
              <li data-dub-step="voicing">{{ __('ui.dub.js.step_voicing') }}</li>
              <li data-dub-step="mixing">{{ __('ui.dub.js.step_mixing') }}</li>
            </ol>
          </div>

          {{-- Finished --}}
          <div class="dub-done" data-dub-view="done" hidden>
            <video data-dub-result controls playsinline preload="metadata"></video>
            <div class="dub-done-row">
              <div class="dub-done-text">
                <strong data-dub-done-title>{{ __('ui.dub.js.done_title') }}</strong>
                <small data-dub-done-note></small>
              </div>
              <div class="dub-done-actions">
                <a class="btn btn-primary" data-dub-download href="#" hidden></a>
                <button type="button" class="btn btn-ghost" data-dub-again>{{ __('ui.dub.js.again') }}</button>
              </div>
            </div>
          </div>

          {{-- What may not be done yet, and how to get there --}}
          <div class="dub-lock" data-dub-view="lock" hidden>
            <span class="dub-lock-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10.5" width="14" height="10" rx="2.2"/><path d="M8.5 10.5V7.8a3.5 3.5 0 0 1 7 0v2.7"/></svg></span>
            <strong data-dub-lock-title></strong>
            <p data-dub-lock-text></p>
            <div class="dub-lock-actions" data-dub-lock-actions></div>
          </div>
        </div>

        <label class="dub-toggle">
          <input type="checkbox" data-dub-keep checked>
          <span class="dub-switch" aria-hidden="true"></span>
          <span class="dub-toggle-text">
            <strong>{{ __('ui.dub.keep_title') }}</strong>
            <small>{{ __('ui.dub.keep_hint') }}</small>
          </span>
        </label>

        <div class="dub-meter" data-dub-meter></div>
        <p class="dub-error" data-dub-error role="alert" hidden></p>

        <div class="vs-editor-foot dub-foot">
          <button type="button" class="dub-voice-chip" data-dub-voice-chip>
            <span class="dub-voice-face" data-dub-voice-face aria-hidden="true"></span>
            <span class="dub-voice-text">
              <small>{{ __('ui.dub.voice_label') }}</small>
              <strong data-dub-voice-name></strong>
            </span>
          </button>
          <div class="dub-go-row">
            <button class="dub-go" type="submit" data-dub-submit disabled>
              <span>
                <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <span data-dub-submit-text>{{ $dub['free'] ? __('ui.dub.js.submit_free') : __('ui.dub.js.submit') }}</span>
              </span>
            </button>
          </div>
        </div>
      </div>
    </div>

    {{-- The studio's voice library: every premium Arabic voice with its avatar and a sample to hear. --}}
    <aside class="vs-side">
      <x-voice-studio.panel prefix="dub_" :show-tags="false" />
    </aside>
  </form>

  <section class="dub-history" data-dub-history hidden>
    <header>
      <h2>{{ __('ui.dub.your_dubs') }}</h2>
      <p>{{ __('ui.dub.kept_for', ['hours' => $dub['keep_hours']]) }}</p>
    </header>
    <div class="dub-history-grid" data-dub-items></div>
  </section>

  <script type="application/json" data-dub-config>@json($dubConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)</script>
</section>
