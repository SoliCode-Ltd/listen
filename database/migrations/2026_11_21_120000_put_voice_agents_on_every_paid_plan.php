<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voice agents on every paid plan but Basic (the owner's choice), with the settings that keep at
 * least 40% on a typical call at each plan's price (App\Support\Agents\AgentCost; the call rate
 * itself follows the site's plans): Starter 1 agent, Creator 3, Pro 5, Studio Max 10 with at most
 * 600 call minutes a month; calls at once and the longest call grow with the plan. Free and Basic
 * have none.
 *
 * And the agents line on each plan's own pricing-card lists (Admin → Plans → Plan Features, the
 * lists dragged into order), in English and Arabic, before the commercial-use line. Its value is
 * {agents}: the card writes it from the plan's settings ("3 agents, up to 94 call minutes"), so
 * it changes when they do, and it shows only once Agents are open to everyone. A list that already
 * has an agents line keeps its own; a plan without its own lists shows the automatic line already.
 * Every number can be changed on the plan afterwards.
 */
return new class extends Migration
{
    /** @var array<string, array{agents: int, minutes: int, concurrent: int, call_minutes: int}> */
    private const PLANS = [
        'starter' => ['agents' => 1, 'minutes' => 0, 'concurrent' => 2, 'call_minutes' => 5],
        'creator' => ['agents' => 3, 'minutes' => 0, 'concurrent' => 3, 'call_minutes' => 8],
        'pro' => ['agents' => 5, 'minutes' => 0, 'concurrent' => 5, 'call_minutes' => 10],
        'studio_max' => ['agents' => 10, 'minutes' => 600, 'concurrent' => 10, 'call_minutes' => 10],
    ];

    private const WITHOUT = ['free', 'basic'];

    /** @var array<string, array{label: string, value: string, icon: string}> */
    private const LINES = [
        'features_en' => ['label' => 'Voice agents', 'value' => '{agents}', 'icon' => 'users'],
        'features_ar' => ['label' => 'الوكلاء الصوتيون', 'value' => '{agents}', 'icon' => 'users'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'agents_enabled')) {
            return;
        }
        foreach (self::PLANS as $slug => $n) {
            DB::table('plans')->where('slug', $slug)->update([
                'agents_enabled' => true,
                'agents_max' => $n['agents'],
                'agent_minutes_per_month' => $n['minutes'],
                'agent_concurrent_calls' => $n['concurrent'],
                'agent_max_call_minutes' => $n['call_minutes'],
            ]);
        }
        DB::table('plans')->whereIn('slug', self::WITHOUT)->update(['agents_enabled' => false, 'agents_max' => 0, 'agent_minutes_per_month' => 0]);

        foreach (DB::table('plans')->whereIn('slug', array_keys(self::PLANS))->get() as $plan) {
            $updates = [];
            foreach (self::LINES as $column => $line) {
                if (! Schema::hasColumn('plans', $column)) {
                    continue;
                }
                $lines = json_decode((string) ($plan->{$column} ?? ''), true);
                if (! is_array($lines) || $lines === [] || $this->hasAgentsLine($lines)) {
                    continue;
                }
                array_splice($lines, $this->place($lines), 0, [$line]);
                $updates[$column] = json_encode(array_values($lines), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($updates !== []) {
                DB::table('plans')->where('id', $plan->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        foreach (DB::table('plans')->get() as $plan) {
            $updates = [];
            foreach (array_keys(self::LINES) as $column) {
                if (! Schema::hasColumn('plans', $column)) {
                    continue;
                }
                $lines = json_decode((string) ($plan->{$column} ?? ''), true);
                if (! is_array($lines)) {
                    continue;
                }
                $kept = array_values(array_filter($lines, fn ($l) => ! (is_array($l) && ($l['value'] ?? null) === '{agents}')));
                if (count($kept) !== count($lines)) {
                    $updates[$column] = json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            if ($updates !== []) {
                DB::table('plans')->where('id', $plan->id)->update($updates);
            }
        }
    }

    /** @param array<int, mixed> $lines */
    private function hasAgentsLine(array $lines): bool
    {
        foreach ($lines as $l) {
            $text = is_array($l) ? implode(' ', array_filter([$l['label'] ?? '', $l['value'] ?? ''], 'is_string')) : (string) $l;
            if (str_contains($text, '{agents}') || preg_match('/voice agents?|الوكلاء الصوتي|وكلاء صوتي|وكيل صوتي/iu', $text)) {
                return true;
            }
        }

        return false;
    }

    /** Before the commercial-use line, where a plan's features end and its terms begin; else last. @param array<int, mixed> $lines */
    private function place(array $lines): int
    {
        foreach (array_values($lines) as $i => $l) {
            if (is_array($l) && preg_match('/commercial|الاستخدام التجاري/iu', (string) ($l['label'] ?? ''))) {
                return $i;
            }
        }

        return count($lines);
    }
};
