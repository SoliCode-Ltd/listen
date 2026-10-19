<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Newsletter extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const AUDIENCE_FREE = 'free';

    public const AUDIENCE_PAID = 'paid';

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_PLAN = 'plan';

    public const AUDIENCE_CUSTOM = 'custom';

    public const LANGUAGE_EN = 'en';

    public const LANGUAGE_AR = 'ar';

    public const LANGUAGE_BOTH = 'both';

    // When "skip people who already received this newsletter" was last turned off.
    private const RESEND_FROM_SETTING = 'newsletter_resend_from.';

    protected $fillable = [
        'name',
        'status',
        'audience',
        'plan_id',
        'custom_emails',
        'exclude_blocked',
        'exclude_unsubscribed',
        'exclude_already_sent',
        'skip_phone_accounts',
        'exclude_admins',
        'language',
        'bilingual_order',
        'subject_en',
        'subject_ar',
        'preheader_en',
        'preheader_ar',
        'eyebrow_en',
        'eyebrow_ar',
        'headline_en',
        'headline_ar',
        'intro_en',
        'intro_ar',
        'body_en',
        'body_ar',
        'highlights_en',
        'highlights_ar',
        'cta_label_en',
        'cta_label_ar',
        'cta_url_en',
        'cta_url_ar',
        'footer_en',
        'footer_ar',
        'from_name',
        'from_address',
        'reply_to',
        'internal_notes',
        'last_test_sent_at',
        'last_test_email',
        'sent_at',
        'sent_count',
        'failed_count',
    ];

    protected $casts = [
        'exclude_blocked' => 'boolean',
        'exclude_unsubscribed' => 'boolean',
        'exclude_already_sent' => 'boolean',
        'skip_phone_accounts' => 'boolean',
        'exclude_admins' => 'boolean',
        'highlights_en' => 'array',
        'highlights_ar' => 'array',
        'last_test_sent_at' => 'datetime',
        'sent_at' => 'datetime',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    protected static function booted(): void
    {
        // Turning off "skip people who already received this newsletter" starts a
        // new round from that moment: people who had it before can get it again,
        // once, so each batch moves on instead of mailing the first people again.
        static::created(function (Newsletter $newsletter): void {
            if (! $newsletter->exclude_already_sent) {
                $newsletter->startResendRound();
            }
        });

        static::updated(function (Newsletter $newsletter): void {
            if ($newsletter->wasChanged('exclude_already_sent') && ! $newsletter->exclude_already_sent) {
                $newsletter->startResendRound();
            }
        });
    }

    /**
     * When the current round of sending it again began, or null when that is
     * not known (then everyone it was already sent to counts as done).
     */
    public function resendFrom(): ?Carbon
    {
        $value = trim((string) SiteSetting::get(self::RESEND_FROM_SETTING.$this->getKey(), ''));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function startResendRound(): void
    {
        SiteSetting::set(self::RESEND_FROM_SETTING.$this->getKey(), now()->toIso8601String(), 'newsletter');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NewsletterDelivery::class);
    }

    public function queuedDeliveries(): HasMany
    {
        return $this->deliveries()
            ->where('is_test', false)
            ->where('status', NewsletterDelivery::STATUS_QUEUED);
    }

    public static function billingUrl(string $locale): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/'.($locale === 'ar' ? 'ar' : 'en').'/billing';
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultAttributes(): array
    {
        return [
            'name' => 'Voice cloning',
            'status' => self::STATUS_DRAFT,
            'audience' => self::AUDIENCE_FREE,
            'plan_id' => null,
            'custom_emails' => null,
            'exclude_blocked' => true,
            'exclude_unsubscribed' => true,
            'exclude_already_sent' => true,
            'skip_phone_accounts' => true,
            'exclude_admins' => true,
            'language' => self::LANGUAGE_BOTH,
            'bilingual_order' => 'en_ar',
            'subject_en' => 'Your voice can now be cloned',
            'subject_ar' => 'صار بإمكانك استنساخ صوتك',
            'preheader_en' => 'Record a short sample, then use your own voice on every script.',
            'preheader_ar' => 'سجّل عينة قصيرة، ثم استخدم صوتك في كل نص.',
            'eyebrow_en' => '',
            'eyebrow_ar' => '',
            'headline_en' => 'Clone your voice. Use it on every script.',
            'headline_ar' => 'استنسخ صوتك واستخدمه في كل نص.',
            'intro_en' => 'Listen can now build a voice from a short recording of you. Once it is ready, that voice is available whenever you generate speech.',
            'intro_ar' => 'تستطيع منصة إسمع الآن بناء صوت من تسجيل قصير لك. عندما يجهز، يصبح هذا الصوت متاحاً كلما ولّدت كلاماً.',
            'body_en' => 'Cloning is included on a paid plan. Free accounts stay on the standard voices. Upgrade, then open Voice Cloning in your dashboard and record a sample in a quiet room.',
            'body_ar' => 'الاستنساخ متاح في الباقات المدفوعة. الحساب المجاني يبقى على الأصوات القياسية. رقِّ اشتراكك، ثم افتح استنساخ الأصوات في لوحة التحكم وسجّل العينة في غرفة هادئة.',
            'highlights_en' => [
                ['text' => 'A short recording is enough to build your voice'],
                ['text' => 'Use the cloned voice for Arabic or English scripts'],
                ['text' => 'You choose the name, and you can remove it later'],
            ],
            'highlights_ar' => [
                ['text' => 'تسجيل قصير يكفي لبناء صوتك'],
                ['text' => 'استخدم الصوت المستنسخ لنصوص عربية أو إنجليزية'],
                ['text' => 'أنت تختار الاسم، ويمكنك حذفه لاحقاً'],
            ],
            'cta_label_en' => 'Upgrade to clone your voice',
            'cta_label_ar' => 'ترقية لاستنساخ صوتك',
            'cta_url_en' => self::billingUrl('en'),
            'cta_url_ar' => self::billingUrl('ar'),
            'footer_en' => 'You received this because you have a Listen account.',
            'footer_ar' => 'وصلتك هذه الرسالة لأن لديك حساباً على منصة إسمع.',
            'from_name' => 'Listen',
            'from_address' => 'help@listenai.studio',
            'reply_to' => null,
            'internal_notes' => null,
        ];
    }

    public function duplicateAsDraft(): self
    {
        $copy = $this->replicate([
            'status',
            'sent_at',
            'sent_count',
            'failed_count',
            'last_test_sent_at',
            'last_test_email',
        ]);
        $copy->status = self::STATUS_DRAFT;
        $copy->sent_at = null;
        $copy->sent_count = 0;
        $copy->failed_count = 0;
        $copy->last_test_sent_at = null;
        $copy->last_test_email = null;
        $copy->name = Str::limit(trim($this->name).' (copy)', 255, '');
        $copy->save();

        return $copy;
    }
}
