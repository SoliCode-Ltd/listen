<?php

use App\Models\SiteSetting;
use App\Services\Billing\PurchaseFollowUps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The email for a declined renewal with a discount has new words: it now says the renewal was
 * declined and the day the plan ends if it stays unpaid, then the lower price. Saving "Emails
 * and settings" stores every word, so a site that saved the page holds the first words and
 * would never see the new ones. Each saved word that is still exactly the first one is moved
 * to the new one; a word the team rewrote is left as they wrote it.
 */
return new class extends Migration
{
    /** The first words, as they were when the email was added. */
    private const FIRST = [
        'renewal_offer_subject_en' => '{discount} off your {plan} renewal',
        'renewal_offer_subject_ar' => 'خصم {discount} على تجديد باقة {plan}',
        'renewal_offer_headline_en' => 'Keep your {plan} plan for {price}',
        'renewal_offer_headline_ar' => 'احتفظ بباقة {plan} مقابل {price}',
        'renewal_offer_body_en' => "Hi {name}, we would like you to stay. We have taken {discount} off the renewal of your {plan} plan: you pay {price} instead of {old_price}.{next}\n\nPress the button below to pay the lower price and keep your plan, with every voice, dialect and feature in it.",
        'renewal_offer_body_ar' => "أهلاً {name}، يهمّنا أن تبقى معنا. خصمنا لك {discount} من تجديد باقة {plan}: تدفع {price} بدلاً من {old_price}.{next}\n\nاضغط الزر أدناه لتدفع السعر المخفّض وتحتفظ بباقتك بكل أصواتها ولهجاتها ومزاياها.",
        'renewal_offer_cta_label_en' => 'Pay {price}',
        'renewal_offer_cta_label_ar' => 'ادفع {price}',
        'renewal_offer_footer_en' => 'Questions about your plan or the payment? Just reply to this email.',
        'renewal_offer_footer_ar' => 'لديك سؤال عن باقتك أو عن الدفع؟ ردّ على هذه الرسالة.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        $now = PurchaseFollowUps::defaults();
        foreach (self::FIRST as $key => $first) {
            $name = PurchaseFollowUps::GROUP.'_'.$key;
            $saved = SiteSetting::query()->where('key', $name)->value('value');
            if (is_string($saved) && self::same($saved, $first) && isset($now[$key])) {
                SiteSetting::set($name, $now[$key], PurchaseFollowUps::GROUP);
            }
        }
    }

    public function down(): void
    {
        // The words stay: the team may have edited them since.
    }

    /** The same words, whatever the line endings and the space around them. */
    private static function same(string $a, string $b): bool
    {
        $tidy = fn (string $text): string => trim(str_replace(["\r\n", "\r"], "\n", $text));

        return $tidy($a) === $tidy($b);
    }
};
