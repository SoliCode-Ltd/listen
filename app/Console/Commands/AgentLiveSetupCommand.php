<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\Agents\LiveSession;
use Illuminate\Console\Command;

/**
 * Prints the live call setup of a sample agent as JSON (nothing is saved):
 * what the agent voice test locks into a short-lived key, so the test checks
 * the very setup the agents use.
 *
 * --sample picks one of the sample businesses below, each with its own
 * speaker from the voice library: the calls recorded for the homepage and
 * the Agents page (made-up businesses, the agent's real voice and words).
 */
class AgentLiveSetupCommand extends Command
{
    protected $signature = 'agents:live-setup
        {--sample= : A sample business (see SAMPLES)}
        {--dialect=ar-SA : The agent\'s dialect}
        {--voice=Achernar : The live voice}
        {--name=Fatima : The agent\'s name}';

    protected $description = 'Print a sample agent\'s live call setup as JSON (for the voice test)';

    /**
     * Made-up businesses, one per recorded call. Each speaker is the voice
     * library's own (name, live voice and dialect), so the recording matches
     * the face shown with it.
     *
     * @var array<string, array<string, mixed>>
     */
    public const SAMPLES = [
        'ar-sa' => [
            'name' => 'فاطمة', 'voice' => 'Achernar', 'language' => 'ar', 'dialect' => 'ar-SA',
            'business_name' => 'عيادة لمسة للأسنان', 'business_type' => 'healthcare', 'jobs' => ['answer', 'booking', 'leads'],
            'identity' => 'فاطمة موظفة الاستقبال في عيادة لمسة للأسنان بالرياض: ودودة ومرتبة ومختصرة.',
            'knowledge' => "عيادة لمسة للأسنان في الرياض، حي الملقا.\nالدوام: من السبت إلى الخميس، من 10 الصبح إلى 10 الليل. الجمعة إجازة.\nتنظيف الأسنان: 250 ريال، حوالي 45 دقيقة. تبييض الأسنان: 900 ريال.\nالمواعيد المتاحة هذا الأسبوع: الخميس الساعة 4 العصر، والخميس الساعة 6 المغرب.\nالحجز: خذ الاسم ورقم الجوال والموعد المطلوب، وأعد قراءة الرقم، ثم احفظ الطلب ليؤكده الفريق برسالة.",
        ],
        'ar-ae' => [
            'name' => 'حامد', 'voice' => 'Sadachbia', 'language' => 'ar', 'dialect' => 'ar-AE',
            'business_name' => 'مطعم بيت السدو', 'business_type' => 'restaurants', 'jobs' => ['answer', 'booking', 'leads'],
            'identity' => 'حامد يرد على حجوزات مطعم بيت السدو في دبي: مرحّب وهادئ ومختصر.',
            'knowledge' => "مطعم بيت السدو في دبي، جميرا. أكل إماراتي وخليجي.\nمفتوح كل يوم من 12 الظهر إلى 1 بعد منتصف الليل.\nفيه قسم للعائلات. أشهر طبق: مندي اللحم.\nحجز الطاولات: خذ الاسم ورقم الجوال وعدد الأشخاص والوقت، وأعد قراءة الرقم، ثم احفظ الطلب.\nالليلة فيه طاولات متاحة الساعة 9.",
        ],
        'ar-eg' => [
            'name' => 'سمية', 'voice' => 'Callirrhoe', 'language' => 'ar', 'dialect' => 'ar-EG',
            'business_name' => 'معمل نور للتحاليل', 'business_type' => 'healthcare', 'jobs' => ['answer', 'booking', 'leads'],
            'identity' => 'سمية بترد على عملاء معمل نور للتحاليل في القاهرة: بشوشة وواضحة ومختصرة.',
            'knowledge' => "معمل نور للتحاليل في القاهرة، مدينة نصر.\nمفتوح كل يوم من 8 الصبح لـ 10 بالليل.\nسحب العينات من البيت متاح في القاهرة والجيزة، بزيادة 100 جنيه.\nصورة الدم الكاملة: 150 جنيه. النتيجة بتطلع خلال 24 ساعة وبتوصل على الموبايل.\nزيارة البيت: خد الاسم ورقم الموبايل والميعاد، وأعد قراءة الرقم، وبعدين احفظ الطلب.",
        ],
        'ar-lb' => [
            'name' => 'رائد', 'voice' => 'Umbriel', 'language' => 'ar', 'dialect' => 'ar-LB-SO',
            'business_name' => 'مكتب البحر العقاري', 'business_type' => 'real_estate', 'jobs' => ['answer', 'leads'],
            'identity' => 'رائد بيرد على زبائن مكتب البحر العقاري بصيدا: لطيف وعملي ومختصر.',
            'knowledge' => "مكتب البحر العقاري بصيدا.\nشقق للإيجار: غرفتين نوم بتبدا من 500 دولار بالشهر، وتلات غرف من 750 دولار.\nالمعاينات من الاثنين للسبت.\nلما حدا يسأل عن شقة: خود الاسم ورقم التلفون والمنطقة والميزانية، وأعد قراءة الرقم، وبعدين احفظ الطلب ليتصل فيه حدا من الفريق.",
        ],
        'en-us' => [
            'name' => 'Caleb', 'voice' => 'Sadaltager', 'language' => 'en', 'dialect' => 'en-US',
            'business_name' => 'Sunset Auto Care', 'business_type' => 'automotive', 'jobs' => ['answer', 'booking', 'leads'],
            'identity' => 'Caleb answers the phone at Sunset Auto Care in Phoenix: friendly, quick and clear.',
            'knowledge' => "Sunset Auto Care, Phoenix, Arizona.\nOpen Monday to Saturday, 8 am to 6 pm.\nOil change: \$49, about 30 minutes. Same-day appointments when there is room.\nToday there is room at 3 pm and 4:30 pm.\nBookings: take the name, phone number and time, read the number back, then save the request.",
        ],
        'en-gb' => [
            'name' => 'Alice', 'voice' => 'Achernar', 'language' => 'en', 'dialect' => 'en-GB-EA',
            'business_name' => 'Bloom and Willow', 'business_type' => 'retail', 'jobs' => ['answer', 'orders', 'leads'],
            'identity' => 'Alice takes orders at Bloom and Willow, a florist in Norwich: warm, helpful and brief.',
            'knowledge' => "Bloom and Willow, florist in Norwich.\nOpen Monday to Saturday, 9 am to 5:30 pm.\nBouquets from £35. Delivery in Norwich is £6, and tomorrow's deliveries are open.\nOrders: take the name, phone number, the flowers and the budget, read the number back, then save the order for the team to confirm.",
        ],
        'en-ie' => [
            'name' => 'Sienna', 'voice' => 'Leda', 'language' => 'en', 'dialect' => 'en-IE',
            'business_name' => 'Harbour Lane Guesthouse', 'business_type' => 'travel', 'jobs' => ['answer', 'booking', 'leads'],
            'identity' => 'Sienna looks after bookings at Harbour Lane Guesthouse in Galway: warm, easy-going and brief.',
            'knowledge' => "Harbour Lane Guesthouse, Galway.\nA double room is €140 a night with breakfast. Check-in from 3 pm, free parking.\nThis Friday night there are two double rooms free.\nBookings: take the name, phone number and night, read the number back, then save the request for the team to confirm by text.",
        ],
    ];

    public function handle(): int
    {
        $key = (string) $this->option('sample');
        if ($key !== '') {
            $sample = self::SAMPLES[$key] ?? null;
            if ($sample === null) {
                $this->error('No sample "'.$key.'". Samples: '.implode(', ', array_keys(self::SAMPLES)));

                return self::FAILURE;
            }
            $ar = $sample['language'] === 'ar';
            $agent = new Agent([
                'name' => $sample['name'],
                'business_name' => $sample['business_name'],
                'business_type' => $sample['business_type'],
                'jobs' => $sample['jobs'],
                'language' => $sample['language'],
                'dialect' => $sample['dialect'],
                'live_voice' => $sample['voice'],
                'first_message' => null,
                'script' => [
                    'identity' => $sample['identity'],
                    'never' => [$ar ? 'لا تعد بأي خصم.' : 'Never promise a discount.'],
                    'steps' => $ar
                        ? ['افهم ما يحتاجه المتصل.', 'أجب من المعلومات.', 'للحجز أو معاودة الاتصال: خذ الاسم ورقم الهاتف، وأعد قراءة الرقم، ثم احفظه.']
                        : ['Find out what the caller needs.', 'Answer from the facts.', 'For a booking or a callback, take their name and phone number, read the number back, then save it.'],
                    'handoff' => $ar ? 'عندما يطلب المتصل التحدث مع شخص.' : 'When the caller asks for a person.',
                ],
                'knowledge' => $sample['knowledge'],
            ]);
        } else {
            $agent = new Agent([
                'name' => (string) $this->option('name'),
                'business_name' => 'Listen',
                'business_type' => 'services',
                'jobs' => ['answer', 'leads'],
                'language' => 'ar',
                'dialect' => (string) $this->option('dialect'),
                'live_voice' => (string) $this->option('voice'),
                'first_message' => null,
                'script' => [
                    'identity' => 'A warm, efficient receptionist for Listen.',
                    'never' => ['Never promise a discount.'],
                    'steps' => ['Find what the caller needs.', 'Answer from the facts.', 'For a callback, take their name and phone number, read the number back, then save it.'],
                    'handoff' => 'When the caller asks for a person or for a custom team plan.',
                ],
                'knowledge' => "Listen turns text into natural speech in many Arabic dialects and English accents.\nMonthly plans: Basic $4 (20 minutes), Starter $9 (100 minutes), Creator $22 (200 minutes), Pro $49 (500 minutes), Studio Max $99 (2,000 minutes).\nPayment: card, Apple Pay, Google Pay, InstaPay or Vodafone Cash.",
            ]);
        }

        $this->line(json_encode(LiveSession::setupFor($agent), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
