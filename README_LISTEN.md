# Listen (Laravel SaaS Voice Platform)

Arabic-first voice app for messages/emails/files with translation and queued audio generation.

## Live URLs
- Landing: https://listenai.studio/
- Demo Lab: https://listenai.studio/demo
- Pricing: https://listenai.studio/pricing
- Login: https://listenai.studio/login
- Dashboard: https://listenai.studio/dashboard

## Stack
- Laravel 12
- SQLite (`database/database.sqlite`)
- Queue: database driver + worker
- Tiered voice quality (`Standard`, `HD`)
- Light/Dark themes on landing, demo, auth, dashboard, billing, and upgrade pages

## SaaS Features
- Auth system (register/login/logout + social login)
- Free plan auto-provisioning on registration
- Plan enforcement:
  - `max_chars_per_request`
  - monthly character quota
  - monthly request quota
  - voice quality tier lock by plan
- Usage counters (`requests`, `chars`, `minutes`) by month
- Queue-backed generation with job status tracking
- Audio storage in `storage/app/public/audio`
- Billing with on-site payment collection, invoices, and no-webhook sync polling

## Runtime Commands
```bash
cd /data/listen
php artisan migrate --seed
php artisan storage:link

# app + queue workers (systemd)
systemctl restart listen-app.service
systemctl restart listen-queue.service
systemctl status listen-app.service listen-queue.service
```

## Environment
Set in `.env`:
```env
APP_URL=https://listenai.studio

# API access
LISTEN_API_KEY=your_generated_key_here

# Social login
SOCIAL_CLIENT_ID=
SOCIAL_CLIENT_SECRET=
SOCIAL_REDIRECT_URI=https://listenai.studio/auth/social/callback

# Speech engine credentials
SPEECH_API_KEY=
SPEECH_CREDENTIALS_JSON=
SPEECH_CREDENTIALS_PATH=/absolute/path/to/service-account.json
SPEECH_VERIFY_SSL=true
SPEECH_AUDIO_ENCODING=MP3
SPEECH_SPEAKING_RATE=1.0
SPEECH_PITCH=0.0
SPEECH_TIMEOUT_SECONDS=30

# Stripe billing
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_PRICE_CREATOR_MONTHLY=
STRIPE_PRICE_CREATOR_YEARLY=
STRIPE_PRICE_PRO_MONTHLY=
STRIPE_PRICE_PRO_YEARLY=
LISTEN_SUBSCRIPTION_SYNC_TTL_MINUTES=10

LISTEN_AUDIO_PROCESSING=sync
LISTEN_AUDIO_FORCE_ASYNC_CHARS=2500
```

## Notes
- Public demo supports `Standard` and `HD` preview switching.
- Authenticated generation is tier-locked by plan: `FREE -> Standard`, paid plans -> `HD`.
- Voice labels are safe display names from DB mappings; internal voice IDs are not exposed.
- API routes can be used server-to-server when secured with `LISTEN_API_KEY`.
