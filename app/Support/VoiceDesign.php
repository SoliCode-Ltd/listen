<?php

namespace App\Support;

use App\Models\DesignedVoice;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use Throwable;

/**
 * Who may use Voice Design, what a plan includes, and the studio's choices.
 *
 * Until an admin opens it to everyone (Voice Design → Settings), only the
 * admin email sees it, so the files can sit on the live site unseen. Then
 * every signed-in member sees the section: a plan with Voice Design switched
 * on (Admin → Plans) designs and keeps voices, paying for the takes it hears
 * in credits; any other plan sees what it does with a way to upgrade. Free
 * accounts can try one take in the demo, from the demo's own characters.
 *
 * A design is the member's description, shaped by three choices (projection,
 * pace and how far the takes may wander from the words), made into one to
 * three takes, each a voice of its own that speaks the same line.
 */
final class VoiceDesign
{
    public const AUDIENCE_PREVIEW = 'preview';

    public const AUDIENCE_EVERYONE = 'everyone';

    /** Switched off for everyone, the admin included. */
    public const AUDIENCE_OFF = 'off';

    public const GROUP = 'voice_design';

    public const LANGUAGES = ['ar', 'en'];

    public const GENDERS = ['female', 'male', 'neutral'];

    public const MIN_DESCRIPTION = 20;

    public const MAX_DESCRIPTION = 1000;

    public const MIN_PREVIEW = 20;

    /** The longest line the takes speak on the dashboard (the demo: what is left of the demo's characters). */
    public const MAX_PREVIEW = 300;

    /** The shaping sliders run from 0 to 100; 50 leaves the description as it is. */
    public const SLIDER_MIDDLE = 50;

    /** Where each example character's clip is kept (made by voice-design:examples, committed with the code). */
    public const EXAMPLES_DIR = 'audio/voice-design/examples';

    /** The orbs kept voices wear (App\Support\Orbs), one after another by id. */
    public const VOICE_ORBS = ['arabic', 'emotions', 'english', 'dialogue', 'longform', 'creator', 'pro', 'places'];

    public static function audience(): string
    {
        $value = (string) self::setting('voice_design_audience', self::AUDIENCE_PREVIEW);

        return in_array($value, [self::AUDIENCE_EVERYONE, self::AUDIENCE_OFF], true) ? $value : self::AUDIENCE_PREVIEW;
    }

    public static function isPreviewOnly(): bool
    {
        return self::audience() === self::AUDIENCE_PREVIEW;
    }

    /** Whether this visitor sees Voice Design at all (the section, the demo's tab). */
    public static function openTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return match (self::audience()) {
            // Everyone, once the owner has turned it on for a plan; until then the admin email alone.
            self::AUDIENCE_EVERYONE => self::anyPlanIncludes() || VoiceCloningAccess::isPreviewAccount($user),
            self::AUDIENCE_OFF => false,
            default => VoiceCloningAccess::isPreviewAccount($user),
        };
    }

    /**
     * Whether the owner has turned Voice Design on for any plan (each starts
     * without it), on sale or not, so members kept on an old plan keep it.
     */
    public static function anyPlanIncludes(): bool
    {
        return (bool) rescue(fn () => Plan::query()->where('voice_design_enabled', true)->exists(), false, false);
    }

    public static function planIncludes(?Plan $plan): bool
    {
        return (bool) ($plan?->voice_design_enabled ?? false);
    }

    /**
     * Whether this member may design and keep voices, and speak with them:
     * their plan includes it. The admin email may always, to try it before
     * any plan has it.
     */
    public static function allows(?User $user, ?Plan $plan): bool
    {
        if (! self::openTo($user)) {
            return false;
        }

        return self::planIncludes($plan) || VoiceCloningAccess::isPreviewAccount($user);
    }

    /**
     * The section to try, with the way to a plan that designs in place of
     * its button: only while a plan with Voice Design is on sale.
     */
    public static function showsUpgrade(?User $user, ?Plan $plan): bool
    {
        return self::openTo($user) && ! self::allows($user, $plan) && self::cheapestPlan() !== null;
    }

    /** The cheapest plan on sale with Voice Design, for the upgrade button. */
    public static function cheapestPlan(): ?Plan
    {
        return rescue(fn () => Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)
            ->where('voice_design_enabled', true)->orderBy('price_cents')->first(), null, false);
    }

    /** Where the upgrade button goes: the billing page on the cheapest plan with Voice Design. */
    public static function upgradeUrl(): string
    {
        $plan = self::cheapestPlan();

        return $plan ? route('billing.page', ['plan' => $plan->slug]) : route('pricing');
    }

    /**
     * How many designed voices a member of this plan keeps: the plan's number,
     * which the owner sets on turning it on; the site's for the admin email
     * trying it on a plan without it.
     */
    public static function maxVoices(?Plan $plan): int
    {
        $own = (int) ($plan?->voice_design_max_voices ?? 0);

        return $own > 0 ? $own : max(1, (int) self::setting('voice_design_max_per_user', 10));
    }

    /** New voices a member of this plan may keep in a credit month; 0 when only the library's size counts. */
    public static function monthlyLimit(?Plan $plan): int
    {
        return max(0, (int) ($plan?->voice_design_monthly_voices ?? 0));
    }

    /**
     * Voices the member kept in their current credit month, deleted ones too:
     * deleting a voice gives back room in the library, not in the month.
     */
    public static function keptThisMonth(int $userId): int
    {
        $period = CreditPeriod::current($userId);

        return DesignedVoice::withTrashed()->where('user_id', $userId)->whereNotNull('kept_at')
            ->where('kept_at', '>=', $period['starts_at'])->count();
    }

    /**
     * Why this member cannot keep another voice now (their library is full,
     * or the month's new voices are used), or null when they can.
     */
    public static function keepBlocker(int $userId, ?Plan $plan): ?string
    {
        $max = self::maxVoices($plan);
        if (DesignedVoice::query()->ownedBy($userId)->saved()->count() >= $max) {
            return __('voice_design.errors.library_full', ['max' => $max]);
        }
        $monthly = self::monthlyLimit($plan);
        if ($monthly > 0 && self::keptThisMonth($userId) >= $monthly) {
            return trans_choice('voice_design.errors.month_used', $monthly, ['count' => $monthly]);
        }

        return null;
    }

    /** Takes a design makes on the dashboard: each is a voice of its own, speaking the same line. */
    public static function takes(): int
    {
        return max(1, min(3, (int) self::setting('voice_design_takes', 3)));
    }

    /** Designs a member may start in an hour (the takes of one design count once). */
    public static function hourlyLimit(): int
    {
        return max(1, (int) self::setting('voice_design_hourly_limit', 12));
    }

    /** The saved voices this member keeps, newest first. */
    public static function savedFor(int $userId)
    {
        return DesignedVoice::query()->ownedBy($userId)->saved()->orderByDesc('created_at')->get();
    }

    /**
     * What one design costs: the line, spoken by each take, as the studio
     * charges a second of audio.
     *
     * @return array{chars:int,words:int,seconds:int,minutes:int,credits:int}
     */
    public static function estimate(string $line, int $takes): array
    {
        $one = UsageEstimator::estimate($line, VoiceTier::HD);
        $takes = max(1, $takes);
        $seconds = $one['seconds'] * $takes;

        return ['chars' => $one['chars'] * $takes, 'words' => $one['words'] * $takes, 'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60), 'credits' => $one['credits'] * $takes];
    }

    /**
     * The description as the engine is given it: the member's words, then the
     * projection and pace they chose, then this take's own touch (none for a
     * take that keeps to the words). Permanent traits belong in the design and
     * not in the delivery, so they are all said here, once.
     */
    public static function enginePrompt(string $description, int $projection, int $pace, int $variety, int $take): string
    {
        $parts = [rtrim(trim($description), '.').'.'];
        $projectionLine = self::sliderLine($projection, [
            'Speaks very softly, close and intimate, barely above a whisper.',
            'Speaks gently, with a soft, unforced voice.',
            null,
            'Projects with a full, confident voice.',
            'Projects powerfully, a big resonant voice that fills the room.',
        ]);
        $paceLine = self::sliderLine($pace, [
            'Takes their time, slow and deliberate, with long pauses.',
            'Speaks at an unhurried, measured pace.',
            null,
            'Speaks briskly, with quick energy.',
            'Speaks fast, rapid-fire, words tumbling out.',
        ]);
        foreach ([$projectionLine, $paceLine] as $line) {
            if ($line !== null) {
                $parts[] = $line;
            }
        }
        $touch = self::takeTouch($variety, $take);
        if ($touch !== null) {
            $parts[] = $touch;
        }

        return implode(' ', $parts);
    }

    /**
     * Each take after the first, on a looser setting, leans a different way,
     * so three takes are three choices rather than one voice three times.
     */
    public static function takeTouch(int $variety, int $take): ?string
    {
        if ($take <= 1 || $variety < 34) {
            return null;
        }
        $wide = $variety >= 67;
        $touches = $wide
            ? [2 => 'Interpret it boldly: a richer, more textured and characterful timbre.', 3 => 'Interpret it freely: a brighter, lighter timbre with a distinctive edge.']
            : [2 => 'A slightly warmer, deeper timbre.', 3 => 'A slightly brighter, crisper timbre.'];

        return $touches[$take] ?? null;
    }

    /**
     * @param  array{0:?string,1:?string,2:?string,3:?string,4:?string}  $lines  from 0 to 100, in five steps
     */
    private static function sliderLine(int $value, array $lines): ?string
    {
        $value = max(0, min(100, $value));
        $step = match (true) {
            $value <= 15 => 0,
            $value <= 38 => 1,
            $value < 62 => 2,
            $value < 85 => 3,
            default => 4,
        };

        return $lines[$step];
    }

    /**
     * The characters to start from: a name, the description, and an orb
     * palette (App\Support\Orbs). Both languages' members see all of them,
     * Arabic dialect characters first for an Arabic page.
     *
     * Each says its line in its own voice (clip: null until voice-design:examples has made it).
     *
     * @return list<array{key:string,name:string,description:string,language:string,orb:string,line:string,clip:?string}>
     */
    public static function examples(?string $locale = null): array
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';
        $rows = [];
        foreach (array_keys((array) trans('voice_design.examples', [], 'en')) as $key) {
            $clip = self::EXAMPLES_DIR.'/'.$key.'.mp3';
            $rows[] = [
                'key' => (string) $key,
                'name' => (string) __('voice_design.examples.'.$key.'.name'),
                'description' => (string) __('voice_design.examples.'.$key.'.description'),
                'language' => (string) trans('voice_design.examples.'.$key.'.language', [], 'en'),
                'orb' => (string) trans('voice_design.examples.'.$key.'.orb', [], 'en'),
                'line' => (string) trans('voice_design.examples.'.$key.'.line', [], 'en'),
                'clip' => is_file(public_path($clip)) ? VersionedAsset::url($clip) : null,
            ];
        }
        if ($arabic) {
            usort($rows, fn (array $a, array $b): int => ($a['language'] === 'ar' ? 0 : 1) <=> ($b['language'] === 'ar' ? 0 : 1));
        }

        return $rows;
    }

    /**
     * What the Voice Design card plays around its orb: the designed voices an
     * admin chose (Admin → Designed voices → On the card), then the example
     * characters, each saying its own line. key: the example's, so picking that
     * character in the form plays it.
     *
     * @return list<array{name:string,text:string,url:string,orb:string,key:?string}>
     */
    public static function showcase(): array
    {
        $chosen = rescue(fn () => DesignedVoice::query()->saved()->where('is_showcase', true)->whereNotNull('preview_audio_path')
            ->orderBy('id')->limit(8)->get()
            ->map(fn (DesignedVoice $voice): array => [
                'name' => (string) $voice->name,
                'text' => (string) $voice->preview_text,
                'url' => (string) AudioUrl::fromPath($voice->preview_audio_path),
                'orb' => self::orbFor($voice),
                'key' => null,
            ])->all(), [], false);
        $examples = array_map(fn (array $e): array => ['name' => $e['name'], 'text' => $e['line'], 'url' => (string) $e['clip'], 'orb' => $e['orb'], 'key' => $e['key']],
            array_values(array_filter(self::examples(), fn (array $e): bool => $e['clip'] !== null)));

        return array_slice([...$chosen, ...$examples], 0, 10);
    }

    /**
     * Seconds of audio a new voice's own sample holds, for the admin's cost
     * figures: the speech service sends one with every voice it makes and
     * bills it as audio. Measured on the ten example voices (October 2026):
     * 14 to 39 seconds, 27 on average, so 30 to stay on the high side.
     */
    public const SAMPLE_SECONDS_ASSUMED = 30;

    /**
     * What one design costs us, for the admin (never shown to members): the
     * takes speaking a typical written line, plus each new voice's own sample,
     * at the speech service's list prices (App\Support\PlanCostEstimate), and
     * what the member is charged for it in credits.
     *
     * @return array{takes:int, line_seconds:int, audio_seconds:int, credits:int, usd:float, usd_high:float, usd_2027:float, demo_usd:float, demo_usd_2027:float}
     */
    public static function costEstimate(?int $takes = null): array
    {
        $takes ??= self::takes();
        // The longer of the two languages' lines, so the figures are the higher ones.
        $lines = [self::estimate((string) trans('voice_design.fallback_line', [], 'ar'), 1), self::estimate((string) trans('voice_design.fallback_line', [], 'en'), 1)];
        $line = $lines[0]['seconds'] >= $lines[1]['seconds'] ? $lines[0] : $lines[1];
        $lineSeconds = $line['seconds'];
        $perTake = $lineSeconds + self::SAMPLE_SECONDS_ASSUMED;
        $usd = function (int $seconds, int $tokensPerSecond, ?\Illuminate\Support\Carbon $on = null): float {
            $price = PlanCostEstimate::geminiPrices($on);

            return round($seconds * $tokensPerSecond / 1_000_000 * $price['audio'], 4);
        };
        $later = \Illuminate\Support\Carbon::create(2027, 1, 1);

        return [
            'takes' => $takes,
            'line_seconds' => $lineSeconds,
            'audio_seconds' => $perTake * $takes,
            'credits' => $line['credits'] * $takes,
            'usd' => $usd($perTake * $takes, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND),
            'usd_high' => $usd($perTake * $takes, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND_HIGH),
            'usd_2027' => $usd($perTake * $takes, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND_HIGH, $later),
            'demo_usd' => $usd($perTake, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND_HIGH),
            'demo_usd_2027' => $usd($perTake, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND_HIGH, $later),
        ];
    }

    /** costEstimate() in a sentence, for the admin's plan and settings pages. */
    public static function costSummary(?int $takes = null): string
    {
        $c = self::costEstimate($takes);

        return sprintf(
            'A design makes %d %s, each speaking a line of about %d seconds and coming with its own sample of the voice (up to about %d seconds, billed as audio): about %d seconds of audio, which costs us about $%s ($%s at the high token rate, $%s from January 2027). The member is charged %s credits. A demo design (one take) costs us about $%s ($%s from January 2027). Writing the line or the description is text only and costs a fraction of a cent. Keeping a voice costs nothing more, and speaking with it later costs the same as any premium voice.',
            $c['takes'], $c['takes'] === 1 ? 'take' : 'takes', $c['line_seconds'], self::SAMPLE_SECONDS_ASSUMED, $c['audio_seconds'],
            number_format($c['usd'], 3), number_format($c['usd_high'], 3), number_format($c['usd_2027'], 3),
            number_format($c['credits']), number_format($c['demo_usd'], 3), number_format($c['demo_usd_2027'], 3),
        );
    }

    /**
     * A take or a kept voice as the studio shows it.
     *
     * @return array<string, mixed>
     */
    public static function present(DesignedVoice $voice): array
    {
        $language = $voice->language === 'en' ? __('voice_design.language_en') : __('voice_design.language_ar');

        return [
            'id' => $voice->id,
            'orb' => self::orbFor($voice),
            // Its face in the studio's voice library and speaker menu, as the stock speakers have theirs.
            'avatar' => self::avatar($voice),
            'tag' => __('ui.dashboard.library.speaks', ['language' => $language]),
            'key' => $voice->voiceKey(),
            'take' => $voice->take,
            'name' => $voice->name,
            'status' => $voice->status,
            'description' => $voice->description,
            'language' => $voice->language,
            'line' => $voice->preview_text,
            'url' => AudioUrl::fromPath($voice->preview_audio_path),
            'seconds' => data_get($voice->metadata, 'seconds'),
            'created' => $voice->created_at?->locale(app()->getLocale() === 'ar' ? 'ar' : 'en')->isoFormat('D MMM YYYY'),
        ];
    }

    public static function orbFor(DesignedVoice $voice): string
    {
        return self::VOICE_ORBS[(int) $voice->id % count(self::VOICE_ORBS)];
    }

    /** The voice's orb as a small picture (an SVG data address), for the studio's voice cards. */
    public static function avatar(DesignedVoice $voice): string
    {
        [$deep, $mid, $light, $glow] = Orbs::colors(self::orbFor($voice));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs>'
            .'<radialGradient id="o" cx="34%" cy="30%" r="78%"><stop offset="0" stop-color="'.$glow.'"/><stop offset=".3" stop-color="'.$light.'"/>'
            .'<stop offset=".68" stop-color="'.$mid.'"/><stop offset="1" stop-color="'.$deep.'"/></radialGradient>'
            .'<radialGradient id="r" cx="50%" cy="50%" r="50%"><stop offset=".82" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#fff" stop-opacity=".28"/></radialGradient>'
            .'</defs><circle cx="32" cy="32" r="32" fill="url(#o)"/><circle cx="32" cy="32" r="32" fill="url(#r)"/></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Everything the studio shows a member (the dashboard's section, or the
     * demo's one take): what they may do, what is left, and the choices.
     *
     * @return array<string, mixed>
     */
    public static function studio(User $user, ?Plan $plan, bool $demo = false): array
    {
        $allowed = self::allows($user, $plan);
        $cheapest = self::cheapestPlan();
        $arabic = app()->getLocale() === 'ar';
        $voices = $allowed && ! $demo ? self::savedFor((int) $user->id) : collect();
        $typical = fn (string $language): int => self::estimate((string) trans('voice_design.fallback_line', [], $language), 1)['credits'];

        return [
            'demo' => $demo,
            'allowed' => $allowed,
            'preview_only' => self::isPreviewOnly(),
            'upgrade_url' => self::upgradeUrl(),
            'upgrade_plan' => $cheapest ? ($arabic && filled($cheapest->name_ar) ? (string) $cheapest->name_ar : (string) $cheapest->name) : null,
            'takes' => $demo ? 1 : self::takes(),
            'max_voices' => self::maxVoices($plan),
            'monthly' => self::monthlyLimit($plan),
            'kept_month' => $allowed && ! $demo ? self::keptThisMonth((int) $user->id) : 0,
            'voices' => $voices->map(fn (DesignedVoice $voice): array => self::present($voice))->values()->all(),
            'examples' => self::examples(),
            'showcase' => self::showcase(),
            'language' => $arabic ? 'ar' : 'en',
            'limits' => [
                'description_min' => self::MIN_DESCRIPTION,
                'description_max' => self::MAX_DESCRIPTION,
                'line_min' => self::MIN_PREVIEW,
                'line_max' => $demo ? min(self::MAX_PREVIEW, DemoAllowance::chars()) : self::MAX_PREVIEW,
            ],
            // Credits one take costs speaking a written line, by language, before the line is known.
            'typical_credits' => ['ar' => $typical('ar'), 'en' => $typical('en')],
            // Characters such a line takes from the demo's allowance.
            'typical_chars' => ['ar' => UsageEstimator::estimate((string) trans('voice_design.fallback_line', [], 'ar'))['chars'], 'en' => UsageEstimator::estimate((string) trans('voice_design.fallback_line', [], 'en'))['chars']],
            'urls' => [
                'design' => $demo ? route('demo.voice-design') : route('voice-design.design'),
                'enhance' => route('voice-design.enhance'),
                'keep' => route('voice-design.keep', ['id' => '__ID__']),
                'destroy' => route('voice-design.destroy', ['id' => '__ID__']),
                'studio' => route('dashboard').'#generateVoiceSection',
                'section' => route('dashboard').'#voiceDesignSection',
            ],
        ];
    }

    private static function setting(string $key, mixed $default): mixed
    {
        try {
            return SiteSetting::get($key, $default);
        } catch (Throwable) {
            // Before the settings table exists on a server.
            return $default;
        }
    }
}
