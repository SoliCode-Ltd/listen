# Listen (Laravel SaaS Voice Platform)

Arabic-first AI voice app for messages/emails/files with translation + queued TTS.

## Live URLs
- Landing: https://bot.solicode.me/listen/
- Login: https://bot.solicode.me/listen/login
- Dashboard: https://bot.solicode.me/listen/dashboard

## Stack
- Laravel 12
- SQLite (`database/database.sqlite`)
- Queue: database driver + worker
- **Single TTS provider: Google Cloud Text-to-Speech (WaveNet by default)**
- Translation fallback: Google `gtx` endpoint
- Light/Dark themes on public and auth/dashboard pages

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

# Google Cloud TTS (set one credential source)
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
- Dashboard/landing avoid exposing API integration details in UI.
- API routes can still be used server-to-server when secured with `LISTEN_API_KEY`.
