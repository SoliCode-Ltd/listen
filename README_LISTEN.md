# Listen (Laravel SaaS Voice Platform)

Arabic-first AI voice app for messages/emails/files with translation + queued TTS.

## Live URLs
- Landing: https://bot.solicode.me/listen/
- Demo Lab: https://bot.solicode.me/listen/demo
- Pricing: https://bot.solicode.me/listen/pricing
- Login: https://bot.solicode.me/listen/login
- Dashboard: https://bot.solicode.me/listen/dashboard

## Stack
- Laravel 12
- SQLite (`database/database.sqlite`)
- Queue: database driver + worker
- **Single TTS provider behind branded Soli voice presets**
- Translation fallback: Google `gtx` endpoint
- Light/Dark themes on landing, demo lab, auth, and dashboard pages

## SaaS-Ready Features
- Auth system (register/login/logout)
- Auto-provisioned Free plan on registration
- Plan enforcement:
  - `max_chars_per_request`
  - monthly minute quota
- Usage counters (`requests`, `chars`, `minutes`) by month
- Queue-backed generation with job status tracking
- Audio storage in `storage/app/public/audio`

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
APP_URL=https://bot.solicode.me/listen

# Protect API access if you expose API routes
LISTEN_API_KEY=your_generated_key_here

# Google OAuth (Login with Gmail)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://bot.solicode.me/listen/auth/google/callback

# Google Cloud TTS
# Option A (simple): API key
GOOGLE_TTS_API_KEY=
# Option B (enterprise): service-account
GOOGLE_TTS_CREDENTIALS_JSON=
GOOGLE_TTS_CREDENTIALS_PATH=/absolute/path/to/service-account.json
GOOGLE_TTS_VOICE_AR=ar-XA-Wavenet-D
GOOGLE_TTS_VOICE_EN=en-US-Wavenet-D
GOOGLE_TTS_AUDIO_ENCODING=MP3
GOOGLE_TTS_SPEAKING_RATE=1.0
GOOGLE_TTS_PITCH=0.0
GOOGLE_TTS_TIMEOUT_SECONDS=30
```

## Notes
- Other TTS providers were removed from the app code.
- Voice selection supports Arabic/English with branded male and female personas.
- Demo Lab is isolated on `/demo` with animated preview UX for polished user-facing voice testing.
- Unified responsive header/footer is shared visually across landing, demo, pricing, dashboard, and auth pages.
- API routes can still be used server-to-server when secured with `LISTEN_API_KEY`.
