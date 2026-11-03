<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Support\ArabicDialects;
use Illuminate\Support\Str;

/**
 * Writes an agent from what the owner gave at setup (business, type, jobs,
 * notes) and what its website says: the first sentence, who it is, what it
 * must never do, its steps, when it passes the customer to a person, the
 * business facts it answers from, and two questions the owner should decide
 * (each with a recommended answer). What callers hear is in the agent's
 * language and dialect; what the owner reads and edits (who it is, its rules,
 * steps and the questions) is in the owner's own language.
 *
 * Without an answer from the text model it still returns a sound agent, made
 * from the owner's own words, so the setup never gets stuck.
 */
class AgentWriter
{
    public function __construct(private GeminiClient $gemini) {}

    /**
     * @return array{first_message: string, identity: string, never: array<int, string>, steps: array<int, string>,
     *               handoff: string, knowledge: string, questions: array<int, array{question: string, options: array<int, string>, recommended: int}>, written_by: string}
     */
    public function write(Agent $agent, string $siteText = '', string $notes = '', ?string $ownerLocale = null): array
    {
        $ownerLocale = $ownerLocale ?? app()->getLocale();
        $raw = $this->gemini->generate($this->prompt($agent, $siteText, $notes, $ownerLocale), self::schema(), 45, 0.4);
        $data = is_string($raw) ? json_decode($this->stripFence($raw), true) : null;
        if (! is_array($data) || trim((string) ($data['first_message'] ?? '')) === '') {
            return $this->fallback($agent, $siteText, $notes, $ownerLocale);
        }

        $clean = fn ($v, int $max) => Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $v) ?? ''), $max, '');
        $list = fn ($v, int $n, int $max) => array_values(array_slice(array_filter(array_map(fn ($x) => $clean($x, $max), (array) $v)), 0, $n));

        $questions = [];
        foreach (array_slice((array) ($data['questions'] ?? []), 0, 3) as $q) {
            $options = $list($q['options'] ?? [], 3, 140);
            if (trim((string) ($q['question'] ?? '')) === '' || count($options) < 2) {
                continue;
            }
            $questions[] = [
                'question' => $clean($q['question'], 220),
                'options' => $options,
                'recommended' => max(0, min(count($options) - 1, (int) ($q['recommended'] ?? 0))),
            ];
        }

        return [
            'first_message' => $clean($data['first_message'], 240),
            'identity' => $clean($data['identity'] ?? '', 700),
            'never' => $list($data['never'] ?? [], 8, 200),
            'steps' => $list($data['steps'] ?? [], 8, 260),
            'handoff' => $clean($data['handoff'] ?? '', 400),
            'knowledge' => Str::limit(trim((string) ($data['knowledge'] ?? '')), 12000, ''),
            'questions' => $questions,
            'written_by' => 'model',
        ];
    }

    public function prompt(Agent $agent, string $siteText, string $notes, ?string $ownerLocale = null): string
    {
        // The owner reads and edits these parts, so they are in the owner's language.
        $owner = ($ownerLocale ?? app()->getLocale()) === 'ar' ? 'simple Modern Standard Arabic' : 'English';
        $arabic = $agent->language !== 'en';
        $dialect = $arabic ? (ArabicDialects::prompt($agent->dialect ?: 'ar-XA-WH') ?? 'Arabic') : 'English';
        $jobs = implode(', ', (array) ($agent->jobs ?: ['answer']));
        $type = str_replace('_', ' ', (string) $agent->business_type);

        return trim(<<<TXT
You write a voice assistant for a business. It will answer the business's customers on live voice calls from the business's website or link.

Business name: {$agent->business_name}
Business type: {$type}
The assistant's name: {$agent->name}
What the owner wants it to do: {$jobs}
Language of the calls: {$dialect}

The owner's notes (most important, they win over the website):
{$notes}

The business's website, as read (may be incomplete or include noise):
{$siteText}

Write, as JSON:
- first_message: the first sentence the assistant says when the call starts, in {$dialect} as people really speak it: a warm greeting, its name, the business, and an offer to help. One sentence.
- identity: who the assistant is and its tone, in {$owner}, 2 or 3 sentences.
- never: up to 6 short rules, in {$owner}, of what it must never do for this kind of business (invent prices, promise discounts, give medical advice ...).
- steps: up to 6 short steps, in {$owner}, for a typical call, following the owner's goals (for example find what the caller needs, answer from the facts, take name and phone for a callback or a booking).
- handoff: in {$owner}, when it should pass the caller to a person.
- knowledge: the business facts the assistant may use, as short plain lines: services and products with prices, opening hours, branches and addresses, phone numbers, delivery, payment, booking and return policies. Only facts found in the notes or the website; never invent any. Keep the business's own words and currency.
- questions: exactly 2 practical questions the owner should decide for this business (for example what to do when a customer asks for a discount or wants to speak to a manager), in {$owner}, each with 2 or 3 short options and the index of the option you recommend.
TXT);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $str = ['type' => 'STRING'];
        $list = ['type' => 'ARRAY', 'items' => $str];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'first_message' => $str,
                'identity' => $str,
                'never' => $list,
                'steps' => $list,
                'handoff' => $str,
                'knowledge' => $str,
                'questions' => ['type' => 'ARRAY', 'items' => [
                    'type' => 'OBJECT',
                    'properties' => ['question' => $str, 'options' => $list, 'recommended' => ['type' => 'INTEGER']],
                    'required' => ['question', 'options', 'recommended'],
                ]],
            ],
            'required' => ['first_message', 'identity', 'never', 'steps', 'handoff', 'knowledge', 'questions'],
        ];
    }

    /** A sound agent from the owner's own words, when the text model is not there. */
    public function fallback(Agent $agent, string $siteText, string $notes, ?string $ownerLocale = null): array
    {
        $ar = ($ownerLocale ?? app()->getLocale()) === 'ar';
        $first = match (true) {
            $agent->language === 'en' => "Hello, this is {$agent->name} from {$agent->business_name}. How can I help you today?",
            str_starts_with((string) $agent->dialect, 'ar-EG') => "أهلاً بيك، معاك {$agent->name} من {$agent->business_name}، أقدر أساعدك إزاي؟",
            in_array(ArabicDialects::ALL[$agent->dialect ?? '']['region'] ?? null, ['gulf'], true) => "هلا والله، معك {$agent->name} من {$agent->business_name}، كيف أقدر أخدمك؟",
            default => "أهلاً وسهلاً، معك {$agent->name} من {$agent->business_name}، كيف أقدر أساعدك؟",
        };

        $owner = $ar ? [
            'identity' => "{$agent->name} هو المساعد الصوتي الودود لـ{$agent->business_name}. يجيب عن أسئلة العملاء بوضوح واختصار ويساعدهم على الخطوة التالية.",
            'never' => ['لا يخترع أبدًا أسعارًا أو عروضًا أو سياسات.', 'لا يعد بأي خصم.', 'لا يطلب أبدًا أرقام البطاقات أو كلمات المرور.'],
            'steps' => ['يرحب بالمتصل ويسأله كيف يساعده.', 'يجيب من المعلومات فقط.', 'إن لم يعرف الإجابة يأخذ الاسم ورقم الهاتف ليتصل به الفريق.', 'يودّع المتصل وينهي المكالمة.'],
            'handoff' => 'عندما يطلب المتصل التحدث مع شخص، أو يكون منزعجًا، أو يحتاج شيئًا غير موجود في المعلومات.',
            'questions' => [
                ['question' => "إذا سُئل {$agent->name} عن شيء لا يعرفه، ماذا يفعل؟", 'options' => ['يأخذ الاسم والرقم ليتصل به فريقك', 'يطلب من المتصل التواصل مع النشاط مباشرة'], 'recommended' => 0],
                ['question' => 'إذا طلب المتصل خصمًا؟', 'options' => ['يوضح أن الأسعار ثابتة ويعرض المساعدة', 'يأخذ رقمه ليرد عليه فريقك'], 'recommended' => 0],
            ],
        ] : [
            'identity' => "{$agent->name} is the friendly voice assistant of {$agent->business_name}. It answers customers' questions clearly and briefly and helps them take the next step.",
            'never' => ['Never invent prices, offers or policies.', 'Never promise a discount.', 'Never ask for card numbers or passwords.'],
            'steps' => ['Greet the caller and ask how you can help.', 'Answer from the facts only.', 'If you cannot answer, take their name and phone number for a callback.', 'Say goodbye and end the call.'],
            'handoff' => 'When the caller asks for a person, is upset, or needs something the facts do not cover.',
            'questions' => [
                ['question' => "If a caller asks {$agent->name} something it does not know, what should it do?", 'options' => ['Take their name and number for your team to call back', 'Ask them to contact the business directly'], 'recommended' => 0],
                ['question' => 'If a caller asks for a discount?', 'options' => ['Explain prices are fixed and offer to help', 'Take their number so your team can reply'], 'recommended' => 0],
            ],
        ];

        return $owner + [
            'first_message' => $first,
            'knowledge' => trim($notes."\n\n".Str::limit($siteText, 8000, '')),
            'written_by' => 'fallback',
        ];
    }

    private function stripFence(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw) ?? $raw;
        }

        return $raw;
    }
}
