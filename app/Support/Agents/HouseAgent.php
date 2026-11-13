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
 * admin, whose credits pay for their calls when that account can (Agents →
 * Settings; never a member's credits, and a call it cannot pay for is free),
 * and what they know is built here from the plans and the studio's own counts,
 * so it stays true. Set up with `php artisan agents:house`.
 */
final class HouseAgent
{
    public const SETTING = 'agents_house';

    /** The longest call with a house agent. */
    public const MAX_SECONDS = 180;

    /**
     * The database ids of Listen's own agents, deleted ones too: set up as one, or listed in the
     * setting.
     *
     * @return list<int>
     */
    public static function agentKeys(): array
    {
        $all = json_decode((string) SiteSetting::get(self::SETTING, ''), true);
        $listed = is_array($all)
            ? array_values(array_filter(array_map(fn ($row): string => is_array($row) ? (string) ($row['agent'] ?? '') : '', $all)))
            : [];

        return Agent::withTrashed()
            ->where(fn ($q) => $q->whereNotNull('settings->house')->when($listed !== [], fn ($q) => $q->orWhereIn('public_id', $listed)))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

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

    /**
     * The offers running on Listen's plans right now, as its own agent is told them at the start
     * of every call and chat (AgentInstructions::build): each plan's offer, what it makes the
     * price, and its last day, and what paying yearly saves. Read as the conversation starts, so
     * an offer the admin sets is known at once and one that has ended is never promised. Empty
     * when nothing is on offer.
     */
    public static function offers(string $language): string
    {
        $ar = $language !== 'en';
        $locale = $ar ? 'ar' : 'en';
        // "9" or "4.50": whole dollars plain, cents always as two digits.
        $money = fn (int $cents): string => $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, '.', '');
        $plans = rescue(fn () => Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->get(), collect(), false);
        $lines = [];
        foreach ($plans as $p) {
            if (! $p->hasActiveDiscount()) {
                continue;
            }
            $percent = (int) $p->discount_percentage;
            $offer = match ($p->discountDuration()) {
                Plan::DISCOUNT_FOREVER => trans('ui.pricing.discount_offer_forever', ['percent' => $percent], $locale),
                Plan::DISCOUNT_FIRST_MONTHS => trans_choice('ui.pricing.discount_offer_first_months', (int) $p->discountMonths(), ['percent' => $percent, 'months' => (int) $p->discountMonths()], $locale),
                default => trans('ui.pricing.discount_offer_first_month', ['percent' => $percent], $locale),
            };
            $key = 'ui.pricing.plan_name_'.$p->slug;
            $name = $ar && trans($key, [], 'ar') !== $key ? trans($key, [], 'ar') : $p->name;
            $now = $money((int) $p->getDiscountedPriceCents());
            $full = $money((int) $p->price_cents);
            // The last day of the offer: one ending at midnight ends the day before.
            $until = $p->discount_ends_at?->copy()->subSecond()->locale($locale)->translatedFormat('j F Y');
            $forever = $p->discountDuration() === Plan::DISCOUNT_FOREVER;
            $lines[] = $ar
                ? "- باقة {$name}: {$offer}. السعر مع العرض {$now} دولار في الشهر".($forever ? '' : " ثم {$full} دولار في الشهر").'، على الاشتراك الشهري.'.($until ? " آخر يوم للعرض {$until}." : '')
                : "- {$p->name} plan: {$offer}. With the offer it is \${$now} a month".($forever ? '' : ", then \${$full} a month").', on monthly billing.'.($until ? " The offer's last day is {$until}." : '');
        }
        $yearly = max(0, min(90, (int) AgentAccess::setting('billing_yearly_discount_percentage', 0)));
        if ($yearly > 0 && $plans->contains(fn (Plan $p): bool => filled($p->stripe_price_yearly ?? null))) {
            $lines[] = $ar
                ? "- الدفع السنوي يوفّر {$yearly}% على السعر الشهري."
                : "- Paying yearly saves {$yearly}% on the monthly price.";
        }

        return implode("\n", $lines);
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
TXT).($support !== '' ? "\n- واتساب: {$support}" : '').self::details(true);
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
TXT).($support !== '' ? "\n- WhatsApp: {$support}" : '').self::details(false);
    }

    /** The full product sheet after the summary (HouseKnowledge), kept within what goes into a call. */
    private static function details(bool $ar): string
    {
        $details = rescue(fn () => HouseKnowledge::details($ar), '', false);

        return $details === '' ? '' : "\n\n".$details;
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
                        'لا تقل أبدًا إن إسمع لا يوفر شيئًا قبل أن تبحث عنه في كل المعلومات (مكتبة الأصوات واللهجات والمشاعر والأماكن الصوتية والباقات)، فأغلب ما يُطلب موجود، مثل أصوات الأطفال وكبار السن. وإن لم تجده فقل إنك ستتأكد، وخذ رقم المتصل أو بريده ليرد عليه الفريق.',
                        'لا تطلب أبدًا أرقام البطاقات أو كلمات المرور.',
                        'لا تتحدث أبدًا عن تكاليف إسمع أو أرباحه أو مورّديه أو أي شأن داخلي، ولا تعرض أن يتواصل الفريق بشأنها: قل بلطف إنها ليست مما تشاركه، وارجع إلى ما يحتاجه المتصل.',
                    ],
                    'steps' => [
                        'رحّب بالمتصل واسأله عما يحتاجه.',
                        'أجب من المعلومات فقط، وباختصار، واذكر أمثلة منها (مثل أسماء الأصوات أو اللهجات).',
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
                    "Never say Listen does not offer something before looking for it in all the facts (the voice library, dialects, emotions, sound places and plans): most of what callers ask for is there, such as children's and elders' voices. If it is not there, say you will check, and take their phone number or email for the team.",
                    'Never ask for card numbers or passwords.',
                    "Never talk about Listen's costs, profits, suppliers or anything internal, and do not offer a call back about them: say kindly it is not something you share, and return to what the caller needs.",
                ],
                'steps' => [
                    'Greet the caller and ask what they need.',
                    'Answer from the facts only, briefly, with examples from them (voice names, dialects).',
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
            if (hash_equals($saved, self::textsHash($agent))) {
                return true;
            }
            // Marked before the fingerprint ignored key order (MySQL keeps a JSON column's keys
            // in its own order, so those never matched once saved): untouched while they are
            // still the words Listen writes for the voice they were written for.
            $written = self::texts($agent->language, ['name' => (string) $agent->name, 'gender' => (string) ($agent->setting('texts_gender') ?: 'female')]);

            return (string) $agent->name === $written['name'] && (string) $agent->first_message === $written['first_message']
                && self::canonical($agent->script ?? []) === self::canonical($written['script']);
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

    /** The words' fingerprint, whatever order the database keeps the script's keys in. */
    private static function textsHash(Agent $agent): string
    {
        return hash('sha256', json_encode([(string) $agent->name, (string) $agent->first_message, self::canonical($agent->script ?? [])], JSON_UNESCAPED_UNICODE));
    }

    /** The same value with every keyed array's keys in one order (lists keep theirs). */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
