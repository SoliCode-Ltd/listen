<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->unsignedInteger('user_sequence_number')
                ->nullable()
                ->after('id');

            $table->json('settings_snapshot')
                ->nullable()
                ->after('billable_chars')
                ->comment('Saved generation settings for premium reuse flow');

            $table->unique(['user_id', 'user_sequence_number'], 'audio_generations_user_sequence_unique');
        });

        $userIds = DB::table('audio_generations')
            ->select('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $sequence = 0;

            DB::table('audio_generations')
                ->select([
                    'id',
                    'voice_name',
                    'voice_tier',
                    'target_language',
                    'translate',
                    'tts_provider',
                    'dialect',
                    'performance_style',
                    'style_prompt',
                    'emotion',
                    'speed',
                    'pitch',
                    'is_dialogue',
                    'dialogue_metadata',
                    'created_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$sequence): void {
                    foreach ($rows as $row) {
                        $sequence++;

                        $voiceTier = strtolower(trim((string) ($row->voice_tier ?? 'standard')));
                        $dialogueMetadata = $row->dialogue_metadata;

                        if (is_string($dialogueMetadata) && $dialogueMetadata !== '') {
                            $decoded = json_decode($dialogueMetadata, true);
                            $dialogueMetadata = is_array($decoded) ? $decoded : null;
                        }

                        $settingsSnapshot = null;
                        if ($voiceTier === 'hd' && trim((string) ($row->voice_name ?? '')) !== '') {
                            $settingsSnapshot = array_filter([
                                'voice_name' => trim((string) $row->voice_name),
                                'voice_tier' => 'hd',
                                'target_language' => trim((string) ($row->target_language ?? '')),
                                'translate' => (bool) ($row->translate ?? false),
                                'tts_provider' => trim((string) ($row->tts_provider ?? '')) ?: null,
                                'dialect' => trim((string) ($row->dialect ?? '')) ?: null,
                                'performance_style' => trim((string) ($row->performance_style ?? '')) ?: null,
                                'style_prompt' => trim((string) ($row->style_prompt ?? '')) ?: null,
                                'emotion' => trim((string) ($row->emotion ?? '')) ?: null,
                                'speed' => $row->speed !== null ? (float) $row->speed : null,
                                'pitch' => $row->pitch !== null ? (float) $row->pitch : null,
                                'is_dialogue' => (bool) ($row->is_dialogue ?? false),
                                'dialogue_speakers' => is_array($dialogueMetadata)
                                    ? (($dialogueMetadata['speakers'] ?? null) ?: null)
                                    : null,
                            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
                        }

                        DB::table('audio_generations')
                            ->where('id', $row->id)
                            ->update([
                                'user_sequence_number' => $sequence,
                                'settings_snapshot' => $settingsSnapshot !== null
                                    ? json_encode($settingsSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                    : null,
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->dropUnique('audio_generations_user_sequence_unique');
            $table->dropColumn([
                'user_sequence_number',
                'settings_snapshot',
            ]);
        });
    }
};
