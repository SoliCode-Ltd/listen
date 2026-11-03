<?php

namespace App\Support\Agents;

use App\Models\Agent;
use App\Models\User;
use App\Services\Agents\AgentCalls;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;

/**
 * The demo's Voice agents tab: three sample businesses (a dental clinic, a restaurant, a shop),
 * each with an agent in its own dialect, to call live from the demo page.
 *
 * A member's calls use one practice agent of their own (status demo, kept deleted so it is
 * never one of their agents: not on their list, not counted against their plan), rewritten
 * for the business they pick. The calls are test calls, so they come out of the member's
 * free test seconds (the same ones their own agents' test calls use) and never their credits.
 */
final class DemoAgent
{
    public const PRESETS = ['clinic', 'restaurant', 'shop'];

    /** The shortest call worth starting (AgentCalls refuses a free test call under it). */
    public const MIN_SECONDS = 20;

    /** Who sees the tab: members Agents are open to. */
    public static function openTo(?User $user): bool
    {
        return $user !== null && AgentAccess::openTo($user);
    }

    /**
     * What the demo page needs: the businesses in the page's language and the free time left.
     *
     * @return array{presets: array<int, array<string, mixed>>, free_left: int, min_seconds: int}
     */
    public static function studio(User $user, string $locale): array
    {
        $language = $locale === 'ar' ? 'ar' : 'en';

        return [
            'presets' => array_map(fn (string $key) => self::card($key, $language), self::PRESETS),
            'free_left' => app(AgentCalls::class)->freeTestSecondsLeft((int) $user->id),
            'min_seconds' => self::MIN_SECONDS,
        ];
    }

    /** A business as its card shows it: what it is, its agent's name and dialect, what to try saying. */
    public static function card(string $key, string $language): array
    {
        $p = self::preset($key, $language);
        $arabicUi = $language === 'ar';
        $dialect = $language === 'ar'
            ? (ArabicDialects::ALL[$p['dialect']]['ar'] ?? '')
            : (EnglishAccents::ALL[$p['dialect']]['en'] ?? '');

        return [
            'key' => $key,
            'kind' => $p['kind'],
            'business' => $p['business'],
            'agent' => $p['name'],
            'gender' => $p['gender'],
            'dialect' => $dialect,
            'language' => $language,
            'try' => $p['try'],
            'arabic_ui' => $arabicUi,
        ];
    }

    /** The member's practice agent, set up as $key in $language. */
    public static function agentFor(User $user, string $key, string $language): Agent
    {
        $p = self::preset($key, $language === 'ar' ? 'ar' : 'en');
        $agent = Agent::withTrashed()->where('user_id', $user->id)->where('status', Agent::DEMO)->oldest('id')->first()
            ?? new Agent(['user_id' => $user->id, 'status' => Agent::DEMO]);

        $agent->fill([
            'name' => $p['name'],
            'business_name' => $p['business'],
            'business_type' => $p['type'],
            'jobs' => $p['jobs'],
            'website_url' => null,
            'language' => $language === 'ar' ? 'ar' : 'en',
            'dialect' => $p['dialect'],
            'speaker_id' => null,
            'live_voice' => $p['voice'],
            'first_message' => $p['first'],
            'script' => ['identity' => $p['identity'], 'steps' => $p['steps']],
            'knowledge' => $p['facts'],
            'choices' => [],
            'settings' => ['demo' => $key],
            'status' => Agent::DEMO,
            'max_call_seconds' => 180,
        ]);
        $agent->save();
        if (! $agent->trashed()) {
            $agent->delete();
        }

        return $agent;
    }

    /** The free time left as m:ss (Latin digits on both pages). */
    public static function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private static function preset(string $key, string $language): array
    {
        $all = $language === 'ar' ? self::arabic() : self::english();

        return $all[$key] ?? $all['clinic'];
    }

    /** @return array<string, array<string, mixed>> */
    private static function arabic(): array
    {
        return [
            'clinic' => [
                'kind' => 'عيادة أسنان',
                'business' => 'عيادة ابتسامة الرياض لطب الأسنان',
                'type' => 'healthcare',
                'jobs' => ['answer', 'booking', 'leads'],
                'name' => 'نورة',
                'gender' => 'female',
                'voice' => 'Sulafat',
                'dialect' => 'ar-SA',
                'first' => 'هلا والله، معك نورة من عيادة ابتسامة الرياض لطب الأسنان. وش أقدر أخدمك فيه اليوم؟',
                'try' => ['بكم تنظيف الأسنان؟', 'أبغى أحجز موعد بكرة العصر'],
                'identity' => 'You are the receptionist of a dental clinic in Riyadh: you answer questions about its treatments, prices and hours, and you book visits.',
                'steps' => [
                    'Find out what the caller needs: a question, a price, or a visit.',
                    'Answer from the facts in one or two sentences.',
                    'To book a visit, ask for the treatment, the day and time that suit them, their name and their mobile number, read the number back, then save it. Tell them the team will call to confirm the time.',
                ],
                'facts' => implode("\n", [
                    '- العنوان: الرياض، حي الملقا، طريق أنس بن مالك.',
                    '- المواعيد: من السبت إلى الخميس من 9 الصبح إلى 10 الليل، والجمعة من 4 العصر إلى 10 الليل.',
                    '- الكشف والاستشارة: 150 ريال، ومجاني إذا بدأ العلاج في نفس اليوم.',
                    '- تنظيف الأسنان وتلميعها: 250 ريال.',
                    '- تبييض الأسنان بالليزر: 1,200 ريال للجلسة.',
                    '- الحشوة التجميلية: من 350 ريال للسن.',
                    '- التقويم الشفاف: يبدأ من 9,000 ريال، والسعر النهائي بعد الكشف.',
                    '- التأمين: تقبل العيادة أغلب بطاقات التأمين الطبي، والفريق يتأكد من التغطية قبل الموعد.',
                    '- الحجز: يأخذ الوكيل الاسم ورقم الجوال والوقت المناسب، والفريق يتصل لتأكيد الموعد.',
                ]),
            ],
            'restaurant' => [
                'kind' => 'مطعم',
                'business' => 'مطعم سفرة البيت',
                'type' => 'restaurants',
                'jobs' => ['answer', 'orders', 'leads'],
                'name' => 'كريم',
                'gender' => 'male',
                'voice' => 'Puck',
                'dialect' => 'ar-EG',
                'first' => 'أهلًا بيك في مطعم سفرة البيت، معاك كريم. تحب تطلب إيه النهارده؟',
                'try' => ['عندكم إيه في المنيو؟', 'عايز أطلب وجبة العيلة توصيل'],
                'identity' => 'You take phone orders and answer questions for a home-style Egyptian restaurant in Cairo.',
                'steps' => [
                    'Find out whether the caller wants to order, or has a question about the menu, delivery or hours.',
                    'For an order, take the dishes and how many, then the name, the mobile number and the delivery address. Read the number back, then save the order with the contact details, and say the total and that the restaurant will call to confirm.',
                    'Suggest the family meal offer once, when it fits.',
                ],
                'facts' => implode("\n", [
                    '- العنوان: القاهرة، مصر الجديدة، شارع الميرغني.',
                    '- المواعيد: كل يوم من 12 الضهر لحد 2 بالليل.',
                    '- التوصيل: لمصر الجديدة ومدينة نصر خلال 45 دقيقة تقريبًا، ورسوم التوصيل 25 جنيه.',
                    '- المنيو: كشري كبير 85 جنيه، كشري وسط 65 جنيه، طاجن ملوخية بالفراخ 180 جنيه، حمام محشي (حمامتين) 320 جنيه، فتة شاورما 150 جنيه، أرز باللبن 45 جنيه.',
                    '- عرض اليوم: وجبة العيلة لـ 4 أفراد (كشري كبير وطاجن ملوخية وأرز باللبن) بـ 550 جنيه.',
                    '- الدفع: كاش عند الاستلام أو بالكارت.',
                ]),
            ],
            'shop' => [
                'kind' => 'متجر عطور',
                'business' => 'متجر بيروت للعطور',
                'type' => 'retail',
                'jobs' => ['answer', 'support', 'orders'],
                'name' => 'ريم',
                'gender' => 'female',
                'voice' => 'Aoede',
                'dialect' => 'ar-LB',
                'first' => 'أهلين، معك ريم من متجر بيروت للعطور. كيف فيني ساعدك؟',
                'try' => ['شو أطيب عطر عندكم؟', 'وين صار طلبي؟'],
                'identity' => 'You answer the customers of an online perfume shop in Lebanon: its perfumes, prices, delivery and returns, and where their order is.',
                'steps' => [
                    'Find out whether the caller wants advice, has a question, or asks about an order.',
                    'For advice, ask what scents they like, then suggest one or two perfumes from the facts with their price.',
                    'For an order, ask for the order number or their phone number, save it with a short note, and tell them the team will send the order status by message.',
                ],
                'facts' => implode("\n", [
                    '- متجر عطور على الإنترنت، والتوصيل لكل لبنان.',
                    '- التوصيل: خلال يومين لتلات أيام، ومجاني للطلبات فوق 60 دولار، وإلا 4 دولار.',
                    '- الأكثر طلبًا: عود ملكي 50 مل بـ 45 دولار، مسك أبيض 50 مل بـ 30 دولار، عنبر وفانيليا 100 مل بـ 55 دولار.',
                    '- علبة 5 عيّنات بـ 12 دولار، وسعرها بينحسم من أول عطر كامل.',
                    '- الإرجاع: خلال 7 أيام إذا العلبة ما انفتحت.',
                    '- الدفع: عند الاستلام أو بالبطاقة.',
                ]),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function english(): array
    {
        return [
            'clinic' => [
                'kind' => 'Dental clinic',
                'business' => 'Bright Smile Dental',
                'type' => 'healthcare',
                'jobs' => ['answer', 'booking', 'leads'],
                'name' => 'Emma',
                'gender' => 'female',
                'voice' => 'Sulafat',
                'dialect' => 'en-US',
                'first' => 'Hi, thanks for calling Bright Smile Dental, this is Emma. How can I help you today?',
                'try' => ['How much is a cleaning?', 'Can I book a visit for tomorrow afternoon?'],
                'identity' => 'You are the receptionist of a family dental clinic in Austin, Texas: you answer questions about its treatments, prices and hours, and you book visits.',
                'steps' => [
                    'Find out what the caller needs: a question, a price, or a visit.',
                    'Answer from the facts in one or two sentences.',
                    'To book a visit, ask for the treatment, the day and time that suit them, their name and phone number, read the number back, then save it. Tell them the team will call to confirm the time.',
                ],
                'facts' => implode("\n", [
                    '- Address: 1200 South Lamar Boulevard, Austin, Texas.',
                    '- Hours: Monday to Friday 8 am to 6 pm, Saturday 9 am to 2 pm, closed on Sunday.',
                    '- New patient exam with X-rays: $95.',
                    '- Cleaning: $120.',
                    '- Teeth whitening: $350 a session.',
                    '- White fillings: from $150 a tooth.',
                    '- Clear aligners: from $3,500; the final price comes after the exam.',
                    '- Insurance: most dental insurance plans are accepted; the team checks the cover before the visit.',
                    '- Booking: the agent takes the name, phone number and a good time, and the team calls to confirm.',
                ]),
            ],
            'restaurant' => [
                'kind' => 'Restaurant',
                'business' => 'The Olive Table',
                'type' => 'restaurants',
                'jobs' => ['answer', 'booking', 'orders'],
                'name' => 'James',
                'gender' => 'male',
                'voice' => 'Orus',
                'dialect' => 'en-GB',
                'first' => 'Good evening, The Olive Table, James speaking. How can I help?',
                'try' => ['What do you have on the menu tonight?', 'I would like a table for four on Friday'],
                'identity' => 'You answer the phone for a Mediterranean restaurant in London: the menu, opening hours, table bookings and takeaway orders.',
                'steps' => [
                    'Find out whether the caller wants a table, a takeaway order, or has a question.',
                    'For a table, ask for the day, the time and how many people, then their name and phone number. Read the number back, save the booking, and say the restaurant will text to confirm.',
                    'For a takeaway, take the dishes, the name and phone number, read the number back, save it, and say the total and when it will be ready.',
                ],
                'facts' => implode("\n", [
                    '- Address: 48 Upper Street, Islington, London.',
                    '- Hours: Tuesday to Sunday, 12 noon to 11 pm; closed on Monday.',
                    '- Starters: hummus with warm flatbread £7, grilled halloumi £8.50.',
                    '- Mains: lamb kofta with rice £18, sea bass with lemon and herbs £21, vegetable moussaka £15.',
                    '- Dessert: baklava with pistachio ice cream £7.',
                    '- Takeaway: ready in about 25 minutes, collected at the restaurant.',
                    '- Tables: up to 8 people a booking; larger groups are passed to the manager.',
                ]),
            ],
            'shop' => [
                'kind' => 'Online shop',
                'business' => 'Harbor & Pine Candles',
                'type' => 'retail',
                'jobs' => ['answer', 'support', 'orders'],
                'name' => 'Mia',
                'gender' => 'female',
                'voice' => 'Aoede',
                'dialect' => 'en-AU',
                'first' => 'Hi there, you have reached Harbor and Pine Candles, Mia speaking. What can I do for you?',
                'try' => ['Which candle would you recommend?', 'Where is my order?'],
                'identity' => 'You answer the customers of an online candle shop in Sydney: its candles, prices, delivery and returns, and where their order is.',
                'steps' => [
                    'Find out whether the caller wants advice, has a question, or asks about an order.',
                    'For advice, ask which scents they like, then suggest one or two candles from the facts with their price.',
                    'For an order, ask for the order number or their phone number, save it with a short note, and tell them the team will send the order status by text.',
                ],
                'facts' => implode("\n", [
                    '- Hand-poured soy candles, delivered across Australia.',
                    '- Delivery: 2 to 4 business days; free over $80, otherwise $9.',
                    '- Best sellers: Sea Salt and Sage $42, Eucalyptus and Mint $38, Vanilla and Amber $45; each burns for about 50 hours.',
                    '- Gift box of three small candles: $55.',
                    '- Returns: within 14 days if the candle has not been lit.',
                    '- Payment: card or pay later at checkout.',
                ]),
            ],
        ];
    }
}
