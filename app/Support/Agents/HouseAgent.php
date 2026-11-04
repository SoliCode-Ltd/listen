<?php

namespace App\Support\Agents;

use App\Models\Agent;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Support\DialectCounts;
use App\Support\StudioFacts;

/**
 * Listen's own agents (one Arabic, one English): they answer visitors on
 * Listen's public pages and are the live demo of Agents. They belong to an
 * admin, their calls are on Listen (no member's credits), and what they know
 * is built here from the plans and the studio's own counts, so it stays true.
 * Set up with `php artisan agents:house`.
 */
final class HouseAgent
{
    public const SETTING = 'agents_house';

    /** The longest call with a house agent. */
    public const MAX_SECONDS = 180;

    /** @return array{key: string, slug: string, agent: string}|null */
    public static function for(string $locale): ?array
    {
        $all = json_decode((string) SiteSetting::get(self::SETTING, ''), true);
        $row = is_array($all) ? ($all[$locale === 'ar' ? 'ar' : 'en'] ?? null) : null;

        return is_array($row) && ! empty($row['key']) && ! empty($row['slug']) ? $row : null;
    }

    /** A count in Arabic: one, two, a few (3 to 10) and many take different words. */
    private static function arabicCount(int $n, string $one, string $two, string $few, string $many): string
    {
        return match (true) {
            $n === 1 => $one,
            $n === 2 => $two,
            $n >= 3 && $n <= 10 => $n.' '.$few,
            default => number_format($n).' '.$many,
        };
    }

    /** What the house agent knows, in its language. */
    public static function knowledge(string $language): string
    {
        $ar = $language === 'ar';
        $plans = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->get();
        $rate = AgentAccess::creditsPerMinute();
        $planLines = $plans->map(function (Plan $p) use ($ar, $rate) {
            $minutes = (int) floor((int) $p->monthly_chars / 900);
            $price = rtrim(rtrim(number_format($p->price_cents / 100, 2, '.', ''), '0'), '.');

            // The plan's name as the Arabic pricing page writes it.
            $key = 'ui.pricing.plan_name_'.$p->slug;
            $arName = trans($key, [], 'ar') !== $key ? trans($key, [], 'ar') : $p->name;

            // Whether the plan has agents (Admin → Plans → Voice agents), how many, and up to how many call minutes.
            $limits = AgentAccess::limits($p);
            $callMinutes = intdiv(max(0, (int) $p->monthly_chars), max(1, $rate));
            if ($limits['minutes'] > 0) {
                $callMinutes = min($callMinutes, $limits['minutes']);
            }
            $agents = $ar
                ? ($limits['included'] ? '، وتشمل الوكلاء: '.self::arabicCount($limits['agents'], 'وكيل واحد', 'وكيلان', 'وكلاء', 'وكيلًا').' وحتى '.number_format($callMinutes).' دقيقة مكالمات في الشهر' : '، ولا تشمل الوكلاء')
                : ($limits['included'] ? ", with agents: {$limits['agents']} ".($limits['agents'] === 1 ? 'agent' : 'agents').' and up to '.number_format($callMinutes).' call minutes a month' : ', without agents');

            return $ar
                ? "- باقة {$arName}: {$price} دولار في الشهر، حوالي {$minutes} دقيقة صوت في الشهر (".number_format((int) $p->monthly_chars)." نقطة رصيد){$agents}."
                : "- {$p->name} plan: \${$price} a month, about {$minutes} minutes of voice a month (".number_format((int) $p->monthly_chars)." credits){$agents}.";
        })->implode("\n");
        $free = intdiv(AgentAccess::freeTestSeconds(), 60);
        $freeAr = $free > 0 ? '، وأول '.self::arabicCount($free, 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة').' من مكالمات التجربة مجانًا' : '';
        $freeEn = $free > 0 ? ", and the first {$free} ".($free === 1 ? 'minute' : 'minutes').' of test calls are free' : '';
        $rateText = number_format($rate);

        $dialects = DialectCounts::arabic();
        $accents = DialectCounts::english();
        $voices = StudioFacts::voices();
        $support = trim((string) SiteSetting::get('whatsapp_number', ''));
        $dubbing = StudioFacts::dubbing();

        $dubbingAr = $dubbing ? '، ودبلجة الفيديو' : '';
        $dubbingEn = $dubbing ? ', video dubbing' : '';

        if ($ar) {
            return trim(<<<TXT
# إسمع
إسمع منصة عربية للصوت بالذكاء الاصطناعي، تعمل بالعربية والإنجليزية.

# الاستوديو (تحويل النص إلى صوت)
- {$voices} صوتًا، بأكثر من {$dialects} لهجة عربية وأكثر من {$accents} لكنة إنجليزية.
- مشاعر وأساليب أداء، وأماكن صوتية (مثل الراديو والهاتف والكهف)، وحوار بين أكثر من صوت.
- استنساخ الصوت وتصميم صوت من وصف{$dubbingAr}، وإضافة للمتصفح، وواجهة برمجية للمطورين.
- الملفات بصيغة MP3، وفي الباقات الأعلى WAV وM4A وOGG.

# الوكلاء
- وكيل صوتي للنشاط التجاري يرد على العملاء بلهجتهم في موقعه أو عبر رابط أو رمز استجابة سريعة، ليلًا ونهارًا.
- يجيب من أسعار النشاط ومواعيده، ويأخذ الاسم ورقم الهاتف، ويساعد في الحجز.
- يُنشأ في دقيقتين من صفحة الوكلاء{$freeAr}.
- إضافة جاهزة للمواقع، أو سطر واحد يُلصق في الموقع مرة واحدة.
- المكالمات تستهلك رصيد الباقة نفسه: دقيقة المكالمة {$rateText} نقطة رصيد. الوكلاء في الباقات المذكورة أدناه فقط.

# الباقات
{$planLines}
- يمكن الترقية أو الإلغاء في أي وقت من صفحة الباقات.
- الدفع بالبطاقة أو Apple Pay أو Google Pay، وفي مصر عبر InstaPay أو فودافون كاش.

# التواصل
- البريد: help@listenai.studio
TXT).($support !== '' ? "\n- واتساب: {$support}" : '');
        }

        return trim(<<<TXT
# Listen
Listen is an Arabic-first AI voice platform, in Arabic and English.

# The studio (text to speech)
- {$voices} voices, in more than {$dialects} Arabic dialects and more than {$accents} English accents.
- Emotions and performance styles, sound places (such as radio, phone and cave), and dialogue between several voices.
- Voice cloning, designing a voice from a description{$dubbingEn}, a browser extension and a developer API.
- Files as MP3, and on higher plans WAV, M4A and OGG.

# Agents
- A voice agent for a business: it answers its customers in their dialect, on its website, a link or a QR code, day and night.
- It answers from the business's prices and hours, takes names and phone numbers, and helps book visits.
- It is set up in two minutes from the Agents page{$freeEn}.
- A ready plugin for websites, or one line pasted into the website once.
- Calls use the plan's own credits: a call minute is {$rateText} credits. Agents come with the plans listed below that include them.

# Plans
{$planLines}
- Members can move up or cancel any time from the pricing page.
- Payment by card, Apple Pay or Google Pay, and in Egypt by InstaPay or Vodafone Cash.

# Contact
- Email: help@listenai.studio
TXT).($support !== '' ? "\n- WhatsApp: {$support}" : '');
    }

    /**
     * Its name and words for its voice: Sara with a woman's voice, the speaker's own name (and
     * words for a man) with a man's.
     *
     * @param  array{name?: string, gender?: string}|null  $voice
     * @return array{name: string, first_message: string, script: array<string, mixed>}
     */
    public static function texts(string $language, ?array $voice): array
    {
        $ar = $language !== 'en';
        $female = ($voice['gender'] ?? 'female') === 'female';
        $name = $female ? ($ar ? 'سارة' : 'Sara') : (trim((string) ($voice['name'] ?? '')) ?: ($ar ? 'وليد' : 'Sam'));

        if ($ar) {
            return [
                'name' => $name,
                'first_message' => "أهلًا بيك في إسمع، معاك {$name}. تحب تعرف إيه عن الأصوات أو الوكلاء أو الباقات؟",
                'script' => [
                    'identity' => $female
                        ? "{$name} مساعدة إسمع الصوتية: ودودة وواضحة ومختصرة، وتتكلم مصري."
                        : "{$name} مساعد إسمع الصوتي: ودود وواضح ومختصر، ويتكلم مصري.",
                    'never' => [
                        'لا تذكر أبدًا الشركات أو التقنيات التي يعمل بها إسمع، ولا أي شركة أخرى.',
                        'لا تعد بخصم أو بميزة غير موجودة في المعلومات.',
                        'لا تطلب أبدًا أرقام البطاقات أو كلمات المرور.',
                        'لا تتحدث أبدًا عن تكاليف إسمع أو أرباحه أو مورّديه أو أي شأن داخلي، ولا تعرض أن يتواصل الفريق بشأنها: قل بلطف إنها ليست مما تشاركه، وارجع إلى ما يحتاجه المتصل.',
                    ],
                    'steps' => [
                        'رحّب بالمتصل واسأله عما يحتاجه.',
                        'أجب من المعلومات فقط، وباختصار.',
                        'رشّح الباقة المناسبة لعدد الدقائق الذي يحتاجه.',
                        'لمشاكل الحساب أو الدفع أو الاسترداد خذ الاسم ورقم الهاتف أو البريد ليتواصل الفريق.',
                    ],
                    'handoff' => 'عندما يحتاج المتصل مساعدة في حسابه أو دفعه، أو يطلب التحدث مع شخص.',
                ],
            ];
        }

        return [
            'name' => $name,
            'first_message' => "Hi, this is {$name} from Listen. Would you like to hear about our voices, our agents or our plans?",
            'script' => [
                'identity' => "{$name} is Listen's voice assistant: friendly, clear and brief.",
                'never' => [
                    'Never name the companies or the technology behind Listen, or any other company.',
                    'Never promise a discount or a feature that is not in the facts.',
                    'Never ask for card numbers or passwords.',
                    "Never talk about Listen's costs, profits, suppliers or anything internal, and do not offer a call back about them: say kindly it is not something you share, and return to what the caller needs.",
                ],
                'steps' => [
                    'Greet the caller and ask what they need.',
                    'Answer from the facts only, briefly.',
                    'Suggest the plan that fits the minutes they need.',
                    'For account, payment or refund problems, take their name and a phone number or email for the team.',
                ],
                'handoff' => 'When the caller needs help with their account or a payment, or asks for a person.',
            ],
        ];
    }

    /** Its words as Listen last wrote them, unchanged by the owner (so they may follow its voice). */
    public static function textsUntouched(Agent $agent): bool
    {
        $saved = $agent->setting('house_texts');
        if (is_string($saved) && $saved !== '') {
            return hash_equals($saved, self::textsHash($agent));
        }
        // Set up before the words were marked: untouched while they are still the first ones.
        $first = self::texts($agent->language, null);

        return (string) $agent->name === $first['name'] && (string) $agent->first_message === $first['first_message']
            && ($agent->script ?? []) == $first['script'];
    }

    /** @param  array{name?: string, gender?: string}|null  $voice */
    public static function applyTexts(Agent $agent, ?array $voice): void
    {
        $texts = self::texts($agent->language, $voice);
        $agent->name = $texts['name'];
        $agent->first_message = $texts['first_message'];
        $agent->script = $texts['script'];
        $agent->settings = array_merge((array) ($agent->settings ?? []), [
            'house_texts' => self::textsHash($agent),
            'texts_gender' => ($voice['gender'] ?? 'female') === 'female' ? 'female' : 'male',
        ]);
    }

    private static function textsHash(Agent $agent): string
    {
        return hash('sha256', json_encode([(string) $agent->name, (string) $agent->first_message, $agent->script ?? []], JSON_UNESCAPED_UNICODE));
    }
}
