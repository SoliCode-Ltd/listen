<?php

namespace App\Support\Acquisition;

/**
 * Where a visit came from, in the few groups the admin reads (Admin → Where members come from):
 * a group (the channel) and, inside it, the one site, network or campaign (the detail).
 *
 * A link's own markers decide first (an ad network's click marker, then its campaign fields),
 * then the site the visitor came from, then nothing: typed in, a bookmark, or an app that hides
 * the link (WhatsApp and most chat apps do).
 */
final class Channels
{
    public const GOOGLE_ADS = 'google_ads';

    public const CHATGPT_ADS = 'chatgpt_ads';

    public const META_ADS = 'meta_ads';

    public const TIKTOK_ADS = 'tiktok_ads';

    public const OTHER_ADS = 'other_ads';

    public const SEARCH = 'search';

    public const AI = 'ai';

    public const SOCIAL = 'social';

    public const EMAIL = 'email';

    public const INVITE = 'invite';

    public const APP_STORE = 'app_store';

    public const APP = 'app';

    public const WEBSITE = 'website';

    public const CAMPAIGN = 'campaign';

    public const DIRECT = 'direct';

    public const UNKNOWN = 'unknown';

    /** @var array<string, string> */
    public const LABELS = [
        self::GOOGLE_ADS => 'Google Ads',
        self::CHATGPT_ADS => 'ChatGPT Ads',
        self::META_ADS => 'Facebook / Instagram ads',
        self::TIKTOK_ADS => 'TikTok ads',
        self::OTHER_ADS => 'Other ads',
        self::SEARCH => 'Search results',
        self::AI => 'AI assistants',
        self::SOCIAL => 'Social media',
        self::EMAIL => 'Email',
        self::INVITE => 'Invite links',
        self::APP_STORE => 'App stores',
        self::APP => 'Phone app',
        self::WEBSITE => 'Other websites',
        self::CAMPAIGN => 'Tagged links',
        self::DIRECT => 'Direct',
        self::UNKNOWN => 'Not recorded',
    ];

    /** @var array<string, string> what each group holds, for the page's key */
    public const HELP = [
        self::GOOGLE_ADS => 'A click on a Google ad (the link carried Google\'s click marker).',
        self::CHATGPT_ADS => 'A click on a ChatGPT ad.',
        self::META_ADS => 'A Facebook or Instagram link tagged as paid (utm_medium=paid or cpc).',
        self::TIKTOK_ADS => 'A click on a TikTok ad.',
        self::OTHER_ADS => 'A click on another network\'s ad, or a link tagged as paid.',
        self::SEARCH => 'Search results, not ads: Google, Bing and the others.',
        self::AI => 'A link in an AI assistant\'s answer: ChatGPT, Gemini, Perplexity and the others.',
        self::SOCIAL => 'A link on a social network or in a chat app that shows it: Facebook, Instagram, YouTube, TikTok, X, Telegram… A Facebook or Instagram ad without a paid tag lands here too.',
        self::EMAIL => 'A link in an email (a webmail, or a link tagged utm_medium=email).',
        self::INVITE => 'A member\'s invite link.',
        self::APP_STORE => 'The app stores\' pages.',
        self::APP => 'Signed up in the Android or iPhone app, with no visit to the website before.',
        self::WEBSITE => 'A link on another website.',
        self::CAMPAIGN => 'A link tagged with a campaign (utm_…) that fits none of the groups above.',
        self::DIRECT => 'Typed in, a bookmark, or an app that hides where the link was (WhatsApp, most chat apps, some phone browsers).',
        self::UNKNOWN => 'Joined before this was recorded, or nothing could be kept: cookies blocked, or a visitor from the UK, the EEA or Switzerland.',
    ];

    /** Paid media, as campaign links name it. */
    private const PAID_MEDIA = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paidsocial', 'paid_social', 'paid-social',
        'paid_media', 'paid-media', 'display', 'cpm', 'cpv', 'banner', 'ad', 'ads', 'sponsored', 'retargeting', 'remarketing'];

    private const EMAIL_MEDIA = ['email', 'e-mail', 'mail', 'newsletter'];

    /** Site name (without www., m., l. and the like) => [group, name]. */
    private const SITES = [
        // AI assistants first: some live under a search engine's name.
        'chatgpt.com' => [self::AI, 'ChatGPT'], 'chat.openai.com' => [self::AI, 'ChatGPT'], 'openai.com' => [self::AI, 'ChatGPT'],
        'gemini.google.com' => [self::AI, 'Gemini'], 'bard.google.com' => [self::AI, 'Gemini'],
        'perplexity.ai' => [self::AI, 'Perplexity'], 'copilot.microsoft.com' => [self::AI, 'Copilot'], 'copilot.cloud.microsoft' => [self::AI, 'Copilot'],
        'claude.ai' => [self::AI, 'Claude'], 'chat.deepseek.com' => [self::AI, 'DeepSeek'], 'deepseek.com' => [self::AI, 'DeepSeek'],
        'grok.com' => [self::AI, 'Grok'], 'x.ai' => [self::AI, 'Grok'], 'meta.ai' => [self::AI, 'Meta AI'], 'poe.com' => [self::AI, 'Poe'],
        'you.com' => [self::AI, 'You.com'], 'phind.com' => [self::AI, 'Phind'], 'chat.mistral.ai' => [self::AI, 'Mistral'],
        'kimi.com' => [self::AI, 'Kimi'], 'kimi.moonshot.cn' => [self::AI, 'Kimi'], 'chat.qwen.ai' => [self::AI, 'Qwen'], 'character.ai' => [self::AI, 'Character.AI'],
        // Email, read in a browser.
        'mail.google.com' => [self::EMAIL, 'Gmail'], 'outlook.live.com' => [self::EMAIL, 'Outlook'], 'outlook.office.com' => [self::EMAIL, 'Outlook'],
        'outlook.office365.com' => [self::EMAIL, 'Outlook'], 'mail.yahoo.com' => [self::EMAIL, 'Yahoo Mail'], 'mail.proton.me' => [self::EMAIL, 'Proton Mail'],
        'mail.aol.com' => [self::EMAIL, 'AOL Mail'], 'mail.yandex.ru' => [self::EMAIL, 'Yandex Mail'],
        // App stores.
        'play.google.com' => [self::APP_STORE, 'Google Play'], 'apps.apple.com' => [self::APP_STORE, 'App Store'], 'itunes.apple.com' => [self::APP_STORE, 'App Store'],
        // Search engines (Google's own country sites are matched below).
        'bing.com' => [self::SEARCH, 'Bing'], 'cn.bing.com' => [self::SEARCH, 'Bing'], 'search.yahoo.com' => [self::SEARCH, 'Yahoo'], 'yahoo.com' => [self::SEARCH, 'Yahoo'],
        'duckduckgo.com' => [self::SEARCH, 'DuckDuckGo'], 'yandex.ru' => [self::SEARCH, 'Yandex'], 'yandex.com' => [self::SEARCH, 'Yandex'], 'ya.ru' => [self::SEARCH, 'Yandex'],
        'baidu.com' => [self::SEARCH, 'Baidu'], 'ecosia.org' => [self::SEARCH, 'Ecosia'], 'search.brave.com' => [self::SEARCH, 'Brave Search'],
        'naver.com' => [self::SEARCH, 'Naver'], 'search.naver.com' => [self::SEARCH, 'Naver'], 'startpage.com' => [self::SEARCH, 'Startpage'],
        'qwant.com' => [self::SEARCH, 'Qwant'], 'seznam.cz' => [self::SEARCH, 'Seznam'], 'search.aol.com' => [self::SEARCH, 'AOL'],
        'ask.com' => [self::SEARCH, 'Ask'], 'petalsearch.com' => [self::SEARCH, 'Petal Search'], 'yep.com' => [self::SEARCH, 'Yep'],
        // Social networks and the chat apps that pass the link on.
        'facebook.com' => [self::SOCIAL, 'Facebook'], 'fb.com' => [self::SOCIAL, 'Facebook'], 'fb.me' => [self::SOCIAL, 'Facebook'], 'messenger.com' => [self::SOCIAL, 'Facebook'],
        'instagram.com' => [self::SOCIAL, 'Instagram'], 'tiktok.com' => [self::SOCIAL, 'TikTok'], 'vt.tiktok.com' => [self::SOCIAL, 'TikTok'],
        'youtube.com' => [self::SOCIAL, 'YouTube'], 'youtu.be' => [self::SOCIAL, 'YouTube'], 'music.youtube.com' => [self::SOCIAL, 'YouTube'],
        't.co' => [self::SOCIAL, 'X'], 'x.com' => [self::SOCIAL, 'X'], 'twitter.com' => [self::SOCIAL, 'X'],
        'linkedin.com' => [self::SOCIAL, 'LinkedIn'], 'lnkd.in' => [self::SOCIAL, 'LinkedIn'], 'reddit.com' => [self::SOCIAL, 'Reddit'],
        'pinterest.com' => [self::SOCIAL, 'Pinterest'], 'pin.it' => [self::SOCIAL, 'Pinterest'], 'snapchat.com' => [self::SOCIAL, 'Snapchat'],
        'threads.net' => [self::SOCIAL, 'Threads'], 'threads.com' => [self::SOCIAL, 'Threads'], 't.me' => [self::SOCIAL, 'Telegram'],
        'telegram.org' => [self::SOCIAL, 'Telegram'], 'telegram.me' => [self::SOCIAL, 'Telegram'], 'whatsapp.com' => [self::SOCIAL, 'WhatsApp'],
        'wa.me' => [self::SOCIAL, 'WhatsApp'], 'discord.com' => [self::SOCIAL, 'Discord'], 'discord.gg' => [self::SOCIAL, 'Discord'],
        'quora.com' => [self::SOCIAL, 'Quora'], 'vk.com' => [self::SOCIAL, 'VK'], 'tumblr.com' => [self::SOCIAL, 'Tumblr'],
    ];

    /** The Android apps that open a link and say so (android-app://<package>). */
    private const ANDROID_APPS = [
        'com.google.android.googlequicksearchbox' => [self::SEARCH, 'Google'],
        'com.google.android.gm' => [self::EMAIL, 'Gmail'],
        'com.android.vending' => [self::APP_STORE, 'Google Play'],
        'com.facebook.katana' => [self::SOCIAL, 'Facebook'], 'com.facebook.lite' => [self::SOCIAL, 'Facebook'], 'com.facebook.orca' => [self::SOCIAL, 'Facebook'],
        'com.instagram.android' => [self::SOCIAL, 'Instagram'], 'com.zhiliaoapp.musically' => [self::SOCIAL, 'TikTok'],
        'com.ss.android.ugc.trill' => [self::SOCIAL, 'TikTok'], 'com.ss.android.ugc.aweme' => [self::SOCIAL, 'TikTok'],
        'com.google.android.youtube' => [self::SOCIAL, 'YouTube'], 'org.telegram.messenger' => [self::SOCIAL, 'Telegram'],
        'com.whatsapp' => [self::SOCIAL, 'WhatsApp'], 'com.whatsapp.w4b' => [self::SOCIAL, 'WhatsApp'], 'com.twitter.android' => [self::SOCIAL, 'X'],
        'com.linkedin.android' => [self::SOCIAL, 'LinkedIn'], 'com.snapchat.android' => [self::SOCIAL, 'Snapchat'], 'com.reddit.frontpage' => [self::SOCIAL, 'Reddit'],
        'com.openai.chatgpt' => [self::AI, 'ChatGPT'], 'com.google.android.apps.bard' => [self::AI, 'Gemini'],
        'com.microsoft.copilot' => [self::AI, 'Copilot'], 'com.anthropic.claude' => [self::AI, 'Claude'],
    ];

    /** Campaign sources (utm_source) that name a known site => [group, name]. */
    private const SOURCE_NAMES = [
        'google' => [self::SEARCH, 'Google'], 'bing' => [self::SEARCH, 'Bing'],
        'chatgpt' => [self::AI, 'ChatGPT'], 'chatgpt.com' => [self::AI, 'ChatGPT'], 'openai' => [self::AI, 'ChatGPT'],
        'perplexity' => [self::AI, 'Perplexity'], 'perplexity.ai' => [self::AI, 'Perplexity'], 'gemini' => [self::AI, 'Gemini'],
        'copilot' => [self::AI, 'Copilot'], 'claude' => [self::AI, 'Claude'], 'claude.ai' => [self::AI, 'Claude'], 'deepseek' => [self::AI, 'DeepSeek'],
        'facebook' => [self::SOCIAL, 'Facebook'], 'fb' => [self::SOCIAL, 'Facebook'], 'meta' => [self::SOCIAL, 'Facebook'],
        'instagram' => [self::SOCIAL, 'Instagram'], 'ig' => [self::SOCIAL, 'Instagram'], 'tiktok' => [self::SOCIAL, 'TikTok'],
        'youtube' => [self::SOCIAL, 'YouTube'], 'x' => [self::SOCIAL, 'X'], 'twitter' => [self::SOCIAL, 'X'], 'linkedin' => [self::SOCIAL, 'LinkedIn'],
        'whatsapp' => [self::SOCIAL, 'WhatsApp'], 'telegram' => [self::SOCIAL, 'Telegram'], 'snapchat' => [self::SOCIAL, 'Snapchat'],
        'reddit' => [self::SOCIAL, 'Reddit'], 'threads' => [self::SOCIAL, 'Threads'], 'pinterest' => [self::SOCIAL, 'Pinterest'],
    ];

    public static function label(?string $channel): string
    {
        return self::LABELS[(string) $channel] ?? self::LABELS[self::UNKNOWN];
    }

    /**
     * @return array{channel: string, detail: string, campaign: ?string}
     */
    public static function classify(Touch $touch): array
    {
        $campaign = $touch->campaign['utm_campaign'] ?? (isset($touch->campaign['gad_campaignid']) ? 'Google Ads campaign '.$touch->campaign['gad_campaignid'] : null);
        [$channel, $detail] = self::sort($touch);

        return ['channel' => $channel, 'detail' => $detail, 'campaign' => $campaign];
    }

    /** @return array{0: string, 1: string} */
    private static function sort(Touch $touch): array
    {
        $clicks = $touch->clicks;
        $source = $touch->campaign['utm_source'] ?? '';
        $medium = $touch->campaign['utm_medium'] ?? '';
        $paid = in_array($medium, self::PAID_MEDIA, true);
        $site = self::site($touch->host);

        // An ad network's own marker on the link.
        if (array_intersect(['gbraid', 'wbraid'], $clicks) !== [] && ! in_array('gclid', $clicks, true)) {
            return [self::GOOGLE_ADS, 'Google Ads, on an iPhone'];
        }
        if (array_intersect(['gclid', 'gad_source'], $clicks) !== []) {
            return [self::GOOGLE_ADS, 'Google Ads'];
        }
        if (in_array('oppref', $clicks, true)) {
            return [self::CHATGPT_ADS, 'ChatGPT Ads'];
        }
        if (in_array('ttclid', $clicks, true)) {
            return [self::TIKTOK_ADS, 'TikTok ads'];
        }
        foreach (['msclkid' => 'Microsoft Ads', 'twclid' => 'X ads', 'li_fat_id' => 'LinkedIn ads', 'sccid' => 'Snapchat ads'] as $marker => $name) {
            if (in_array($marker, $clicks, true)) {
                return [self::OTHER_ADS, $name];
            }
        }
        if (in_array('fbclid', $clicks, true)) {
            // Facebook and Instagram mark ad clicks and ordinary links alike: only a paid tag tells them apart.
            $network = ($site[1] ?? '') === 'Instagram' || in_array($source, ['instagram', 'ig'], true) ? 'Instagram' : 'Facebook';

            return $paid ? [self::META_ADS, $network] : [self::SOCIAL, $network];
        }
        if (in_array('ref', $clicks, true)) {
            return [self::INVITE, 'Invite link'];
        }

        // The campaign the link names.
        if ($source !== '' || $medium !== '') {
            $named = self::SOURCE_NAMES[$source] ?? null;
            if ($paid) {
                return match (true) {
                    in_array($source, ['google', 'adwords', 'googleads', 'google_ads', 'gads'], true) => [self::GOOGLE_ADS, 'Google Ads'],
                    in_array($source, ['facebook', 'fb', 'instagram', 'ig', 'meta'], true) => [self::META_ADS, $named[1] ?? 'Facebook'],
                    $source === 'tiktok' => [self::TIKTOK_ADS, 'TikTok ads'],
                    in_array($source, ['chatgpt', 'chatgpt.com', 'openai'], true) => [self::CHATGPT_ADS, 'ChatGPT Ads'],
                    default => [self::OTHER_ADS, self::named($source !== '' ? $source : $medium)],
                };
            }
            if (in_array($medium, self::EMAIL_MEDIA, true)) {
                return [self::EMAIL, $source !== '' ? self::named($source) : 'Email'];
            }
            if ($named !== null) {
                return $named;
            }
            // A tag of the owner's own (utm_source=bio) on a link from a known site: the site's group; the tag shows as the campaign.
            if ($site !== null) {
                return $site;
            }
            if (in_array($medium, ['social', 'social-media', 'social_media', 'organic_social', 'sm'], true)) {
                return [self::SOCIAL, self::named($source !== '' ? $source : $medium)];
            }

            return [self::CAMPAIGN, self::named($source !== '' ? $source : $medium)];
        }

        // The site the visitor came from.
        if ($site !== null) {
            return $site;
        }
        if ($touch->host !== '') {
            return str_starts_with($touch->host, 'android-app://')
                ? [self::WEBSITE, 'Android app '.substr($touch->host, strlen('android-app://'))]
                : [self::WEBSITE, $touch->host];
        }

        return [self::DIRECT, 'Direct'];
    }

    /** @return array{0: string, 1: string}|null */
    private static function site(string $host): ?array
    {
        if ($host === '') {
            return null;
        }
        if (str_starts_with($host, 'android-app://')) {
            return self::ANDROID_APPS[substr($host, strlen('android-app://'))] ?? null;
        }

        $base = preg_replace('/^(m|l|lm|mobile|web|out|old|amp|touch|business)\./', '', $host) ?? $host;
        foreach ([$host, $base] as $name) {
            if (isset(self::SITES[$name])) {
                return self::SITES[$name];
            }
        }
        // Google's search in every country: google.com, google.com.eg, google.co.uk...
        if (preg_match('/^google(\.[a-z]{2,3}){1,2}$/', $base) === 1) {
            return [self::SEARCH, 'Google'];
        }
        // A site's own regional or sub-site (en.m.wikipedia.org stays a website; *.facebook.com is Facebook).
        foreach (self::SITES as $name => $place) {
            if (str_ends_with($base, '.'.$name) && in_array($place[0], [self::SOCIAL, self::SEARCH], true)) {
                return $place;
            }
        }

        return null;
    }

    /** A campaign value as the admin reads it: as it was written, short. */
    private static function named(string $value): string
    {
        return mb_substr($value, 0, 60);
    }
}
