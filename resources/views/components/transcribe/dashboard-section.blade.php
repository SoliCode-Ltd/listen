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

          return ['value' => $code, 'label' => $trAr ? $names[1] : $names[0], 'search' => $names[0].' '.$names[1].' '.$code];
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
          'used' => __('transcribe.page.col_used'), 'status' => __('transcribe.page.col_status')],
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
      <div class="dub-rule">
        <strong class="dub-rule-title">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
          {{ __('transcribe.page.rule_title') }}
        </strong>
        <ul>
          <li><b>{{ __('transcribe.page.rule_second') }}</b><span>{{ __('transcribe.page.rule_second_value') }}</span></li>
          <li><b>{{ __('transcribe.page.rule_held') }}</b><span>{{ __('transcribe.page.rule_held_value') }}</span></li>
          <li><b>{{ __('transcribe.page.rule_free') }}</b><span>{{ __('transcribe.page.rule_free_value') }}</span></li>
        </ul>
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

          {{-- Recording --}}
          <div class="tr-panel tr-recorder" data-tr-view="record" hidden>
            <strong>{{ __('transcribe.page.record_title') }}</strong>
            <button type="button" class="tr-rec-btn" data-tr-rec aria-pressed="false" aria-label="{{ __('transcribe.page.record_start') }}"
              data-label-start="{{ __('transcribe.page.record_start') }}" data-label-stop="{{ __('transcribe.page.record_stop') }}"><span aria-hidden="true"></span></button>
            <span class="tr-rec-time" data-tr-rec-time dir="ltr">00:00</span>
            <p class="tr-note">{{ __('transcribe.page.record_hint') }}</p>
            <div class="tr-panel-actions">
              <button type="button" class="btn btn-ghost" data-tr-back>{{ __('transcribe.page.cancel') }}</button>
            </div>
          </div>

          {{-- A file (or a recording) chosen, its length known --}}
          <div class="dub-chosen" data-tr-view="chosen" hidden>
            <audio data-tr-preview controls preload="metadata" data-download-disabled="1"></audio>
            <div class="dub-file-row">
              <span class="dub-file-name" data-tr-file-name dir="auto"></span>
              <span class="dub-file-length" data-tr-file-length dir="ltr"></span>
              <button type="button" class="dub-link" data-tr-change>{{ __('transcribe.page.change') }}</button>
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
            <select id="trLanguage" data-tr-language data-vs-select data-vs-search="{{ __('transcribe.page.search_language') }}" data-vs-none="{{ __('transcribe.page.no_language') }}">
              <option value="auto" data-search="{{ __('transcribe.page.detect') }} auto">{{ __('transcribe.page.detect') }}</option>
              @foreach($trLanguages as $language)
                <option value="{{ $language['value'] }}" data-search="{{ $language['search'] }}">{{ $language['label'] }}</option>
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

    {{-- A transcript open: read, play, correct, translate, download --}}
    <section class="tr-reader" data-tr-reader hidden aria-labelledby="trReaderTitle" tabindex="-1">
      <button type="button" class="dub-link tr-back" data-tr-close>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>{{ __('transcribe.js.back') }}
      </button>
      <header class="tr-reader-head">
        <h2 id="trReaderTitle" data-tr-reader-title></h2>
        <p class="tr-reader-meta" data-tr-reader-meta></p>
      </header>
      {{-- One row of matching pill buttons, each an icon and a word; the language is a menu like Download. --}}
      <div class="tr-toolbar" role="toolbar" aria-label="{{ __('transcribe.page.col_actions') }}">
        <button type="button" class="tr-tool" data-tr-copy title="{{ __('transcribe.js.copy') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8.5" y="8.5" width="11" height="11" rx="2.5"/><path d="M15.5 8.5V6.5a2 2 0 0 0-2-2h-7a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h2"/></svg>
          <span class="tr-tool-label" data-tr-copy-label>{{ __('transcribe.js.copy') }}</span>
        </button>
        <div class="tr-menu" data-tr-menu>
          <button type="button" class="tr-tool tr-menu-toggle" aria-haspopup="true" aria-expanded="false" data-tr-menu-button title="{{ __('transcribe.js.download') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 19h14"/></svg>
            <span class="tr-tool-label">{{ __('transcribe.js.download') }}</span>
            <svg class="tr-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="tr-menu-list" role="menu" hidden>
            @foreach(\App\Services\Transcription\TranscriptExport::FORMATS as $format)
              <a role="menuitem" href="#" data-tr-export="{{ $format }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 19h14"/></svg>{{ __('transcribe.js.'.$format) }}</a>
            @endforeach
          </div>
        </div>
        <div class="tr-menu" data-tr-menu>
          <button type="button" class="tr-tool tr-menu-toggle" aria-haspopup="true" aria-expanded="false" data-tr-menu-button data-tr-language-button
            aria-label="{{ __('transcribe.js.translate_to') }}: {{ __('transcribe.js.original') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5s1.2-6.1 3.5-8.5z"/></svg>
            <span data-tr-language-current>{{ __('transcribe.js.original') }}</span>
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
        <button type="button" class="tr-tool" data-tr-studio-send title="{{ __('transcribe.js.send_studio') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/></svg>
          <span class="tr-tool-label">{{ __('transcribe.js.send_studio') }}</span>
        </button>
        <button type="button" class="tr-tool tr-tool-icon tr-tool-danger" data-tr-delete aria-label="{{ __('transcribe.js.delete') }}" title="{{ __('transcribe.js.delete') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5"/></svg>
        </button>
      </div>
      <p class="tr-note" data-tr-translate-note hidden>{{ __('transcribe.js.translate_free') }}</p>
      <div class="tr-player" data-tr-player></div>
      <div class="tr-speakers" data-tr-speakers hidden>
        <strong>{{ __('transcribe.js.speakers') }}</strong>
        <div class="tr-speaker-list" data-tr-speaker-list></div>
      </div>
      <div class="tr-text" data-tr-text></div>
    </section>

    {{-- Every transcript, with the minutes it used --}}
    <section class="dub-ledger tr-ledger" aria-labelledby="trLedgerTitle" data-tr-ledger>
      <h2 id="trLedgerTitle">{{ __('transcribe.page.history_title') }}</h2>
      <p>{{ __('transcribe.page.history_text') }}</p>
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
              <th>{{ __('transcribe.page.col_used') }}</th>
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

    <script type="application/json" data-tr-config>@json($trConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)</script>
  </article>
</section>
