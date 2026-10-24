<?php

use App\Support\SpeakerVoices;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * One premium speaker for every Arabic dialect and English accent, each with a
 * voice of their own (database/seeders/data/speaker_voices.php):
 * - the speakers first named after the Chirp3 voices (Farah, Ella ...) become
 *   Gemini speakers, so the library shows them; their ids and names stay;
 * - every premium speaker's engine voice is set to their own voice;
 * - the new speakers (database/seeders/data/more_speakers.php) are added.
 * Run by hand, like every migration on staging and live. A database with no
 * premium speakers yet (a fresh install) is left to the VoiceSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A fresh install gets every speaker from the VoiceSeeder.
        if (! DB::table('voices')->where('tier', 'hd')->exists()) {
            return;
        }
        $now = now();
        DB::table('voices')->where('tier', 'hd')->where('internal_voice_id', 'like', '%-Chirp3-HD-%')
            ->update(['provider' => 'gemini', 'updated_at' => $now]);

        foreach ((require database_path('seeders/data/more_speakers.php'))['speakers'] as $speaker) {
            $taken = DB::table('voices')->where('internal_voice_id', $speaker['id'])
                ->orWhere(fn ($q) => $q->where('language', $speaker['language'])->where('display_name', $speaker['name']))
                ->exists();
            if (! $taken) {
                DB::table('voices')->insert([
                    'language' => $speaker['language'], 'gender' => $speaker['gender'], 'tier' => 'hd',
                    'display_name' => $speaker['name'], 'internal_voice_id' => $speaker['id'],
                    'provider' => 'gemini', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        SpeakerVoices::forget();
        foreach (SpeakerVoices::all() as $id => $speaker) {
            DB::table('voices')->whereRaw('LOWER(internal_voice_id) = ?', [$id])
                ->update(['provider_voice_id' => $speaker['voice'], 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $now = now();
        $added = array_column((require database_path('seeders/data/more_speakers.php'))['speakers'], 'id');
        DB::table('voices')->whereIn('internal_voice_id', $added)->delete();

        // The engine voice was the studio voice in the id (the Chirp3 speakers: the id itself).
        foreach (DB::table('voices')->where('tier', 'hd')->where('provider', 'gemini')->get(['id', 'internal_voice_id']) as $row) {
            $id = (string) $row->internal_voice_id;
            $chirp = str_contains($id, '-Chirp3-HD-');
            DB::table('voices')->where('id', $row->id)->update([
                'provider' => $chirp ? 'google_cloud' : 'gemini',
                'provider_voice_id' => $chirp ? $id : substr($id, strrpos($id, '-') + 1),
                'updated_at' => $now,
            ]);
        }
    }
};
