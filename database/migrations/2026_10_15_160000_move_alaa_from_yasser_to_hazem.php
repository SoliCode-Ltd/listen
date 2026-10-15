<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner moved the name Alaa / علاء: Yasser / ياسر (Zubenelgenubi) gets
 * his name back, and Hazem / حازم (Schedar) becomes Alaa / علاء. In the studio
 * and in their homepage samples. No homepage audio says either name.
 */
return new class extends Migration
{
    private const YASSER_SAMPLES = ['audio/showcase/ar-sd-nile.mp3'];

    private const HAZEM_SAMPLES = ['audio/showcase/ar-eg-saidi-wisdom.mp3', 'audio/showcase/ar-mr-hassaniya-tea.mp3'];

    public function up(): void
    {
        $this->voice('Zubenelgenubi', 'Alaa', 'Yasser');
        $this->samples(self::YASSER_SAMPLES, ['Alaa' => 'Yasser', 'علاء' => 'ياسر']);
        $this->voice('Schedar', 'Hazem', 'Alaa');
        $this->samples(self::HAZEM_SAMPLES, ['Hazem' => 'Alaa', 'حازم' => 'علاء']);
    }

    public function down(): void
    {
        $this->voice('Schedar', 'Alaa', 'Hazem');
        $this->samples(self::HAZEM_SAMPLES, ['Alaa' => 'Hazem', 'علاء' => 'حازم']);
        $this->voice('Zubenelgenubi', 'Yasser', 'Alaa');
        $this->samples(self::YASSER_SAMPLES, ['Yasser' => 'Alaa', 'ياسر' => 'علاء']);
    }

    private function voice(string $providerVoice, string $from, string $to): void
    {
        if (Schema::hasTable('voices')) {
            DB::table('voices')->where('provider_voice_id', $providerVoice)->where('display_name', $from)->update(['display_name' => $to]);
        }
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<string, string>  $names
     */
    private function samples(array $paths, array $names): void
    {
        if (! Schema::hasTable('voice_samples')) {
            return;
        }
        foreach (DB::table('voice_samples')->whereIn('audio_path', $paths)->get() as $row) {
            // Arabic is stored escaped in the JSON, so it is decoded rather than replaced as text.
            $data = json_decode((string) $row->metadata, true);
            if (! is_array($data)) {
                continue;
            }
            array_walk_recursive($data, function (&$value) use ($names): void {
                if (is_string($value)) {
                    $value = strtr($value, $names);
                }
            });
            DB::table('voice_samples')->where('id', $row->id)->update(['metadata' => json_encode($data)]);
        }
    }
};
