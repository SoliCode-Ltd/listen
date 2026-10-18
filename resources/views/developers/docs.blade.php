@include('components.page-head', ['pageTitle' => __('developers.docs.seo_title'), 'page' => 'developers'])
@include('developers._styles')
</head>
<body>
  @include('components.header')

  @php
    $d = fn (string $key, array $replace = []) => __('developers.docs.'.$key, $replace);
    $voice = $sampleVoiceId;
    $keysUrl = route('developers.keys');
    $sections = [
      'overview' => $d('overview_title'),
      'quickstart' => $d('quickstart_title'),
      'authentication' => $d('auth_title'),
      'text-to-speech' => $d('tts_title'),
      'async' => $d('async_title'),
      'voices' => $d('voices_title'),
      'history' => $d('history_title'),
      'usage' => $d('user_title'),
      'credits' => $d('credits_title'),
      'rate-limits' => $d('limits_title'),
      'errors' => $d('errors_title'),
      'best-practices' => $d('practices_title'),
      'versioning' => $d('versioning_title'),
      'support' => $d('support_title'),
    ];

    $ttsBody = '{
  "text": "أهلين! كيفك اليوم؟ أنا هون لساعدك.",
  "dialect": "ar-LB",
  "style": "assistant",
  "emotion": "warm",
  "voice_settings": { "speed": 1.0 }
}';

    $quickCurl = 'export LISTEN_API_KEY="lsk_live_..."

# 1. List the Arabic voices your plan can use
curl "'.$baseUrl.'/voices?language=ar" \\
  -H "X-Listen-Api-Key: $LISTEN_API_KEY"

# 2. Turn text into speech and save it
curl -X POST "'.$baseUrl.'/text-to-speech/'.$voice.'?output_format=mp3" \\
  -H "X-Listen-Api-Key: $LISTEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -d \''.str_replace("\n", "\n  ", $ttsBody).'\' \\
  --output speech.mp3';

    $quickPython = 'import json
import os
import urllib.error
import urllib.request

API_KEY = os.environ["LISTEN_API_KEY"]
BASE_URL = "'.$baseUrl.'"


def text_to_speech(voice_id, payload, output_format="mp3"):
    request = urllib.request.Request(
        f"{BASE_URL}/text-to-speech/{voice_id}?output_format={output_format}",
        data=json.dumps(payload).encode("utf-8"),
        headers={"X-Listen-Api-Key": API_KEY, "Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=300) as response:
            print("Credits used:", response.headers["X-Credits-Used"])
            return response.read()
    except urllib.error.HTTPError as e:
        error = json.loads(e.read())["error"]
        raise RuntimeError(f"{error[\'code\']}: {error[\'message\']} ({error[\'request_id\']})")


audio = text_to_speech("'.$voice.'", {
    "text": "أهلين! كيفك اليوم؟ أنا هون لساعدك.",
    "dialect": "ar-LB",
    "style": "assistant",
    "emotion": "warm",
})
with open("speech.mp3", "wb") as f:
    f.write(audio)';

    $quickNode = '// Node.js 18 or later
import { writeFile } from "node:fs/promises";

const BASE_URL = "'.$baseUrl.'";

const response = await fetch(`${BASE_URL}/text-to-speech/'.$voice.'?output_format=mp3`, {
  method: "POST",
  headers: {
    "X-Listen-Api-Key": process.env.LISTEN_API_KEY,
    "Content-Type": "application/json",
  },
  body: JSON.stringify({
    text: "أهلين! كيفك اليوم؟ أنا هون لساعدك.",
    dialect: "ar-LB",
    style: "assistant",
    emotion: "warm",
  }),
});

if (!response.ok) {
  const { error } = await response.json();
  throw new Error(`${error.code}: ${error.message} (${error.request_id})`);
}

console.log("Credits used:", response.headers.get("X-Credits-Used"));
await writeFile("speech.mp3", Buffer.from(await response.arrayBuffer()));';

    $authCurl = '# Either header works
curl "'.$baseUrl.'/user/subscription" -H "X-Listen-Api-Key: $LISTEN_API_KEY"
curl "'.$baseUrl.'/user/subscription" -H "Authorization: Bearer $LISTEN_API_KEY"';

    $asyncCurl = '# Start
curl -X POST "'.$baseUrl.'/text-to-speech/'.$voice.'/async" \\
  -H "X-Listen-Api-Key: $LISTEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -d \'{"text": "A long chapter ...", "title": "Chapter 1"}\'
# → 202 {"history_item_id": 4821, "status": "queued", ...}

# Poll until "status" is "completed" or "failed"
curl "'.$baseUrl.'/history/4821" -H "X-Listen-Api-Key: $LISTEN_API_KEY"

# Download
curl "'.$baseUrl.'/history/4821/audio?output_format=mp3" \\
  -H "X-Listen-Api-Key: $LISTEN_API_KEY" --output chapter-1.mp3';

    $asyncPython = 'import json, os, time, urllib.request

API_KEY = os.environ["LISTEN_API_KEY"]
BASE_URL = "'.$baseUrl.'"
HEADERS = {"X-Listen-Api-Key": API_KEY, "Content-Type": "application/json"}


def call(method, path, payload=None):
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    req = urllib.request.Request(BASE_URL + path, data=data, headers=HEADERS, method=method)
    with urllib.request.urlopen(req, timeout=60) as response:
        return response.read()


item = json.loads(call("POST", "/text-to-speech/'.$voice.'/async", {"text": open("chapter.txt").read()}))
while item["status"] in ("queued", "processing"):
    time.sleep(3)
    item = json.loads(call("GET", f"/history/{item[\'history_item_id\']}"))

if item["status"] == "completed":
    with open("chapter.mp3", "wb") as f:
        f.write(call("GET", f"/history/{item[\'history_item_id\']}/audio"))
else:
    print("Failed:", item["error"]["message"])  # its credits were given back';

    $voicesExample = '{
  "voices": [
    {
      "voice_id": "'.$voice.'",
      "name": "Sumaya",
      "name_ar": "سمية",
      "category": "premade",
      "language": "ar",
      "gender": "female",
      "accent": null,
      "model_id": "listen_premium",
      "featured": true,
      "available_on_plan": true,
      "preview_url": "'.url('/media/...').'",
      "avatar_url": "'.url('/images/speakers/...').'"
    }
  ],
  "total_count": 1
}';

    $historyExample = '{
  "history": [
    {
      "history_item_id": 4821,
      "status": "completed",
      "request_source": "api",
      "api_key_id": 7,
      "voice_id": "'.$voice.'",
      "voice_name": "Sumaya",
      "model_id": "listen_premium",
      "language_code": "ar",
      "dialect": "ar-LB",
      "style": "assistant",
      "emotion": "warm",
      "voice_settings": { "speed": 1, "pitch": 0 },
      "title": null,
      "text": "أهلين! كيفك اليوم؟",
      "character_count": 18,
      "credits_used": 45,
      "duration_seconds": 3,
      "audio_url": "'.$baseUrl.'/history/4821/audio",
      "error": null,
      "created_at": "2026-10-18T09:30:12+00:00",
      "created_at_unix": 1792315812
    }
  ],
  "has_more": true,
  "next_cursor": 4821
}';

    $subscriptionExample = '{
  "plan": {
    "slug": "creator",
    "name": "Creator",
    "models": ["listen_standard", "listen_premium"],
    "commercial_use_allowed": true,
    "processing_priority": "high"
  },
  "status": "active",
  "trial": false,
  "current_period_end": "2026-11-02T10:00:00+00:00",
  "billing_mode": "monthly",
  "credits": {
    "used": 182340,
    "limit": 1500000,
    "remaining": 1317660,
    "gift": 0,
    "credits_per_second": 15,
    "overage_allowed": false,
    "period": "2026-10",
    "resets_at": "2026-11-01T00:00:00+00:00",
    "resets_at_unix": 1793404800,
    "expires_at": null
  },
  "voice_seconds": { "used": 12156, "limit": 100000 },
  "requests": { "used_this_month": 911, "monthly_limit": null, "daily_limit": null, "used_today": null },
  "limits": {
    "max_characters_per_request": 20000,
    "requests_per_minute": 120,
    "concurrent_generations": 5,
    "output_formats": ["mp3", "wav"]
  },
  "api_key": {
    "api_key_id": 7,
    "name": "Production assistant",
    "monthly_credit_limit": 500000,
    "credits_used_this_month": 98210,
    "requests_this_month": 640
  }
}';

    $errorExample = 'HTTP/1.1 402 Payment Required
X-Request-Id: req_8f2kq0x7m3c9w1v5b6n4z2a1

{
  "error": {
    "type": "quota_error",
    "code": "quota_exceeded",
    "message": "Your monthly credits are used up. Please upgrade your plan to continue.",
    "param": null,
    "credits_needed": 450,
    "request_id": "req_8f2kq0x7m3c9w1v5b6n4z2a1"
  }
}';

    $retryPython = 'import json, random, time, urllib.error, urllib.request


def with_retries(make_request, attempts=5):
    """Retry 429 and 5xx responses with exponential backoff; raise anything else."""
    for attempt in range(attempts):
        try:
            return make_request()
        except urllib.error.HTTPError as e:
            if e.code != 429 and e.code < 500 or attempt == attempts - 1:
                raise
            retry_after = e.headers.get("Retry-After")
            delay = float(retry_after) if retry_after else (2 ** attempt) + random.random()
            time.sleep(delay)';

    $retryNode = 'async function withRetries(makeRequest, attempts = 5) {
  for (let attempt = 0; ; attempt++) {
    const response = await makeRequest();
    const retryable = response.status === 429 || response.status >= 500;
    if (!retryable || attempt === attempts - 1) return response;
    const retryAfter = Number(response.headers.get("Retry-After"));
    const delay = retryAfter > 0 ? retryAfter * 1000 : 2 ** attempt * 1000 + Math.random() * 1000;
    await new Promise((resolve) => setTimeout(resolve, delay));
  }
}';

    $param = function (string $name, string $type, bool $required, string $description) use ($d) {
      return ['name' => $name, 'type' => $type, 'required' => $required, 'description' => $description];
    };
    $bodyParams = [
      $param('text', 'string', true, $d('p_text')),
      $param('model_id', 'string', false, $d('p_model_id')),
      $param('language_code', 'string', false, $d('p_language_code')),
      $param('dialect', 'string', false, $d('p_dialect').' '.$d('premium_only')),
      $param('style', 'string', false, $d('p_style').' '.$d('premium_only')),
      $param('emotion', 'string', false, $d('p_emotion').' '.$d('premium_only')),
      $param('style_prompt', 'string', false, $d('p_style_prompt').' '.$d('premium_only')),
      $param('voice_settings', 'object', false, $d('p_voice_settings').' '.$d('premium_only')),
      $param('translate', 'boolean', false, $d('p_translate')),
      $param('title', 'string', false, $d('p_title')),
    ];
    $responseHeaders = [
      'X-History-Item-Id' => $d('h_history'),
      'X-Character-Count' => $d('h_chars'),
      'X-Credits-Used' => $d('h_credits'),
      'X-Credits-Remaining' => $d('h_remaining'),
      'X-Audio-Duration-Seconds' => $d('h_duration'),
      'X-Output-Format' => $d('h_format'),
      'X-Request-Id' => $d('h_request'),
    ];
    $supportEmail = '<a href="mailto:support@listenai.studio">support@listenai.studio</a>';
  @endphp

  <main class="container dev-page">
    <section class="dev-hero">
      <span class="dev-kicker">{{ $d('kicker') }}</span>
      <h1>{{ $d('title') }}</h1>
      <p>{{ $d('subtitle') }}</p>
      <div class="dev-actions">
        <a class="btn btn-primary" href="{{ $keysUrl }}">{{ $d('cta_keys') }}</a>
        <a class="btn" href="{{ route('v1.openapi') }}" target="_blank" rel="noopener">{{ $d('cta_openapi') }}</a>
      </div>
    </section>

    <div class="dev-layout">
      <nav class="dev-toc" aria-label="{{ $d('toc') }}">
        <strong>{{ $d('toc') }}</strong>
        @foreach($sections as $id => $label)
          <a href="#{{ $id }}">{{ $label }}</a>
        @endforeach
      </nav>

      <div>
        <section class="dev-card dev-section" id="overview">
          <h2>{{ $d('overview_title') }}</h2>
          <p>{{ $d('overview_body') }}</p>
          <ul>
            @foreach(__('developers.docs.overview_points') as $point)
              <li>{{ $point }}</li>
            @endforeach
          </ul>
          <p><strong>{{ $d('base_url') }}:</strong> <code>{{ $baseUrl }}</code></p>
          @if(app()->getLocale() === 'ar')
            <p class="dev-muted">{{ $d('english_note') }}</p>
          @endif

          <h3>{{ $d('availability_title') }}</h3>
          @if($planRows === [])
            <p>{{ $d('availability_none') }}</p>
          @else
            <p>{{ $d('availability_body') }}</p>
            <div class="dev-table-wrap">
              <table class="dev-table">
                <thead><tr>
                  <th>{{ $d('col_plan') }}</th><th>{{ $d('col_credits') }}</th><th>{{ $d('col_rpm') }}</th>
                  <th>{{ $d('col_concurrency') }}</th><th>{{ $d('col_max_chars') }}</th><th>{{ $d('col_models') }}</th>
                </tr></thead>
                <tbody>
                  @foreach($planRows as $row)
                    <tr>
                      <td>{{ $row['name'] }}</td>
                      <td>{{ number_format($row['credits']) }}</td>
                      <td>{{ number_format($row['rpm']) }}</td>
                      <td>{{ number_format($row['concurrency']) }}</td>
                      <td>{{ number_format($row['max_chars']) }}</td>
                      <td>@foreach($row['models'] as $model)<code>{{ $model }}</code> @endforeach</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </section>

        <section class="dev-card dev-section" id="quickstart">
          <h2>{{ $d('quickstart_title') }}</h2>
          <ol>
            <li><a href="{{ $keysUrl }}">{{ $d('quickstart_step1') }}</a></li>
            <li>{{ $d('quickstart_step2') }}</li>
            <li>{{ $d('quickstart_step3') }}</li>
          </ol>
          @include('developers._code', ['samples' => ['cURL' => $quickCurl, 'Python' => $quickPython, 'Node.js' => $quickNode]])
        </section>

        <section class="dev-card dev-section" id="authentication">
          <h2>{{ $d('auth_title') }}</h2>
          <p>{{ $d('auth_body') }}</p>
          @include('developers._code', ['samples' => ['cURL' => $authCurl]])
          <div class="dev-note warn">{{ $d('auth_security') }}</div>
          <h3>{{ $d('auth_scopes_title') }}</h3>
          <p>{{ $d('auth_scopes_body') }}</p>
          <div class="dev-table-wrap">
            <table class="dev-table">
              <thead><tr><th>{{ $d('col_permission') }}</th><th>{{ $d('col_allows') }}</th></tr></thead>
              <tbody>
                @foreach($scopes as $scope)
                  <tr><td><code>{{ $scope }}</code></td><td dir="ltr">{{ $d('scope_'.$scope) }}</td></tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </section>

        <section class="dev-card dev-section" id="text-to-speech">
          <h2>{{ $d('tts_title') }}</h2>
          <div class="dev-endpoint"><span class="dev-method">POST</span><span class="dev-path">/v1/text-to-speech/{voice_id}</span></div>
          <p>{{ $d('tts_body') }}</p>

          <h3>{{ $d('params_path') }}</h3>
          <div class="dev-table-wrap"><table class="dev-table">
            <thead><tr><th>{{ $d('col_param') }}</th><th>{{ $d('col_type') }}</th><th>{{ $d('col_description') }}</th></tr></thead>
            <tbody><tr><td><code>voice_id</code><span class="dev-pill req">{{ $d('required') }}</span></td><td>string</td><td>{{ $d('p_voice_id') }}</td></tr></tbody>
          </table></div>

          <h3>{{ $d('params_query') }}</h3>
          <div class="dev-table-wrap"><table class="dev-table">
            <thead><tr><th>{{ $d('col_param') }}</th><th>{{ $d('col_type') }}</th><th>{{ $d('col_description') }}</th></tr></thead>
            <tbody><tr><td><code>output_format</code><span class="dev-pill">{{ $d('optional') }}</span></td><td>string</td><td>{{ $d('p_output_format') }}</td></tr></tbody>
          </table></div>

          <h3>{{ $d('params_body') }}</h3>
          <div class="dev-table-wrap"><table class="dev-table">
            <thead><tr><th>{{ $d('col_param') }}</th><th>{{ $d('col_type') }}</th><th>{{ $d('col_description') }}</th></tr></thead>
            <tbody>
              @foreach($bodyParams as $p)
                <tr>
                  <td><code>{{ $p['name'] }}</code><span class="dev-pill {{ $p['required'] ? 'req' : '' }}">{{ $p['required'] ? $d('required') : $d('optional') }}</span></td>
                  <td>{{ $p['type'] }}</td>
                  <td>{{ $p['description'] }}</td>
                </tr>
              @endforeach
            </tbody>
          </table></div>
          @include('developers._code', ['samples' => ['JSON' => $ttsBody]])

          <h3>{{ $d('response_title') }}</h3>
          <p>{{ $d('response_body') }}</p>
          <div class="dev-table-wrap"><table class="dev-table">
            <tbody>
              @foreach($responseHeaders as $header => $meaning)
                <tr><td><code>{{ $header }}</code></td><td>{{ $meaning }}</td></tr>
              @endforeach
            </tbody>
          </table></div>

          <h3>{{ $d('dialects_title') }}</h3>
          <p>{{ $d('dialects_body') }}</p>
        </section>

        <section class="dev-card dev-section" id="async">
          <h2>{{ $d('async_title') }}</h2>
          <div class="dev-endpoint"><span class="dev-method">POST</span><span class="dev-path">/v1/text-to-speech/{voice_id}/async</span></div>
          <p>{{ $d('async_body') }}</p>
          @include('developers._code', ['samples' => ['cURL' => $asyncCurl, 'Python' => $asyncPython]])
        </section>

        <section class="dev-card dev-section" id="voices">
          <h2>{{ $d('voices_title') }}</h2>
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/voices</span></div>
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/voices/{voice_id}</span></div>
          <p>{{ $d('voices_body') }}</p>
          @include('developers._code', ['samples' => ['JSON' => $voicesExample]])
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/models</span></div>
          <p>{{ $d('models_body') }}</p>
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/voice-options</span></div>
          <p>{{ $d('options_body') }}</p>
        </section>

        <section class="dev-card dev-section" id="history">
          <h2>{{ $d('history_title') }}</h2>
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/history?page_size=30&amp;before={next_cursor}</span></div>
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/history/{history_item_id}</span></div>
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/history/{history_item_id}/audio</span></div>
          <div class="dev-endpoint"><span class="dev-method delete">DELETE</span><span class="dev-path">/v1/history/{history_item_id}</span></div>
          <p>{{ $d('history_body') }}</p>
          @include('developers._code', ['samples' => ['JSON' => $historyExample]])
        </section>

        <section class="dev-card dev-section" id="usage">
          <h2>{{ $d('user_title') }}</h2>
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/user/subscription</span></div>
          <div class="dev-endpoint"><span class="dev-method get">GET</span><span class="dev-path">/v1/user</span></div>
          <p>{{ $d('user_body') }}</p>
          @include('developers._code', ['samples' => ['JSON' => $subscriptionExample]])
        </section>

        <section class="dev-card dev-section" id="credits">
          <h2>{{ $d('credits_title') }}</h2>
          <ul>
            @foreach(__('developers.docs.credits_points') as $point)
              <li>{{ $point }}</li>
            @endforeach
          </ul>
        </section>

        <section class="dev-card dev-section" id="rate-limits">
          <h2>{{ $d('limits_title') }}</h2>
          <p>{{ $d('limits_body') }}</p>
          <ul>
            @foreach(__('developers.docs.limits_points') as $point)
              <li>{{ $point }}</li>
            @endforeach
          </ul>
          <p>{{ $d('limits_retry') }}</p>
          @include('developers._code', ['samples' => ['Python' => $retryPython, 'Node.js' => $retryNode]])
        </section>

        <section class="dev-card dev-section" id="errors">
          <h2>{{ $d('errors_title') }}</h2>
          <p>{{ $d('errors_body') }}</p>
          @include('developers._code', ['samples' => ['HTTP' => $errorExample]])
          <div class="dev-table-wrap"><table class="dev-table">
            <thead><tr><th>{{ $d('col_status') }}</th><th>{{ $d('col_code') }}</th><th>{{ $d('col_meaning') }}</th></tr></thead>
            <tbody>
              @foreach(__('developers.docs.error_rows') as [$status, $code, $meaning])
                <tr><td>{{ $status }}</td><td><code>{{ $code }}</code></td><td>{{ $meaning }}</td></tr>
              @endforeach
            </tbody>
          </table></div>
        </section>

        <section class="dev-card dev-section" id="best-practices">
          <h2>{{ $d('practices_title') }}</h2>
          <ul>
            @foreach(__('developers.docs.practices_points') as $point)
              <li>{{ $point }}</li>
            @endforeach
          </ul>
        </section>

        <section class="dev-card dev-section" id="versioning">
          <h2>{{ $d('versioning_title') }}</h2>
          <p>{{ $d('versioning_body') }}</p>
          <p><a href="{{ route('v1.openapi') }}" target="_blank" rel="noopener">{{ $d('openapi_body') }}</a></p>
        </section>

        <section class="dev-card dev-section" id="support">
          <h2>{{ $d('support_title') }}</h2>
          <p>{!! str_replace('__EMAIL__', $supportEmail, e($d('support_body', ['email' => '__EMAIL__']))) !!}</p>
        </section>
      </div>
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('developers._script')
</body>
</html>
