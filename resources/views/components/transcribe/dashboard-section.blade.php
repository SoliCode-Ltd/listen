{{--
  The dashboard's "Speech to text" section, built from the
  studio's own parts (the dubbing section's account, rule box, drop zone,
  progress, meter, go button and ledger: css/dub-studio.css) so it reads as
  one more studio tool; css/transcribe.css adds only what those have no
  part for (the link and recorder views, the transcript reader, the row menu).

  At the top: this billing month's minutes (left, used, when they start
  again, the longest file) and how they are counted. The box: drop or upload
  a file, give a link, or record, choose the language, and see what the file
  will use before it starts. Below: every transcript with the minutes it
  used, searched, opened in the reader (click a sentence to play it, correct
  a paragraph, name the speakers, translate, download, send to the studio).

  Driven by js/transcribe.js. $account: TranscriptionPresenter::account().
--}}
@props(['account'])
@php
  $trAr = app()->getLocale() === 'ar';
  // Every language, by its name in the page's language; found by either name or its code.
  $trLanguageList = function (array $codes) use ($trAr): array {
      $rows = array_map(function (string $code) use ($trAr): array {
          $names = \App\Support\Transcription\TranscriptionSettings::LANGUAGES[$code] ?? [$code, $code];

          return ['value' => $code, 'label' => $trAr ? $names[1] : $names[0], 'search' => $names[0].' '.$names[1].' '.$code,
              'flag' => \App\Support\Transcription\TranscriptionSettings::flagUrl($code),
              'badge' => \App\Support\Transcription\TranscriptionSettings::BADGES[$code] ?? null];
      }, $codes);
      $collator = class_exists(\Collator::class) ? new \Collator($trAr ? 'ar' : 'en') : null;
      usort($rows, fn (array $a, array $b): int => $collator ? $collator->compare($a['label'], $b['label']) : strcmp($a['label'], $b['label']));

      return $rows;
  };
  $trLanguages = $trLanguageList(\App\Support\Transcription\TranscriptionSettings::languages());
  $trTargets = $trLanguageList(\App\Services\Transcription\TranscriptEditor::targets());
  $trMaxMb = \App\Support\Transcription\TranscriptionSettings::maxUploadMb();
  $trLongest = \App\Support\Transcription\Minutes::format((int) $account['max_file_seconds'], null, false);
  $trLeftShare = $account['allowance_seconds'] > 0 ? max(0, min(100, (int) round($account['left_seconds'] / $account['allowance_seconds'] * 100))) : 0;
  $trConfig = [
      'account' => $account,
      'languages' => $trLanguages,
      'targets' => $trTargets,
      'maxMb' => $trMaxMb,
      'keepDays' => \App\Support\Transcription\TranscriptionSettings::keepAudioDays(),
      'extensions' => \App\Http\Controllers\TranscribeController::EXTENSIONS,
      'rtl' => \App\Support\Transcription\TranscriptionSettings::RTL,
      'urls' => [
          'check' => route('transcribe.check'),
          'upload' => route('transcribe.upload.start'),
          'store' => route('transcribe.store'),
          'index' => route('transcribe.index'),
          'pricing' => route('pricing'),
          'csrf' => route('dashboard.csrf-token'),
      ],
      'i18n' => __('transcribe.js'),
      // The list's column names, said beside each value when the list shows as cards on a phone.
      'columns' => ['date' => __('transcribe.page.col_date'), 'language' => __('transcribe.page.col_language'), 'length' => __('transcribe.page.col_length'),
          'status' => __('transcribe.page.col_status')],
      'csrf' => csrf_token(),
  ];
@endphp
<section id="transcribeSection" class="dashboard-panel" data-dashboard-section>
  <article class="page-card reveal dub-section tr-section" data-tr-root>
    <h1><span class="grad-text">{{ __('transcribe.page.title') }}</span></h1>
    <p class="dub-section-lead">{{ __('transcribe.page.lead') }}</p>

    @if($account['included'])
      <div class="dub-account" data-tr-account>
        <div class="dub-stat">
          <div class="dub-stat-head">
            <span class="dub-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/></svg></span>
            <span class="dub-stat-label">{{ __('transcribe.page.stat_left') }}</span>
          </div>
          <div class="dub-stat-value" data-tr-left>{{ $account['left'] }}</div>
          <div class="dub-stat-note">{{ __('transcribe.page.stat_left_of', ['total' => $account['allowance']]) }}</div>
          <div class="dub-stat-bar" aria-hidden="true"><i data-tr-left-bar style="width: {{ $trLeftShare }}%"></i></div>
        </div>
        <div class="dub-stat">
          <div class="dub-stat-head">
            <span class="dub-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
            <span class="dub-stat-label">{{ __('transcribe.page.stat_used') }}</span>
          </div>
          <div class="dub-stat-value" data-tr-used>{{ $account['used'] }}</div>
          <div class="dub-stat-note">{{ __('transcribe.page.stat_used_note') }}</div>
        </div>
        <div class="dub-stat">
          <div class="dub-stat-head">
            <span class="dub-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/></svg></span>
            <span class="dub-stat-label">{{ __('transcribe.page.stat_resets') }}</span>
          </div>
          <div class="dub-stat-value">{{ $account['resets'] }}</div>
          <div class="dub-stat-note">{{ __('transcribe.page.stat_resets_note') }}</div>
        </div>
        <div class="dub-stat">
          <div class="dub-stat-head">
            <span class="dub-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 12h16M4 12l3-3M4 12l3 3M20 12l-3-3M20 12l-3 3"/></svg></span>
            <span class="dub-stat-label">{{ __('transcribe.page.stat_longest') }}</span>
          </div>
          <div class="dub-stat-value">{{ $trLongest }}</div>
          <div class="dub-stat-note">{{ __('transcribe.page.stat_longest_note') }}</div>
        </div>
      </div>
    @else
      <div class="dub-rule">
        <strong class="dub-rule-title">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
          {{ __('transcribe.page.upgrade_title') }}
        </strong>
        <p>{{ $account['free_try'] ? __('transcribe.page.free_text', ['length' => \App\Support\Transcription\Minutes::format((int) $account['free_try_seconds'])]) : __('transcribe.page.used_text') }}</p>
        @if($account['upgrade']['url'])
          <p class="tr-rule-action"><a class="btn btn-primary" href="{{ $account['upgrade']['url'] }}">{{ $account['upgrade']['plan'] ? __('transcribe.page.get_plan', ['plan' => $account['upgrade']['plan']]) : __('transcribe.page.see_plans') }}</a></p>
        @endif
      </div>
    @endif

    {{-- The box: add audio, see what it will use, start it. --}}
    <div class="dub-studio tr-studio" data-tr-studio>
      <form class="vs-editor-card dub-card tr-card" data-tr-form novalidate>
        <div class="dub-stage" data-tr-stage>
          {{-- Nothing chosen yet: drop a file, or upload, a link, a recording --}}
          <div class="dub-drop tr-drop" data-tr-view="drop" data-tr-drop>
            <input type="file" data-tr-file tabindex="-1" aria-hidden="true"
              accept="audio/*,video/mp4,video/webm,video/quicktime,.{{ implode(',.', \App\Http\Controllers\TranscribeController::EXTENSIONS) }}">
            <span class="dub-drop-art" aria-hidden="true">
              <svg viewBox="0 0 48 48"><path d="M10 20v8M16 15v18M22 11v26M28 16v16"/><path d="M34 18h8M34 24h8M34 30h5"/></svg>
            </span>
            <span class="dub-drop-title">{{ __('transcribe.page.drop_title') }}</span>
            <span class="dub-drop-or">{{ __('transcribe.page.drop_text') }}</span>
            {{-- The file chosen, as a card: its length in the corner, its name below; a recording plays from it --}}
            <span class="tr-tile" data-tr-tile>
              <button type="button" class="tr-tile-art" data-tr-preview-play disabled
                aria-label="{{ __('transcribe.js.play_recording') }}" data-label-play="{{ __('transcribe.js.play_recording') }}" data-label-pause="{{ __('transcribe.js.pause_recording') }}">
                <svg class="tr-tile-icon" data-tr-file-icon="upload" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V6l11-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/></svg>
                <span class="tr-tile-play" data-tr-file-icon="record" aria-hidden="true" hidden>
                  <svg class="tr-play-icon" viewBox="0 0 24 24"><path d="M8 5.5v13l10.5-6.5z"/></svg>
                  <svg class="tr-pause-icon" viewBox="0 0 24 24"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
                </span>
                <span class="tr-tile-time" data-tr-file-length dir="ltr"></span>
                <span class="tr-tile-bar" aria-hidden="true"><span data-tr-preview-bar></span></span>
              </button>
              <span class="tr-tile-name" data-tr-file-name dir="auto"></span>
              <button type="button" class="tr-tile-remove" data-tr-change aria-label="{{ __('transcribe.js.remove_file') }}" title="{{ __('transcribe.js.remove_file') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
              </button>
            </span>
            <audio class="initialized" data-tr-preview preload="metadata" hidden></audio>
            <span class="tr-options">
              <button type="button" class="btn tr-option" data-tr-upload>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
                <span>{{ __('transcribe.page.upload') }}</span>
              </button>
              <button type="button" class="btn tr-option" data-tr-go-link>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>
                <span>{{ __('transcribe.page.link') }}</span>
              </button>
              <button type="button" class="btn tr-option" data-tr-go-record>
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/></svg>
                <span>{{ __('transcribe.page.record') }}</span>
              </button>
            </span>
            <span class="dub-drop-limits">
              <span>{{ __('transcribe.page.formats') }}</span>
              <span>{{ __('transcribe.page.longest', ['length' => $trLongest]) }}</span>
              <span>{{ __('transcribe.page.size', ['mb' => $trMaxMb]) }}</span>
            </span>
          </div>

          {{-- A link to a file --}}
          <div class="tr-panel" data-tr-view="link" hidden>
            <label class="vs-field-label" for="trLink">{{ __('transcribe.page.link_label') }}</label>
            <input id="trLink" type="url" inputmode="url" dir="ltr" autocomplete="off" placeholder="{{ __('transcribe.page.link_placeholder') }}" data-tr-link>
            <p class="tr-note">{{ __('transcribe.page.link_note') }}</p>
            <div class="tr-panel-actions">
              <button type="button" class="btn btn-primary" data-tr-link-use>{{ __('transcribe.page.link_use') }}</button>
              <button type="button" class="btn btn-ghost" data-tr-back>{{ __('transcribe.page.cancel') }}</button>
            </div>
          </div>

          {{-- Recording: it starts at once; pause, stop, or close to throw it away --}}
          <div class="tr-recorder" data-tr-view="record" hidden>
            <button type="button" class="tr-rec-close" data-tr-back aria-label="{{ __('transcribe.page.cancel') }}" title="{{ __('transcribe.page.cancel') }}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
            <div class="tr-rec-controls">
              <button type="button" class="tr-rec-pause" data-tr-rec-pause aria-label="{{ __('transcribe.js.pause') }}"
                data-label-pause="{{ __('transcribe.js.pause') }}" data-label-resume="{{ __('transcribe.js.resume') }}">
                <svg class="tr-pause-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
                <svg class="tr-play-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>
              </button>
              <button type="button" class="tr-rec-stop" data-tr-rec aria-label="{{ __('transcribe.page.record_stop') }}" title="{{ __('transcribe.page.record_stop') }}">
                <span aria-hidden="true"></span>
              </button>
            </div>
            <div class="tr-rec-bar">
              <span class="tr-rec-state"><span class="tr-rec-dot" aria-hidden="true"></span><span data-tr-rec-state>{{ __('transcribe.js.recording') }}</span></span>
              <canvas class="tr-rec-wave" data-tr-rec-wave aria-hidden="true"></canvas>
              <span class="tr-rec-time" data-tr-rec-time dir="ltr">00:00</span>
            </div>
          </div>

          {{-- Sending it, then writing it out --}}
          <div class="dub-working" data-tr-view="working" hidden aria-live="polite" tabindex="-1">
            <div class="dub-working-head">
              <span class="dub-spinner" aria-hidden="true"></span>
              <div>
                <strong data-tr-working-title></strong>
                <small data-tr-working-note></small>
              </div>
              <span class="dub-percent" data-tr-percent dir="ltr">0%</span>
            </div>
            <div class="dub-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" data-tr-bar><span data-tr-bar-fill></span></div>
            <ol class="dub-steps">
              <li data-tr-step="upload">{{ __('transcribe.js.step_upload') }}</li>
              <li data-tr-step="prepare">{{ __('transcribe.js.step_prepare') }}</li>
              <li data-tr-step="listen">{{ __('transcribe.js.step_listen') }}</li>
              <li data-tr-step="done">{{ __('transcribe.js.step_done') }}</li>
            </ol>
          </div>
        </div>

        {{-- What the file will use, what is left, what will remain --}}
        <div class="dub-meter" data-tr-meter aria-live="polite"></div>
        <p class="dub-error" data-tr-error role="alert" hidden></p>

        <div class="vs-editor-foot dub-foot tr-foot">
          <div class="tr-language">
            <label class="vs-field-label" for="trLanguage">{{ __('transcribe.page.language') }}</label>
            <select id="trLanguage" data-tr-language data-vs-select data-vs-search="{{ __('transcribe.page.search_language') }}" data-vs-none="{{ __('transcribe.page.no_language') }}" data-vs-flags>
              <option value="auto" data-search="{{ __('transcribe.page.detect') }} auto">{{ __('transcribe.page.detect') }}</option>
              @foreach($trLanguages as $language)
                <option value="{{ $language['value'] }}" data-search="{{ $language['search'] }}" @if($language['flag']) data-flag="{{ $language['flag'] }}" @elseif($language['badge']) data-flag-letter="{{ $language['badge'] }}" @endif>{{ $language['label'] }}</option>
              @endforeach
            </select>
          </div>
          <div class="dub-go-row">
            <button class="dub-go" type="submit" data-tr-submit disabled>
              <span>
                <svg viewBox="0 0 24 24"><path d="M5 7h14M5 12h14M5 17h9"/></svg>
                <span data-tr-submit-text>{{ __('transcribe.js.start_plain') }}</span>
              </span>
            </button>
          </div>
        </div>
      </form>
    </div>

    {{-- A transcript open, as an editor: a bar of actions, the transcript, its details, and a timeline to play and find a place --}}
    <section class="tr-reader tr-editor" data-tr-reader hidden aria-labelledby="trReaderTitle" tabindex="-1">
      <header class="tr-ed-bar">
        <button type="button" class="tr-ed-back" data-tr-close aria-label="{{ __('transcribe.js.back') }}" title="{{ __('transcribe.js.back') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        </button>
        <div class="tr-ed-heading">
          <div class="tr-reader-name">
            <h2 id="trReaderTitle" data-tr-reader-title dir="auto"></h2>
            <button type="button" class="tr-icon tr-rename" data-tr-rename-open aria-label="{{ __('transcribe.js.rename') }}" title="{{ __('transcribe.js.rename') }}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.8 2.8 0 0 0-4-4L4 16v4z"/></svg>
            </button>
          </div>
          {{-- Its length, language and day, each once, small under its name --}}
          <p class="tr-ed-facts">
            <span title="{{ __('transcribe.js.length') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg><span class="sr-only">{{ __('transcribe.js.length') }}: </span><span data-tr-fact="length" dir="auto"></span></span>
            <span title="{{ __('transcribe.page.language') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5s1.2-6.1 3.5-8.5z"/></svg><span class="sr-only">{{ __('transcribe.page.language') }}: </span><span data-tr-fact="language"></span></span>
            <span title="{{ __('transcribe.js.created') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5.5" width="16" height="14" rx="2.5"/><path d="M8 3.5v4M16 3.5v4M4 10h16"/></svg><span class="sr-only">{{ __('transcribe.js.created') }}: </span><span data-tr-fact="date" dir="auto"></span></span>
          </p>
        </div>
        <div class="tr-toolbar" role="toolbar" aria-label="{{ __('transcribe.page.col_actions') }}">
          <button type="button" class="tr-tool tr-tool-icon" data-tr-copy aria-label="{{ __('transcribe.js.copy') }}" title="{{ __('transcribe.js.copy') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8.5" y="8.5" width="11" height="11" rx="2.5"/><path d="M15.5 8.5V6.5a2 2 0 0 0-2-2h-7a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h2"/></svg>
            <span class="tr-tool-label sr-only" data-tr-copy-label>{{ __('transcribe.js.copy') }}</span>
          </button>
          <button type="button" class="tr-tool tr-tool-icon tr-tool-danger" data-tr-delete aria-label="{{ __('transcribe.js.delete') }}" title="{{ __('transcribe.js.delete') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5"/></svg>
          </button>
          <span class="tr-ed-sep" aria-hidden="true"></span>
          <button type="button" class="tr-tool" data-tr-studio-send title="{{ __('transcribe.js.send_studio') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/></svg>
            <span class="tr-tool-label">{{ __('transcribe.js.send_studio') }}</span>
          </button>
          <div class="tr-menu" data-tr-menu>
            <button type="button" class="tr-tool tr-menu-toggle" aria-haspopup="true" aria-expanded="false" data-tr-menu-button data-tr-language-button
              aria-label="{{ __('transcribe.js.translate_to') }}: {{ __('transcribe.js.original') }}">
              <span class="tr-language-icon" data-tr-language-icon aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5s1.2-6.1 3.5-8.5z"/></svg></span>
              <span class="tr-tool-label" data-tr-language-current>{{ __('transcribe.js.original') }}</span>
              <svg class="tr-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="tr-menu-list tr-language-list" role="menu" hidden aria-label="{{ __('transcribe.js.translate_to') }}">
              <p class="tr-menu-head" aria-hidden="true">{{ __('transcribe.js.translate_to') }}</p>
              <div class="tr-menu-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                <input type="search" data-tr-language-search placeholder="{{ __('transcribe.page.search_language') }}" aria-label="{{ __('transcribe.page.search_language') }}" autocomplete="off" spellcheck="false">
              </div>
              <div data-tr-language-items></div>
              <p class="tr-menu-none" data-tr-language-none hidden>{{ __('transcribe.page.no_language') }}</p>
            </div>
          </div>
          <div class="tr-menu" data-tr-menu>
            <button type="button" class="tr-tool tr-tool-primary tr-menu-toggle" aria-haspopup="true" aria-expanded="false" data-tr-menu-button title="{{ __('transcribe.js.export') }}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M5 13v5.5A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5V13"/></svg>
              <span class="tr-tool-label">{{ __('transcribe.js.export') }}</span>
              <svg class="tr-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="tr-menu-list" role="menu" hidden>
              @foreach(\App\Services\Transcription\TranscriptExport::FORMATS as $format)
                <a role="menuitem" href="#" data-tr-export="{{ $format }}"><span class="tr-format" dir="ltr">{{ strtoupper($format) }}</span><span>{{ __('transcribe.js.'.$format) }}</span></a>
              @endforeach
            </div>
          </div>
        </div>
      </header>
      <p class="tr-note tr-note-error" data-tr-translate-note role="status" hidden></p>
      <p class="tr-note tr-ed-note" data-tr-audio-gone hidden></p>
      <div class="tr-ed-body">
        <div class="tr-ed-doc" data-tr-doc>
          <div class="tr-text" data-tr-text></div>
        </div>
      </div>
      {{-- The recording on a timeline: one track for each speaker, a ruler, the play head; click or drag anywhere on it to go there --}}
      <audio class="initialized" data-tr-audio preload="metadata" hidden></audio>
      <div class="tr-timeline" data-tr-playbar dir="ltr" hidden>
        <div class="tr-tl-grid">
          <div class="tr-tl-labels" data-tr-tl-labels></div>
          <div class="tr-tl-scroll" data-tr-tl-scroll tabindex="0" role="slider" aria-label="{{ __('transcribe.js.seek') }}" aria-valuemin="0" aria-valuemax="0" aria-valuenow="0">
            <div class="tr-tl-canvas" data-tr-tl-canvas>
              <div class="tr-tl-ruler" data-tr-tl-ruler></div>
              {{-- The sound itself, drawn as it is heard; the part played so far is lit --}}
              <div class="tr-tl-wave" aria-hidden="true"><canvas data-tr-tl-wave></canvas></div>
              <div class="tr-tl-tracks" data-tr-tl-tracks></div>
              <div class="tr-tl-hover" data-tr-tl-hover aria-hidden="true" hidden><span data-tr-tl-hover-time>0:00</span></div>
              <div class="tr-tl-head" data-tr-tl-head aria-hidden="true"><span class="tr-tl-badge" data-tr-tl-badge>0:00.0</span></div>
            </div>
          </div>
        </div>
        <div class="tr-tl-controls">
          <div class="tr-tl-end" aria-hidden="true"></div>
          <div class="tr-tl-transport">
            <button type="button" class="tr-pb-speed" data-tr-pb-speed aria-label="{{ __('transcribe.js.speed') }}" title="{{ __('transcribe.js.speed') }}">1×</button>
            <button type="button" class="tr-pb-play" data-tr-pb-play aria-label="{{ __('transcribe.js.play') }}"
              data-label-play="{{ __('transcribe.js.play') }}" data-label-pause="{{ __('transcribe.js.pause') }}">
              <svg class="tr-play-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>
              <svg class="tr-pause-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
            </button>
            <span class="tr-tl-time"><span data-tr-pb-now>0:00</span><span class="tr-tl-of">/</span><span data-tr-pb-total>0:00</span></span>
          </div>
          <div class="tr-tl-end" aria-hidden="true"></div>
        </div>
      </div>
    </section>

    {{-- Every transcript, with the minutes it used --}}
    <section class="dub-ledger tr-ledger" aria-labelledby="trLedgerTitle" data-tr-ledger>
      <h2 id="trLedgerTitle">{{ __('transcribe.page.history_title') }}</h2>
      <label class="tr-search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/></svg>
        <span class="sr-only">{{ __('transcribe.page.search') }}</span>
        <input type="search" placeholder="{{ __('transcribe.page.search') }}" data-tr-search>
      </label>
      <div class="dub-ledger-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ __('transcribe.page.col_date') }}</th>
              <th>{{ __('transcribe.page.col_name') }}</th>
              <th>{{ __('transcribe.page.col_language') }}</th>
              <th>{{ __('transcribe.page.col_length') }}</th>
              <th>{{ __('transcribe.page.col_status') }}</th>
              <th><span class="sr-only">{{ __('transcribe.page.col_actions') }}</span></th>
            </tr>
          </thead>
          <tbody data-tr-rows>
            <tr><td colspan="7" class="dub-ledger-empty" data-tr-empty>{{ __('transcribe.js.loading') }}</td></tr>
          </tbody>
        </table>
      </div>
      <div class="tr-more"><button type="button" class="btn btn-ghost" data-tr-more hidden>{{ __('transcribe.page.more') }}</button></div>
      <template data-tr-empty-text>{{ __('transcribe.page.empty') }}</template>
      <template data-tr-no-match>{{ __('transcribe.page.no_match') }}</template>
    </section>

    {{-- Asked before a transcript is deleted: the site's own dialog, never the browser's --}}
    <dialog class="tr-dialog" data-tr-confirm aria-labelledby="trConfirmTitle" aria-describedby="trConfirmText">
      <form method="dialog" class="tr-dialog-card">
        <span class="tr-dialog-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5"/></svg></span>
        <h3 id="trConfirmTitle">{{ __('transcribe.js.delete_title') }}</h3>
        <p id="trConfirmText">{{ __('transcribe.js.delete_text') }}</p>
        <div class="tr-dialog-actions">
          <button type="submit" value="cancel" class="tr-dialog-btn" autofocus>{{ __('transcribe.js.cancel') }}</button>
          <button type="submit" value="ok" class="tr-dialog-btn is-danger">{{ __('transcribe.js.delete') }}</button>
        </div>
      </form>
    </dialog>
    {{-- What an action did, said for a moment at the foot of the screen --}}
    <div class="tr-toast" data-tr-toast role="status" aria-live="polite"></div>

    <script type="application/json" data-tr-config>@json($trConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)</script>
  </article>
</section>
