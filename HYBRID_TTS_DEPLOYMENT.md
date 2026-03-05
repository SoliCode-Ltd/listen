# Hybrid TTS Deployment Checklist

> **Feature**: Hybrid TTS Architecture (Google Cloud + Gemini 2.5 Flash Preview TTS)  
> **Date**: 2025-06  
> **Backward Compatible**: Yes – defaults to `legacy` mode (Google Cloud only)

---

## 1. Environment Variables

Add to `.env` (production & staging):

```env
# Gemini TTS (only needed when hybrid mode is enabled)
GEMINI_TTS_API_KEY=your-gemini-api-key-here
GEMINI_TTS_ENDPOINT=https://generativelanguage.googleapis.com/v1beta
GEMINI_TTS_TIMEOUT=60
GEMINI_TTS_VERIFY_SSL=true
GEMINI_TTS_DEFAULT_VOICE=Kore
GEMINI_TTS_COST_PER_MILLION_CHARS=0
```

> **Note**: The system works in `legacy` mode without the Gemini key. Only set it when ready to enable hybrid.

---

## 2. Database Migrations

Run in order (they are timestamped):

```bash
php artisan migrate
```

This applies 5 new migrations:
| Migration | Description |
|-----------|-------------|
| `2026_03_03_000100` | Add TTS columns to `plans` table |
| `2026_03_03_000200` | Add telemetry/studio columns to `audio_generations` |
| `2026_03_03_000300` | Add provider/studio columns to `voices` |
| `2026_03_03_000400` | Create `voice_samples` table |
| `2026_03_03_000500` | Create `tts_logs` table |

**Rollback**: All migrations have `down()` methods.

```bash
# Roll back all 5 hybrid TTS migrations
php artisan migrate:rollback --step=5
```

---

## 3. Seed Default Data

```bash
php artisan db:seed --class=HybridTtsSeeder
```

This seeds:
- 7 TTS-related `site_settings` (mode=legacy, fallback=enabled, default voice, timeout, guardrails)
- 14 Gemini voices into the `voices` table (10 English + 4 Arabic-capable)

---

## 4. Clear Caches

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan cache:clear
```

---

## 5. Admin Configuration (Post-Deploy)

1. Navigate to **Admin Panel → TTS Settings** (`/admin/tts-settings`)
2. Review default settings (mode should be `legacy`)
3. When ready to enable hybrid TTS:
   - Set **Global Mode** to `hybrid`
   - Verify Gemini API key is connected (shown in settings page)
   - Set per-plan provider overrides if needed (default: `auto`)
   - Configure cost guardrails

---

## 6. Verify

### Quick Smoke Tests

```bash
# Run all TTS-related tests
php vendor/bin/phpunit --filter="Tts|ArabicVoice|PlanTts|Gemini" --testdox

# Expected: 46 tests, 0 failures
```

### Manual Verification

1. **Legacy mode** (default): Generate audio from dashboard → should use Google Cloud TTS (check `tts_logs` table)
2. **Hybrid mode**: Toggle mode to `hybrid` in admin → free user should still use Google Cloud → paid user should use Gemini
3. **Fallback**: If Gemini fails, it should automatically fall back to Google Cloud
4. **Voice Studio**: Dashboard should show emotion/dialect/speed/pitch controls
5. **Admin Panel**: Check TTS Settings page, Voice Samples resource, TTS Logs

---

## 7. New Files Created

### Services
- `app/Contracts/TtsProviderInterface.php`
- `app/Services/Tts/GoogleCloudTtsProvider.php`
- `app/Services/Tts/GeminiTtsProvider.php`
- `app/Services/Tts/TtsRoutingService.php`
- `app/Services/Tts/ArabicVoiceMapper.php`

### Models
- `app/Models/VoiceSample.php`
- `app/Models/TtsLog.php`

### Filament Admin
- `app/Filament/Pages/TtsSettings.php`
- `app/Filament/Resources/VoiceSampleResource.php` (+ 3 pages)
- `app/Filament/Resources/TtsLogResource.php` (+ 2 pages)
- `app/Filament/Widgets/TtsProviderChart.php`
- `resources/views/filament/pages/tts-settings.blade.php`

### Migrations & Seeders
- 5 migrations in `database/migrations/`
- `database/seeders/HybridTtsSeeder.php`

### Tests
- `tests/Unit/ArabicVoiceMapperTest.php` (18 tests)
- `tests/Unit/TtsRoutingServiceTest.php` (11 tests)
- `tests/Unit/GeminiTtsProviderTest.php` (5 tests)
- `tests/Feature/TtsRoutingFeatureTest.php` (12 tests)
- `tests/Feature/PlanTtsFieldsTest.php` (3 tests)

### Modified Files
- `app/Services/TtsService.php` – added `synthesizeAdvanced()`
- `app/Jobs/GenerateAudioJob.php` – plan-aware routing + telemetry
- `app/Models/AudioGeneration.php` – new fillable/cast fields
- `app/Models/Plan.php` – TTS policy fields
- `app/Models/Voice.php` – provider/dialect/studio fields
- `app/Providers/AppServiceProvider.php` – DI bindings
- `app/Filament/Resources/PlanResource.php` – TTS section
- `app/Filament/Resources/VoiceResource.php` – studio section
- `app/Http/Controllers/DashboardController.php` – studio params
- `app/Http/Controllers/HomeController.php` – voice samples
- `app/Http/Controllers/Api/MessageReaderController.php` – studio params
- `config/listen.php` – TTS config section
- `config/services.php` – Gemini TTS config
- `resources/views/dashboard.blade.php` – voice studio controls
- `resources/views/landing.blade.php` – voice samples showcase
- `resources/views/demo.blade.php` – demo samples section

---

## 8. Rollback Plan

If issues arise after enabling hybrid mode:

1. **Immediate**: Set TTS mode back to `legacy` in Admin → TTS Settings
2. **Full rollback**: 
   ```bash
   php artisan migrate:rollback --step=5
   ```
   Then revert the code changes. The system will continue working with Google Cloud TTS only.

---

## 9. Monitoring

- **TTS Logs**: Admin Panel → TTS Logs shows all TTS requests with provider, latency, cost, fallback status
- **TTS Provider Chart**: Dashboard widget shows 30-day Google vs Gemini usage trend
- **Cost Guardrails**: Admin can set daily cost limits per provider in TTS Settings
- **Log Channel**: TTS telemetry logged to the channel configured in `config/listen.php` → `tts.telemetry_channel`
