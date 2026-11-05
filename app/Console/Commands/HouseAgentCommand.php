<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Agents\AgentVoices;
use App\Support\Agents\HouseAgent;
use Illuminate\Console\Command;

/**
 * Listen's own agents: one Arabic (Egyptian), one English. Running it again
 * updates what they know (it follows the plans) and keeps what the owner chose
 * in the dashboard (the voice, dialect, a pause, the name and words), and it
 * never makes a second pair. Their call buttons sit on Listen's public pages, opposite the
 * WhatsApp button, and their calls are on Listen (HouseAgent).
 */
class HouseAgentCommand extends Command
{
    protected $signature = 'agents:house {--owner= : the admin who owns them (email); the first admin otherwise} {--off : take them off the pages}';

    protected $description = 'Set up (or update) Listen\'s own voice agents for its public pages';

    public function handle(): int
    {
        if ($this->option('off')) {
            SiteSetting::set(HouseAgent::SETTING, '', 'agents');
            $this->info('Listen\'s agents are off the pages.');

            return self::SUCCESS;
        }

        $owner = $this->option('owner')
            ? User::where('email', (string) $this->option('owner'))->first()
            : User::where('is_admin', true)->orderBy('id')->first();
        if (! $owner) {
            $this->error('No admin to own Listen\'s agents.');

            return self::FAILURE;
        }

        $saved = json_decode((string) SiteSetting::get(HouseAgent::SETTING, ''), true) ?: [];
        $out = [];
        foreach (['ar', 'en'] as $language) {
            $agent = isset($saved[$language]['agent']) ? Agent::withTrashed()->where('public_id', $saved[$language]['agent'])->first() : null;
            if (! $agent || (int) $agent->user_id !== (int) $owner->id) {
                $agent = new Agent(['user_id' => $owner->id]);
            }
            if ($agent->trashed()) {
                $agent->restore();
            }

            $ar = $language === 'ar';
            $existing = $agent->exists;
            // The voice the owner picked stays; the first time (or when it is gone), a woman's voice.
            $voice = $existing && $agent->speaker_id ? AgentVoices::find((string) $agent->speaker_id, $language, $ar) : null;
            $voice = $voice ?: $this->voice($language);
            // Its name and words are the owner's once changed; until then they are Listen's, for its voice.
            $ownWords = $existing && ! HouseAgent::textsUntouched($agent);
            $agent->fill([
                'business_name' => $ar ? 'إسمع' : 'Listen',
                'business_type' => 'services',
                'jobs' => ['answer', 'leads', 'support'],
                'website_url' => url('/'),
                'language' => $language,
                'dialect' => $existing && $agent->dialect ? $agent->dialect : ($ar ? 'ar-EG' : null),
                'speaker_id' => $voice['id'] ?? null,
                'live_voice' => $voice['voice'] ?? 'Achernar',
                'knowledge' => HouseAgent::knowledge($language),
                // Paused by the owner, it stays paused.
                'status' => $existing && $agent->status === Agent::PAUSED ? Agent::PAUSED : Agent::LIVE,
                'max_call_seconds' => HouseAgent::MAX_SECONDS,
                'settings' => array_merge($agent->settings ?? [], ['house' => $language]),
            ]);
            if (! $ownWords) {
                HouseAgent::applyTexts($agent, $voice);
            }
            if (! $agent->went_live_at) {
                $agent->went_live_at = now();
            }
            $agent->save();

            $widget = $agent->mainWidget();
            $widget->forceFill([
                // Opposite the WhatsApp button, and only on Listen's own website.
                'appearance' => array_merge($widget->appearance ?? [], ['position' => 'start']),
                'allowed_origins' => array_values(array_filter([\App\Models\AgentWidget::normalizeOrigin(url('/'))])),
                'enabled' => true,
                'link_enabled' => true,
            ])->save();

            $out[$language] = ['agent' => $agent->public_id, 'key' => $widget->public_key, 'slug' => $widget->slug];
            $this->line(sprintf('%s: %s (%s), voice %s, %d characters of knowledge', $language, $agent->public_id, $widget->slug, $voice['name'] ?? $agent->live_voice, mb_strlen((string) $agent->knowledge)));
        }

        SiteSetting::set(HouseAgent::SETTING, json_encode($out), 'agents');
        $this->info('Listen\'s agents are on the public pages.');

        return self::SUCCESS;
    }

    /** A woman's voice: Egyptian for the Arabic agent, the first English one otherwise. */
    private function voice(string $language): ?array
    {
        $voices = AgentVoices::all($language, $language === 'ar');
        foreach ($voices as $v) {
            if ($v['gender'] === 'female' && ($language !== 'ar' || str_starts_with((string) $v['dialect'], 'ar-EG'))) {
                return $v;
            }
        }

        return $voices[0] ?? null;
    }
}
