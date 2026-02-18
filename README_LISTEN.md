# Listen (Laravel App)

Arabic-first AI voice reader for emails/messages/files with translation + queued TTS.

## Live URLs
- Landing: https://bot.solicode.me/listen/
- Login: https://bot.solicode.me/listen/login
- Dashboard: https://bot.solicode.me/listen/dashboard

## Stack
- Laravel 12
- SQLite (`database/database.sqlite`)
- Queue: database driver + worker
- **Primary TTS: Chatterbox (Resemble) via local Docker microservice**
- Translation fallback: Google `gtx` endpoint
- Nginx reverse proxy (`/listen/` -> `127.0.0.1:8090`)

## Implemented
- Premium landing UI + interactive demo pipeline
- Auth system (register/login/logout)
- Dashboard command center (usage meter, queue form, jobs table)
- Playable audio in dashboard (`audio` controls)
- Queue endpoint: `POST /api/reader/queue`
- Jobs endpoint: `GET /api/reader/jobs?limit=25`
- Background job processor (`GenerateAudioJob`) for translation + TTS generation
- Database schema for:
  - source accounts
  - text items
  - audio generations
  - plans, subscriptions, usage counters
- Seeded plans: Free / Pro / Business

## Default seeded account
- Email: `test@example.com`
- Password: `password`

## Runtime commands
```bash
cd /data/listen
php artisan migrate --seed
php artisan storage:link

# app + queue workers (systemd)
systemctl restart listen-app.service
systemctl restart listen-queue.service
systemctl status listen-app.service listen-queue.service
```

## Chatterbox service
Docker container name: `listen-chatterbox`

Health check:
```bash
curl http://127.0.0.1:8123/health
```

Manual rebuild/run:
```bash
docker build -t listen-chatterbox:latest /data/listen/chatterbox-service
docker rm -f listen-chatterbox || true
docker run -d --name listen-chatterbox --restart unless-stopped \
  -p 127.0.0.1:8123:8123 \
  -v /data/listen/storage/app/public/audio:/outputs \
  -v /data/listen/chatterbox-cache:/root/.cache/huggingface \
  listen-chatterbox:latest
```

## Environment
Set in `.env`:
```env
APP_URL=https://bot.solicode.me/listen

CHATTERBOX_URL=http://127.0.0.1:8123
CHATTERBOX_TIMEOUT_SECONDS=240

# API protection for external queue/list endpoints
LISTEN_API_KEY=your_generated_key_here

# Optional OpenAI fallback (leave empty if Chatterbox-only)
OPENAI_API_KEY=
TTS_OPENAI_MODEL=gpt-4o-mini-tts
TTS_OPENAI_VOICE=alloy
```

## API usage (when `LISTEN_API_KEY` is set)
```bash
curl -X POST https://bot.solicode.me/listen/api/reader/queue \
  -H "Content-Type: application/json" \
  -H "X-Listen-Key: <LISTEN_API_KEY>" \
  -d '{"source_type":"email","content":"Hello","target_language":"ar","translate":true}'
```

## Recommended next steps
1. Replace database queue with Redis + Horizon
2. Add Stripe billing + hard tier enforcement
3. Add OAuth connectors (Gmail/Telegram/Drive)
4. Add audio CDN/object storage (S3/R2)
5. Add webhook/mobile push delivery for completed jobs
