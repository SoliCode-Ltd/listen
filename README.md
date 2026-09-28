# Listen

Listen is the Arabic-first text-to-speech platform at [listenai.studio](https://listenai.studio). It turns text into speech in Arabic and English, with translation, dialect and performance controls on HD voices, queued generation, usage plans, and a Chrome extension that reads selected text on any page.

The interface is available in English and Arabic.

## Live site

| Page | URL |
| --- | --- |
| Landing | https://listenai.studio/en |
| Demo | https://listenai.studio/en/demo |
| Pricing | https://listenai.studio/en/pricing |
| Login | https://listenai.studio/en/login |
| Dashboard | https://listenai.studio/en/dashboard |
| Billing | https://listenai.studio/en/billing |
| Browser extension | https://listenai.studio/en/browsers-extension |
| Contact | https://listenai.studio/en/contact-us |

Arabic pages use the `/ar` prefix, for example https://listenai.studio/ar.

## What you can do

- Generate speech from the dashboard or the signed-in demo, in Arabic or English, with optional translation.
- Choose Standard voices (Google Cloud WaveNet) or HD voices (Gemini), including Arabic dialects, emotion, and performance style.
- Keep a history of generations, replay them, and reuse the settings from an earlier HD job.
- Enforce plan limits: characters per request, monthly characters, monthly requests, and voice tier.
- Subscribe with Stripe, or submit a manual payment, then view and download invoices.
- Share a referral link and track referral rewards.
- Read selected text in Chrome with the Listen Reader extension.
- Administer users, plans, voices, generations, billing, and site settings in the Filament panel at `/admin`.

## Stack

- PHP 8.2+ and Laravel 12
- SQLite by default (`database/database.sqlite`)
- Vite, Tailwind CSS 4
- Filament 3 admin panel
- Laravel Cashier (Stripe) and Laravel Socialite (Google)
- Google Cloud Text-to-Speech and Gemini TTS
- Database queue
- English and Arabic UI (`mcamara/laravel-localization`)

## Requirements

- PHP 8.2 or newer, with the usual Laravel extensions
- Composer
- Node.js and npm
- A Google Cloud Text-to-Speech credential for Standard voices
- One or more Gemini API keys for HD voices
- Stripe keys if you want card billing

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
npm install
npm run build
```

`php artisan migrate --seed` creates plans, the voice catalog, voice samples, a test user (`test@example.com` / `password`), and an admin user. Change those seeded passwords before any shared environment.

For day-to-day development, one command starts the app, the queue worker, the log tail, and Vite:

```bash
composer dev
```

The app is then at http://localhost:8000. A health check is available at `/up`.

Generated audio is stored under `storage/app/public/audio` and served through the public storage link.

## Configuration

Copy `.env.example` and set at least the following. Full comments live in that file and in `config/listen.php`.

```env
APP_URL=https://listenai.studio

# Per-user extension keys are created in the app. This legacy key, if set,
# authenticates API calls as user id 1.
LISTEN_API_KEY=

# Google sign-in. Register this exact URI in Google Cloud.
# Locally, use http://localhost:8000/en/auth/social/callback.
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://listenai.studio/en/auth/social/callback

# Google Cloud Text-to-Speech (Standard / WaveNet)
SPEECH_TTS_API_KEY=
SPEECH_TTS_CREDENTIALS_JSON=
SPEECH_TTS_CREDENTIALS_PATH=
SPEECH_TTS_AUDIO_ENCODING=MP3
SPEECH_TTS_SPEAKING_RATE=0.95
SPEECH_TTS_PITCH=0.0
SPEECH_TTS_TIMEOUT_SECONDS=30

# Gemini TTS (HD). Add GEMINI_TTS_API_KEY_2, _3, ... for more keys.
GEMINI_TTS_API_KEY=
LISTEN_TTS_MODE=hybrid
LISTEN_TTS_FALLBACK_ENABLED=true

# Stripe. Price IDs are stored on plans after `php artisan stripe:sync-plans --all`.
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=

# sync: generate inside the request. queue: always use the worker.
# In sync mode, texts at least this long are still queued.
LISTEN_AUDIO_PROCESSING=sync
LISTEN_AUDIO_FORCE_ASYNC_CHARS=2500
```

`LISTEN_TTS_MODE` accepts `google` (Google Cloud only) or `hybrid` (Free plans stay on Google Cloud, paid plans use Gemini, with fallback to Google Cloud when Gemini fails).

Speech targets are `ar` and `en`. HD Arabic controls include dialect (`ar-XA` for Modern Standard Arabic, plus regional dialects), performance style (narrative, cinematic, inspirational, audiobook, and others), emotion, speed (`0.5`–`2.0`), and pitch (`-10`–`10`). Standard tier ignores those studio controls. Dialogue mode, with up to six speakers, is limited to plans that enable it (Studio Max in the seed data).

## Plans

`PlanSeeder` inserts these defaults. Live prices, quotas, and Stripe price IDs are edited in `/admin` and synced with `php artisan stripe:sync-plans --all`.

| Plan | Monthly price | Characters | Requests | Voice | Notable limits |
| --- | ---: | ---: | --- | --- | --- |
| Free | $0 | 10,000 | 60 | Standard | 1,200 characters per request, personal use |
| Starter | $9 | 500,000 | Unlimited | HD | Commercial use, emotion and dialect |
| Creator | $22 | 1,500,000 | Unlimited | HD | Featured plan, higher queue priority |
| Pro | $49 | 4,000,000 | Unlimited | HD | Priority queue |
| Studio Max | $99 | 8,000,000 | Unlimited | HD | Dialogue mode, highest queue priority |

A monthly request value of `0` means unlimited. Paid plans can allow overage. Character and minute top-ups are priced on the plan record.

The signed-in demo is capped separately from the monthly plan. Defaults are in `.env.example` (`DEMO_GUEST_CHAR_ALLOWANCE`, `DEMO_USER_CHAR_ALLOWANCE`, and the preview rate limits).

## Reader API

Routes are under the `/api` prefix. Authenticate with the signed-in session, a per-user key from the extension page, or the legacy `LISTEN_API_KEY`. Send the key as `X-Listen-Key` or `X-API-Key`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/reader/profile` | Plan, usage, and voice catalog for the key |
| `POST` | `/api/reader/queue` | Create a generation |
| `GET` | `/api/reader/jobs` | List generations |
| `GET` | `/api/extension/config` | Public extension config. No key required |
| `POST` | `/api/stripe/webhook` | Stripe webhooks |

Queue a clip:

```bash
curl -X POST "$APP_URL/api/reader/queue" \
  -H "X-Listen-Key: $LISTEN_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "content": "مرحباً، هذا اختبار قصير.",
    "target_language": "ar",
    "voice_tier": "hd",
    "dialect": "ar-XA",
    "performance_style": "cinematic",
    "emotion": "warm",
    "speed": 0.85,
    "pitch": -2
  }'
```

`content` and `text` are interchangeable. `target_language` is `ar` or `en`. A finished job returns `audio_url`. A queued job returns HTTP 202 and a `job_id`; poll `GET /api/reader/jobs`.

Profanity filtering applies to generation text. Requesting a voice tier above the account plan returns HTTP 403.

## Chrome extension

Listen Reader lives in `public/extensions/chrome-listen-reader`. It adds **Listen Selected Text** to the right-click menu and plays audio in a side panel.

1. Sign in and open **Browsers Extension**.
2. Generate an API key and download the package.
3. In Chrome, open `chrome://extensions`, enable Developer mode, and choose **Load unpacked**.
4. Paste the API key into the extension popup.

`php artisan listen:sync-extension` writes the current `APP_URL` into the extension `config.js` before you ship a build.

## Operations

Run the scheduler in production (`php artisan schedule:work`, or a system cron entry for `php artisan schedule:run` every minute). It runs:

| Command | When |
| --- | --- |
| `listen:billing-sync` | Every 15 minutes |
| `listen:billing-enforce-grace` | Hourly |
| `listen:billing-reminders` | Daily at 09:00 |
| `plans:expire-discounts` | Every 5 minutes |
| `newsletter:send-queued` | Every minute. Sends the next few queued newsletter messages and stays inside the Titan daily and hourly caps |

Other commands:

| Command | Purpose |
| --- | --- |
| `stripe:sync-plans --all` | Create or update Stripe products and prices from plans |
| `listen:voices-validate` | Check that each language and tier has voices |
| `tts:generate-samples` | Rebuild homepage voice samples |
| `listen:lang-sync-ar` | Sync Arabic UI strings |
| `referral:reconcile-rewards` | Reconcile referral rewards |
| `referral:repair-bindings` | Repair referral bindings |
| `listen:audit-blocked` | Audit blocked accounts |
| `listen:multi-account-backfill` | Backfill multi-account blocks |

When `LISTEN_AUDIO_PROCESSING=queue`, keep a worker running:

```bash
php artisan queue:listen --tries=1 --timeout=0
```

`composer dev` already does this locally.

## Tests

```bash
composer test
```

That clears cached config and runs the PHPUnit suite in `tests/Unit` and `tests/Feature`.

## Repository layout

| Path | What it holds |
| --- | --- |
| `app/Http/Controllers` | Web, billing, demo, and reader API |
| `app/Services/Tts` | Google Cloud and Gemini providers, routing, and Arabic voice mapping |
| `app/Filament` | Admin panel |
| `app/Jobs` | Audio generation |
| `resources/views` | Landing, demo, dashboard, billing, auth, and legal pages |
| `lang/en`, `lang/ar` | Interface copy |
| `database/seeders` | Plans, voices, samples, and admin |
| `public/extensions/chrome-listen-reader` | Chrome extension source |
| `config/listen.php` | Audio processing, TTS mode, billing, and demo limits |

## Support

Product: [listenai.studio](https://listenai.studio)

Account help: help@listenai.studio

Privacy, terms, and cookies: support@listenai.studio

Listen is built by SoliCode Ltd.
