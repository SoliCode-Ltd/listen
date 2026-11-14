<?php
// WRITES ON LIVE, once. The two voices moved by hand on 14 November are put under the
// site's own rule (VoiceDesignService stand-ins): each is its original voice on its own
// key again, the voice made that night becomes its stand-in on the other key, and it keeps
// speaking through the stand-in until the daily reset, as any voice whose key is out does.
// The hidden backup rows written that night are removed. Safe to run twice: a voice
// already put back is skipped.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DesignedVoice;
use App\Services\Tts\GeminiKeyManager;
use App\Services\VoiceDesignService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

echo 'Now (UTC): ', now()->toDateTimeString(), "\n";
if (! method_exists(VoiceDesignService::class, 'copiesOf')) {
    echo "The site does not have stand-ins yet (not deployed): nothing done.\n";
    exit(1);
}

$members = [];
$moved = DesignedVoice::query()->saved()->get()->filter(fn (DesignedVoice $v): bool => is_array(data_get($v->metadata, 'remade')));
if ($moved->isEmpty()) {
    echo "No voice is waiting to be put back.\n";
}
foreach ($moved as $voice) {
    $remade = (array) data_get($voice->metadata, 'remade');
    $ownId = (string) ($remade['provider_voice_key'] ?? '');
    $ownKey = (string) ($remade['key_name'] ?? '');
    if ($ownId === '' || $ownKey === '') {
        echo "designed_{$voice->id}: its original is not recorded, left as it is\n";

        continue;
    }
    $standInId = (string) $voice->provider_voice_key;
    $standInKey = (string) $voice->key_name;

    DB::transaction(function () use ($voice, $ownId, $ownKey, $standInId, $standInKey): void {
        // The backup row holds the original's id: it goes first, so the id is held by one row only.
        $backups = DesignedVoice::onlyTrashed()->where('user_id', $voice->user_id)->where('provider_voice_key', $ownId)->get()
            ->filter(fn (DesignedVoice $row): bool => (int) data_get($row->metadata, 'backup_of') === (int) $voice->id);
        foreach ($backups as $backup) {
            // The row only: its audio file is the voice's own preview, which stays.
            DesignedVoice::withTrashed()->whereKey($backup->id)->forceDelete();
            echo "designed_{$voice->id}: backup row #{$backup->id} removed\n";
        }
        $metadata = (array) $voice->metadata;
        unset($metadata['remade']);
        $metadata['copies'] = [$standInKey => $standInId] + (array) ($metadata['copies'] ?? []);
        $voice->forceFill(['provider_voice_key' => $ownId, 'key_name' => $ownKey, 'metadata' => $metadata])->saveQuietly();
    });
    Cache::put(VoiceDesignService::standInCacheKey($voice), $standInKey, GeminiKeyManager::nextDailyReset()->utc());
    $members[(int) $voice->user_id] = true;
    echo "designed_{$voice->id}: its own voice is {$ownId} on {$ownKey} again; stand-in {$standInId} on {$standInKey}, in use until ",
        GeminiKeyManager::nextDailyReset()->utc()->toDateTimeString(), " UTC\n";
}
GeminiKeyManager::forgetDesignedVoiceCounts();

foreach (array_keys($members) as $userId) {
    echo "member {$userId}: voices in the library ", App\Support\VoiceDesign::savedFor($userId)->count(),
        ', kept in total ', App\Support\VoiceDesign::keptInTotal($userId), "\n";
}

echo "\n== Saved designed voices by the key that holds them ==\n";
foreach (DesignedVoice::query()->saved()->selectRaw('key_name, count(*) as held')->groupBy('key_name')->orderBy('key_name')->get() as $row) {
    echo "  {$row->key_name}: {$row->held}\n";
}
echo 'Voices with a stand-in: ', DesignedVoice::query()->saved()->get()->filter(fn (DesignedVoice $v): bool => VoiceDesignService::copiesOf($v) !== [])->count(), "\n";
echo 'Hidden backup rows left: ', DesignedVoice::onlyTrashed()->get()->filter(fn (DesignedVoice $v): bool => data_get($v->metadata, 'backup_of') !== null)->count(), "\n";
