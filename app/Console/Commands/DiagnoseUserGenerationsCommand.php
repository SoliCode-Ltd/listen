<?php

namespace App\Console\Commands;

use App\Models\AudioGeneration;
use App\Models\BillingTrial;
use App\Models\BlockedGeneration;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\TtsLog;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\TtsRoutingService;
use App\Support\GeminiTtsModel;
use App\Support\PlanUsageGuard;
use Illuminate\Console\Command;

/**
 * Read-only report of everything that decides whether a user's
 * generation succeeds: plan, quota, routing, Gemini keys, and the
 * real failure reasons the dashboard hides behind a generic message.
 */
class DiagnoseUserGenerationsCommand extends Command
{
    protected $signature = 'listen:diagnose-user
        {user : Email or user id}
        {--limit=20 : Recent generations to show}';

    protected $description = 'Show plan, usage, limits, TTS routing, and real failure reasons for one user.';

    public function handle(PlanAccessService $plans, TtsRoutingService $routing, GeminiKeyManager $keys): int
    {
        $needle = (string) $this->argument('user');
        $user = ctype_digit($needle)
            ? User::find((int) $needle)
            : User::where('email', $needle)->first();

        if (! $user) {
            $this->error("User {$needle} not found.");

            return self::FAILURE;
        }

        $this->section("User #{$user->id} {$user->email}");
        $this->line('Blocked: '.($user->isBlocked() ? 'YES' : 'no'));

        $this->section('Subscriptions (newest first)');
        $subs = Subscription::with('plan')->where('user_id', $user->id)->latest('id')->limit(5)->get();
        $this->table(
            ['id', 'plan', 'status', 'payment state', 'period end', 'grace ends'],
            $subs->map(fn (Subscription $s) => [
                $s->id,
                $s->plan?->slug ?? '-',
                $s->status,
                $s->billing_payment_state ?? '-',
                $this->when($s->current_period_end),
                $this->when($s->billing_grace_ends_at),
            ])->all(),
        );

        $active = $plans->resolveActivePlanForUserId($user->id);
        $quota = $plans->resolveQuotaPlanForUserId($user->id, $active);
        $this->line('Plan used for generation: '.($active?->slug ?? 'none').' (tier '.$plans->resolveTierForPlan($active).')');
        if ($active && (int) $active->price_cents === 0 && $subs->contains(fn ($s) => (int) ($s->plan?->price_cents ?? 0) > 0)) {
            $this->warn('A paid subscription exists but is not active or its period ended, so the user is treated as Free.');
        }
        if ($quota !== $active) {
            $this->warn('Unpaid trial window: quotas come from '.$quota?->slug.' with overage off.');
        }

        $trial = BillingTrial::where('user_id', $user->id)->latest('id')->first();
        if ($trial) {
            $this->line("Latest trial: {$trial->status}, ends ".$this->when($trial->trial_ends_at).', paid '.$this->when($trial->paid_at));
        }

        $this->section('Usage this month vs limits');
        $counter = UsageCounter::where('user_id', $user->id)->where('period_ym', now()->format('Y-m'))->first();
        $planChars = PlanUsageGuard::planChars($quota);
        $planRequests = PlanUsageGuard::planRequests($quota);
        $this->table(['metric', 'used', 'limit'], [
            ['characters', number_format((int) $counter?->total_chars), number_format($planChars)],
            ['requests', number_format((int) $counter?->total_requests), $planRequests > 0 ? number_format($planRequests) : 'unlimited'],
            ['minutes', number_format((int) $counter?->total_minutes), number_format((int) ($quota?->monthly_minutes ?? 0))],
            ['max chars per request', '-', number_format(PlanUsageGuard::maxCharsPerRequest($quota))],
            ['overage allowed', '-', PlanUsageGuard::allowsOverage($quota) ? 'yes' : 'no'],
        ]);
        if (! PlanUsageGuard::allowsOverage($quota) && (int) $counter?->total_chars >= $planChars) {
            $this->warn('Monthly character limit reached: new requests are rejected.');
        }

        $this->section('TTS routing');
        $mode = $routing->getGlobalMode();
        $this->line("Mode: {$mode}   Fallback to Google: ".($routing->shouldFallback($mode) ? 'on' : 'OFF'));
        $this->line('Plan provider: '.($active ? (SiteSetting::get("tts_plan_provider_{$active->slug}") ?: ($active->tts_provider_policy ?: 'default')) : '-'));
        $this->line('Gemini model: '.GeminiTtsModel::resolve().'   timeout '.(int) config('services.gemini_tts.timeout_seconds').'s');
        $this->line('Google Cloud configured: '.((new GoogleCloudTtsProvider)->isAvailable() ? 'yes' : 'NO'));
        $this->line('PHP max_execution_time (CLI): '.ini_get('max_execution_time').'s. Check PHP-FPM and the web server timeout too.');

        $stats = $keys->getAggregateStats();
        $this->line("Gemini keys: {$stats['total_keys']} configured, {$stats['available_keys']} under the local budget");
        $this->table(
            ['key', 'rpm used/limit', 'rpd used/limit'],
            array_map(fn (array $k) => [
                $k['name'].' '.$k['masked_key'],
                $k['stats']['rpm_used'].'/'.$k['stats']['rpm_limit'],
                $k['stats']['rpd_used'].'/'.$k['stats']['rpd_limit'],
            ], $stats['keys']),
        );

        $limit = max(1, (int) $this->option('limit'));
        $this->section("Last {$limit} generations");
        $generations = AudioGeneration::where('user_id', $user->id)->latest('id')->limit($limit)->get();
        $logs = TtsLog::whereIn('audio_generation_id', $generations->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('audio_generation_id');

        $this->table(
            ['#', 'created', 'status', 'chars', 'bytes', 'lang', 'tier', 'provider', 'fallback', 'reason'],
            $generations->map(function (AudioGeneration $g) use ($logs) {
                $log = $logs->get($g->id)?->last();

                return [
                    $g->displayNumber(),
                    $this->when($g->created_at),
                    $g->status,
                    mb_strlen((string) $g->input_text),
                    strlen((string) $g->input_text),
                    $g->target_language,
                    $g->voice_tier,
                    $log?->provider ?? $g->tts_provider ?? '-',
                    ($log?->fallback_used ?? $g->tts_fallback_used) ? 'yes' : 'no',
                    wordwrap(mb_substr((string) preg_replace('/\s+/u', ' ', (string) ($log?->failure_reason ?? $g->error_message ?? '')), 0, 500), 90, "\n", true),
                ];
            })->all(),
        );

        $stuck = $generations->filter(fn (AudioGeneration $g) => in_array($g->status, ['queued', 'processing'], true)
            && $g->created_at?->lt(now()->subMinutes(10)));
        if ($stuck->isNotEmpty()) {
            $this->warn($stuck->count().' generation(s) stuck in queued/processing for over 10 minutes. The web request was probably killed by a timeout, and their reserved usage was not refunded.');
        }

        $blocked = BlockedGeneration::where('user_id', $user->id)->latest('id')->limit(5)->get();
        if ($blocked->isNotEmpty()) {
            $this->section('Requests blocked by the profanity filter');
            $this->table(['created', 'term', 'route'], $blocked->map(fn ($b) => [
                $this->when($b->created_at), $b->matched_term, $b->route_name,
            ])->all());
        }

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info($title);
    }

    private function when(mixed $date): string
    {
        return $date ? $date->format('Y-m-d H:i') : '-';
    }
}
