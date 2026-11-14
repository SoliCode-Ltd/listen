<?php
// WRITES ON LIVE. Re-makes the designed voices listed below on a free speech key,
// from each one's own saved prompt, and points the member's voice at the new one.
// The original stays on its first key (a hidden backup row keeps it known, so the
// nightly cleanup leaves it alone) and can be put back. Safe to run twice: a voice
// that has already moved is skipped.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = [43, 50];
$from = 'GEMINI_TTS_API_KEY';
$allowed = ['GEMINI_TTS_API_KEY_2', 'GEMINI_TTS_API_KEY_3'];

$model = App\Services\VoiceCloningService::MODEL;
$cloning = app(App\Services\VoiceCloningService::class);
$manager = app(App\Services\Tts\GeminiKeyManager::class);
$keys = array_values(array_filter(
    $cloning->apiKeys($model),
    fn ($k) => in_array($k['name'], $allowed, true) && ! $manager->isOutForToday((string) $k['name'], $model),
));
echo 'Now (UTC): ', now()->toDateTimeString(), "\n";
echo 'Free keys to use, freest first: ', implode(', ', array_column($keys, 'name')) ?: 'none', "\n";
if ($keys === []) {
    exit(1);
}
$endpoint = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$failed = 0;

foreach ($ids as $id) {
    $voice = App\Models\DesignedVoice::query()->find($id);
    if (! $voice || ! $voice->isSaved()) {
        echo "designed_{$id}: not a saved voice, skipped\n";

        continue;
    }
    if ($voice->key_name !== $from) {
        echo "designed_{$id}: already on {$voice->key_name}, skipped\n";

        continue;
    }
    if (blank($voice->engine_prompt)) {
        echo "designed_{$id}: no saved prompt, skipped\n";
        $failed++;

        continue;
    }

    $made = null;
    foreach ($keys as $key) {
        foreach ([true, false] as $full) {
            $body = [
                'model' => $model,
                'type' => 'prompted',
                'display_name' => App\Services\VoiceDesignService::DISPLAY_TAG.' · '.App\Services\VoiceDesignService::siteTag()
                    .' · U'.$voice->user_id.' · remake'.$id.' · '.((int) $voice->take ?: 1).' · '.now()->utc()->format('YmdHi'),
                'prompted' => ['input' => $voice->engine_prompt],
            ];
            if ($full) {
                $body['language_code'] = $voice->languageCode();
                if (in_array($voice->gender, App\Support\VoiceDesign::GENDERS, true)) {
                    $body['gender'] = $voice->gender;
                }
            }
            try {
                $response = Illuminate\Support\Facades\Http::timeout(90)->connectTimeout(20)
                    ->withOptions($cloning->httpOptions())
                    ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => $key['key']])
                    ->post($endpoint.'/voices', ['store' => true, 'voice' => $body]);
            } catch (Throwable $e) {
                echo "designed_{$id}: {$key['name']} did not answer (", mb_substr($e->getMessage(), 0, 160), ")\n";

                break;
            }
            $newId = $response->successful() ? $cloning->findVoiceId($response->json() ?? []) : null;
            if ($newId !== null) {
                $made = ['id' => $newId, 'key' => (string) $key['name']];

                break 2;
            }
            echo "designed_{$id}: {$key['name']} answered HTTP {$response->status()}: ",
                mb_substr(preg_replace('/\s+/u', ' ', (string) $response->body()), 0, 300), "\n";
            if (! ($response->status() === 400 && preg_match('/language|gender|INVALID_ARGUMENT/i', (string) $response->body()))) {
                break;
            }
        }
    }
    if ($made === null) {
        echo "designed_{$id}: NOT re-made, left as it was\n";
        $failed++;

        continue;
    }

    $old = ['provider_voice_key' => $voice->provider_voice_key, 'key_name' => $voice->key_name];
    $backup = $voice->replicate();
    $voice->update([
        'provider_voice_key' => $made['id'],
        'key_name' => $made['key'],
        'metadata' => ((array) $voice->metadata) + ['remade' => $old + ['at' => now()->toIso8601String()]],
    ]);
    echo "designed_{$id}: now {$made['id']} on {$made['key']} (was {$old['provider_voice_key']} on {$old['key_name']})\n";

    try {
        $backup->status = App\Models\DesignedVoice::DRAFT;
        $backup->metadata = ((array) $backup->metadata) + ['backup_of' => $id, 'backup_at' => now()->toIso8601String()];
        $backup->save();
        $backup->delete();
        echo "designed_{$id}: original kept as hidden backup row #{$backup->id}\n";
    } catch (Throwable $e) {
        echo "designed_{$id}: backup row NOT written (", mb_substr($e->getMessage(), 0, 200), ")\n";
    }
}

App\Services\Tts\GeminiKeyManager::forgetDesignedVoiceCounts();
exit($failed > 0 ? 1 : 0);
