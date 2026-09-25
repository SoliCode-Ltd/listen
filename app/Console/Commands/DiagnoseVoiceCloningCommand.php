<?php

namespace App\Console\Commands;

use App\Jobs\ProcessVoiceCloningJob;
use App\Models\ClonedVoice;
use App\Services\VoiceCloningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DiagnoseVoiceCloningCommand extends Command
{
    protected $signature = 'voice-cloning:diagnose
        {voice? : Cloned voice id to send to Gemini again}
        {--no-send : Only run the environment checks}';

    protected $description = 'Check the server setup for Gemini voice cloning and show the raw Gemini reply.';

    public function handle(VoiceCloningService $service): int
    {
        $this->line('PHP '.PHP_VERSION.' ('.PHP_SAPI.')');
        $this->line('memory_limit='.ini_get('memory_limit').'  max_execution_time='.ini_get('max_execution_time'));
        $this->line('post_max_size='.ini_get('post_max_size').'  upload_max_filesize='.ini_get('upload_max_filesize'));
        $this->line('Note: PHP-FPM pool settings can differ from the CLI.');

        $keys = $service->configuredKeyCount();
        $keys > 0
            ? $this->info("Gemini API keys found: {$keys}")
            : $this->error('No Gemini API key found. Set GEMINI_TTS_API_KEY, then run php artisan config:clear.');

        $proxy = trim((string) config('services.gemini_tts.voice_proxy', ''));
        $this->line('Voice proxy: '.($proxy !== '' ? 'on' : 'off'));
        $this->line('Voice cloning enabled: '.(VoiceCloningService::isEnabled() ? 'yes' : 'no'));

        $this->newLine();
        $this->line('Checking the Gemini Voices API…');
        $probe = $service->probeVoicesApi();
        $probe['ok']
            ? $this->info('Voices API reachable (HTTP '.$probe['status'].').')
            : $this->error('Voices API failed (HTTP '.($probe['status'] ?? 'none').'): '.$probe['body']);

        $voiceId = $this->argument('voice');
        if ($voiceId === null || $this->option('no-send')) {
            return $probe['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $voice = ClonedVoice::find((int) $voiceId);
        if (! $voice) {
            $this->error("Cloned voice {$voiceId} not found.");

            return self::FAILURE;
        }

        foreach (['source_audio_path' => 'Sample', 'consent_audio_path' => 'Consent'] as $field => $label) {
            $path = (string) $voice->{$field};
            $exists = $path !== '' && Storage::disk('local')->exists($path);
            $head = $exists ? substr((string) Storage::disk('local')->get($path), 0, 12) : '';
            $format = str_starts_with($head, 'RIFF') ? 'WAV' : ($exists ? 'not WAV' : 'missing');
            $size = $exists ? Storage::disk('local')->size($path) : 0;
            $this->line("{$label}: {$path} [{$format}, {$size} bytes]");
        }

        $this->newLine();
        $this->line("Sending voice {$voice->id} to Gemini…");
        $voice->update(['status' => 'pending', 'failure_reason' => null]);
        (new ProcessVoiceCloningJob($voice->id))->handle($service);
        $voice->refresh();

        if ($voice->isReady()) {
            $this->info("Ready. Gemini voice id: {$voice->provider_voice_key}");

            return self::SUCCESS;
        }

        $this->error('Failed ('.data_get($voice->metadata, 'failure_code').'), HTTP '.(data_get($voice->metadata, 'http_status') ?? 'none'));
        $this->line((string) $voice->failure_reason);
        $raw = (string) data_get($voice->metadata, 'provider_response', '');
        if ($raw !== '') {
            $this->newLine();
            $this->line('Raw Gemini reply:');
            $this->line($raw);
        }

        return self::FAILURE;
    }
}
