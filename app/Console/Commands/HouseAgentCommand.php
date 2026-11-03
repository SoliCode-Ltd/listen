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
 * updates them (what they know follows the plans), it never makes a second
 * pair. Their call buttons sit on Listen's public pages, opposite the
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

            $voice = $this->voice($language);
            $ar = $language === 'ar';
            $agent->fill([
                'name' => $ar ? 'سارة' : 'Sara',
                'business_name' => $ar ? 'إسمع' : 'Listen',
                'business_type' => 'services',
                'jobs' => ['answer', 'leads', 'support'],
                'website_url' => url('/'),
                'language' => $language,
                'dialect' => $ar ? 'ar-EG' : null,
                'speaker_id' => $voice['id'] ?? null,
                'live_voice' => $voice['voice'] ?? 'Achernar',
                'first_message' => $ar
                    ? 'أهلًا بيك في إسمع، معاك سارة. تحب تعرف إيه عن الأصوات أو الوكلاء أو الباقات؟'
                    : 'Hi, this is Sara from Listen. Would you like to hear about our voices, our agents or our plans?',
                'script' => $ar ? [
                    'identity' => 'سارة مساعدة إسمع الصوتية: ودودة وواضحة ومختصرة، وتتكلم مصري.',
                    'never' => [
                        'لا تذكر أبدًا الشركات أو التقنيات التي يعمل بها إسمع، ولا أي شركة أخرى.',
                        'لا تعد بخصم أو بميزة غير موجودة في المعلومات.',
                        'لا تطلب أبدًا أرقام البطاقات أو كلمات المرور.',
                    ],
                    'steps' => [
                        'رحّب بالمتصل واسأله عما يحتاجه.',
                        'أجب من المعلومات فقط، وباختصار.',
                        'رشّح الباقة المناسبة لعدد الدقائق الذي يحتاجه.',
                        'لمشاكل الحساب أو الدفع أو الاسترداد خذ الاسم ورقم الهاتف أو البريد ليتواصل الفريق.',
                    ],
                    'handoff' => 'عندما يحتاج المتصل مساعدة في حسابه أو دفعه، أو يطلب التحدث مع شخص.',
                ] : [
                    'identity' => 'Sara is Listen\'s voice assistant: friendly, clear and brief.',
                    'never' => [
                        'Never name the companies or the technology behind Listen, or any other company.',
                        'Never promise a discount or a feature that is not in the facts.',
                        'Never ask for card numbers or passwords.',
                    ],
                    'steps' => [
                        'Greet the caller and ask what they need.',
                        'Answer from the facts only, briefly.',
                        'Suggest the plan that fits the minutes they need.',
                        'For account, payment or refund problems, take their name and a phone number or email for the team.',
                    ],
                    'handoff' => 'When the caller needs help with their account or a payment, or asks for a person.',
                ],
                'knowledge' => HouseAgent::knowledge($language),
                'status' => Agent::LIVE,
                'max_call_seconds' => HouseAgent::MAX_SECONDS,
                'settings' => array_merge($agent->settings ?? [], ['house' => $language]),
            ]);
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
