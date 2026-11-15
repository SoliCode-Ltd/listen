<?php

use App\Models\SiteSetting;
use App\Services\Billing\PurchaseFollowUps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The discount email's message, where the team only moved its line breaks: the live site had
 * put the greeting on a line of its own, so the saved message was no longer exactly the first
 * one and the migration before this left it. Its words are still the first words, so it gets
 * the new message too (which keeps the greeting on its own line). A message with words of the
 * team's own is left as they wrote it.
 */
return new class extends Migration
{
    /** The first messages, as they were when the email was added. */
    private const FIRST = [
        'renewal_offer_body_en' => "Hi {name}, we would like you to stay. We have taken {discount} off the renewal of your {plan} plan: you pay {price} instead of {old_price}.{next}\n\nPress the button below to pay the lower price and keep your plan, with every voice, dialect and feature in it.",
        'renewal_offer_body_ar' => "أهلاً {name}، يهمّنا أن تبقى معنا. خصمنا لك {discount} من تجديد باقة {plan}: تدفع {price} بدلاً من {old_price}.{next}\n\nاضغط الزر أدناه لتدفع السعر المخفّض وتحتفظ بباقتك بكل أصواتها ولهجاتها ومزاياها.",
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
            if (is_string($saved) && self::words($saved) === self::words($first) && isset($now[$key])) {
                SiteSetting::set($name, $now[$key], PurchaseFollowUps::GROUP);
            }
        }
    }

    public function down(): void
    {
        // The words stay: the team may have edited them since.
    }

    /** The words alone: no spaces or line breaks, and no difference between capitals and small letters. */
    private static function words(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $text));
    }
};
