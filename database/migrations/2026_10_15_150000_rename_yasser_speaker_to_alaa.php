<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner renamed the Zubenelgenubi speaker from Yasser / ياسر to Alaa / علاء:
 * the studio voice and its homepage sample (Sunset on the Nile). The name is
 * never spoken in any homepage audio, so no clip changes.
 */
return new class extends Migration
{
    private const SAMPLE = 'audio/showcase/ar-sd-nile.mp3';

    public function up(): void
    {
        $this->rename('Yasser', 'Alaa', ['Yasser' => 'Alaa', 'ياسر' => 'علاء']);
    }

    public function down(): void
    {
        $this->rename('Alaa', 'Yasser', ['Alaa' => 'Yasser', 'علاء' => 'ياسر']);
    }

    /**
     * @param  array<string, string>  $names
     */
    private function rename(string $from, string $to, array $names): void
    {
        if (Schema::hasTable('voices')) {
            DB::table('voices')->where('display_name', $from)->where('provider_voice_id', 'Zubenelgenubi')->update(['display_name' => $to]);
        }
        if (! Schema::hasTable('voice_samples')) {
            return;
        }
        foreach (DB::table('voice_samples')->where('audio_path', self::SAMPLE)->get() as $row) {
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
