<?php

namespace App\Http\Controllers;

use App\Models\AgentWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * "Report this agent" on an agent's link page: a caller says the agent asked
 * for card numbers or money, posed as someone else, or was offensive. The
 * report is kept with the agent for the team (Admin → Agents, "Reported"), one
 * per network a day; three in a day mark the agent for review.
 */
class AgentReportController extends Controller
{
    /** @var array<string, string> what callers can report, as the team reads it */
    public const REASONS = [
        'scam' => 'Asked for card numbers, passwords or money, or posed as someone else',
        'offensive' => 'Rude, hateful or inappropriate',
        'wrong' => 'Gave wrong or misleading answers',
        'other' => 'Something else',
    ];

    public function store(Request $request): JsonResponse
    {
        $json = json_decode((string) $request->getContent(), true);
        $in = is_array($json) ? $json : $request->all();
        $widget = AgentWidget::where('slug', (string) ($in['slug'] ?? ''))->first();
        $agent = $widget?->agent;
        if (! $widget || ! $agent || ! $widget->link_enabled) {
            return response()->json(['error' => 'not_found'], 404);
        }
        app()->setLocale($agent->language === 'en' ? 'en' : 'ar');
        $thanks = ['ok' => true, 'message' => __('agents.report_thanks')];

        // One report from a network a day: more say nothing new (and are not counted).
        $key = 'agent-report:'.$agent->id.':'.sha1((string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return response()->json($thanks);
        }
        RateLimiter::hit($key, 86400);

        $reason = array_key_exists((string) ($in['reason'] ?? ''), self::REASONS) ? (string) $in['reason'] : 'other';
        $note = Str::limit(trim(strip_tags((string) ($in['note'] ?? ''))), 500, '');
        $settings = (array) ($agent->settings ?? []);
        $reports = array_slice(array_merge((array) ($settings['reports'] ?? []), [[
            'reason' => $reason,
            'note' => $note,
            'at' => now()->toIso8601String(),
            'from' => substr(hash('sha256', (string) $request->ip()), 0, 16),
        ]]), -50);
        $settings['reports'] = $reports;
        // Three reports in a day: marked for the team, whatever the check before going live said.
        $today = collect($reports)->filter(fn ($r) => now()->subDay()->lt(\Illuminate\Support\Carbon::parse($r['at'] ?? now())))->count();
        if ($today >= 3 && ($settings['review']['verdict'] ?? null) !== 'block') {
            $settings['review'] = array_merge((array) ($settings['review'] ?? []), [
                'verdict' => 'review',
                'reason' => 'Reported by '.$today.' callers in a day.',
                'at' => now()->toIso8601String(),
            ]);
        }
        $agent->forceFill(['settings' => $settings])->save();

        return response()->json($thanks);
    }
}
