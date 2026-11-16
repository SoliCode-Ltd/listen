<?php

namespace App\Console\Commands;

use App\Models\Voice;
use Illuminate\Console\Command;

class ValidateVoiceCatalogCommand extends Command
{
    protected $signature = 'listen:voices-validate {--strict : Fail when a tier has no voices for a language}';

    protected $description = 'Validate voice catalog mapping, uniqueness, and tier-engine consistency.';

    public function handle(): int
    {
        $voices = Voice::query()
            ->where('is_active', true)
            ->get(['language', 'gender', 'tier', 'display_name', 'internal_voice_id']);

        $errors = [];

        $byVoiceId = [];
        $byDisplayName = [];
        $expectedTokenByTier = [
            'standard' => ['-standard-', '-wavenet-'],
            'hd' => ['-chirp3-hd-'],
        ];

        foreach ($voices as $voice) {
            $language = strtolower((string) $voice->language);
            $tier = strtolower((string) $voice->tier);
            $display = trim((string) $voice->display_name);
            $voiceId = strtolower(trim((string) $voice->internal_voice_id));

            if ($voiceId === '' || $display === '') {
                $errors[] = 'Voice catalog contains empty display name or internal id.';
                continue;
            }

            if (isset($byVoiceId[$voiceId])) {
                $errors[] = "Duplicate internal voice id: {$voiceId}";
            }
            $byVoiceId[$voiceId] = true;

            $displayKey = $language.'|'.strtolower($display);
            if (isset($byDisplayName[$displayKey])) {
                $errors[] = "Duplicate display name for language {$language}: {$display}";
            }
            $byDisplayName[$displayKey] = true;

            if (! str_starts_with($voiceId, $language.'-')) {
                $errors[] = "Language mismatch for voice id {$voiceId} (language={$language})";
            }

            $expectedToken = $expectedTokenByTier[$tier] ?? null;
            if (! $expectedToken) {
                $errors[] = "Unknown tier found in voices table: {$tier}";
            } else {
                $matchesEngine = false;
                foreach ((array) $expectedToken as $token) {
                    if (str_contains($voiceId, $token)) {
                        $matchesEngine = true;
                        break;
                    }
                }
                if (! $matchesEngine) {
                    $errors[] = "Tier/engine mismatch: {$voiceId} does not match tier {$tier}";
                }
            }
        }

        if ($this->option('strict')) {
            $required = [
                ['language' => 'ar', 'tier' => 'standard'],
                ['language' => 'en', 'tier' => 'standard'],
                ['language' => 'ar', 'tier' => 'hd'],
                ['language' => 'en', 'tier' => 'hd'],
            ];

            foreach ($required as $pair) {
                $exists = $voices
                    ->where('language', $pair['language'])
                    ->where('tier', $pair['tier'])
                    ->isNotEmpty();

                if (! $exists) {
                    $errors[] = "Missing active voices for language={$pair['language']} tier={$pair['tier']}";
                }
            }
        }

        if (! empty($errors)) {
            $this->error('Voice catalog validation failed:');
            foreach ($errors as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        $this->info('Voice catalog validation passed.');
        return self::SUCCESS;
    }
}
