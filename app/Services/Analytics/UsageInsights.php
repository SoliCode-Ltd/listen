<?php

namespace App\Services\Analytics;

use App\Models\AudioGeneration;
use App\Models\User;
use App\Support\GenerationChoiceLabels;
use App\Support\VoiceTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What members made, for the Monthly usage page: how much, what they chose
 * (voices, accents, emotions, styles, studio features), where and on which days.
 * Read from the generations themselves, so pack characters are included.
 */
final class UsageInsights
{
    /** Rows in each ranked list. */
    public const TOP = 8;

    private const T = 'audio_generations.';

    /** Generations made in a calendar month ("2026-10"); null: every month. */
    public static function month(?string $ym, bool $withoutAdmins = true): Builder
    {
        $query = AudioGeneration::query();
        if ($ym !== null) {
            [$start, $end] = self::bounds($ym);
            $query->whereBetween(self::T.'created_at', [$start, $end]);
        }

        return $withoutAdmins
            ? $query->whereNotIn(self::T.'user_id', User::query()->where('is_admin', true)->select('id'))
            : $query;
    }

    /**
     * One member's generations charged to a credit period ("2026-10" or "2026-10-14 08:37"),
     * found the way App\Support\CreditPeriod::ofGeneration finds a generation's period.
     */
    public static function period(int $userId, string $key): Builder
    {
        return AudioGeneration::query()
            ->where(self::T.'user_id', $userId)
            ->where(function (Builder $q) use ($key): void {
                $q->where(self::T.'settings_snapshot->usage_period', $key)
                    ->orWhere(self::T.'settings_snapshot->reservation->period', $key);
                if (strlen($key) === 7) {
                    // Made before generations kept their period: the calendar month.
                    [$start, $end] = self::bounds($key);
                    $q->orWhere(fn (Builder $old) => $old
                        ->whereNull(self::T.'settings_snapshot->usage_period')
                        ->whereNull(self::T.'settings_snapshot->reservation->period')
                        ->whereBetween(self::T.'created_at', [$start, $end]));
                }
            });
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function bounds(string $ym): array
    {
        $start = Carbon::createFromFormat('!Y-m', $ym)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * @return array{attempts:int, made:int, failed:int, members:int, days:int, seconds:int, chars:int, first:?Carbon, last:?Carbon}
     */
    public function summary(Builder $generations): array
    {
        $attempts = (clone $generations)->count();
        $row = self::made($generations)->toBase()
            ->selectRaw('count(*) as made, count(distinct '.self::T.'user_id) as members, count(distinct DATE('.self::T.'created_at)) as days')
            ->selectRaw('coalesce(sum('.self::T.'duration_seconds), 0) as seconds, coalesce(sum('.self::T.'billable_chars), 0) as chars')
            ->selectRaw('min('.self::T.'created_at) as first, max('.self::T.'created_at) as last')
            ->first();

        return [
            'attempts' => $attempts,
            'made' => (int) ($row->made ?? 0),
            'failed' => (clone $generations)->where(self::T.'status', 'failed')->count(),
            'members' => (int) ($row->members ?? 0),
            'days' => (int) ($row->days ?? 0),
            'seconds' => (int) ($row->seconds ?? 0),
            'chars' => (int) ($row->chars ?? 0),
            'first' => ($row->first ?? null) ? Carbon::parse($row->first) : null,
            'last' => ($row->last ?? null) ? Carbon::parse($row->last) : null,
        ];
    }

    /**
     * Ranked lists of what was made: label => generations. Each share is of every
     * generation made, so a list that is not always chosen (an emotion) adds up to less.
     *
     * @return array{total:int, lists: array<string, array{items: array<string,int>, note: ?string}>}
     */
    public function breakdowns(Builder $generations): array
    {
        $made = self::made($generations);
        $lists = [
            'Where they made it' => [$this->sources($made), 'The browser extension is counted apart from the studio from this update on; earlier extension use shows under Studio.'],
            'Studio features used' => [$this->features($made), null],
            'Voice type' => [$this->ranked($made, 'voice_tier', fn (string $v): string => VoiceTier::label(VoiceTier::normalize($v))), null],
            'Language' => [$this->ranked($made, 'target_language', fn (string $v): string => GenerationChoiceLabels::language($v)), null],
            'Voices' => [$this->ranked($made, 'voice_name', fn (string $v): string => self::withoutCode(GenerationChoiceLabels::voice($v))), 'Dialogues count their first speaker\'s voice.'],
            'Accents' => [$this->ranked($made, 'dialect', fn (string $v): string => self::withoutCode(GenerationChoiceLabels::accent($v))), null],
            'Emotions' => [$this->ranked($made, 'emotion', fn (string $v): string => GenerationChoiceLabels::emotion($v)), null],
            'Performance styles' => [$this->ranked($made, 'performance_style', fn (string $v): string => GenerationChoiceLabels::performanceStyle($v)), null],
            'Sound environments' => [$this->ranked($made, 'settings_snapshot->audio_effect', fn (string $v): string => self::withoutCode(GenerationChoiceLabels::environment($v))), null],
        ];

        return [
            'total' => (clone $made)->count(),
            'lists' => array_map(fn (array $list): array => ['items' => $list[0], 'note' => $list[1]], array_filter($lists, fn (array $list): bool => $list[0] !== [])),
        ];
    }

    /**
     * Generations made and members who made them, for every day of the month up to today.
     *
     * @return array<string, array{generations:int, members:int}> keyed by "2026-10-27"
     */
    public function daily(Builder $generations, string $ym): array
    {
        $rows = self::made($generations)->toBase()
            ->selectRaw('DATE('.self::T.'created_at) as day, count(*) as generations, count(distinct '.self::T.'user_id) as members')
            ->groupByRaw('DATE('.self::T.'created_at)')
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->day, 0, 10));

        [$day, $end] = self::bounds($ym);
        $end = $end->min(now()->endOfDay());
        $days = [];
        for (; $day->lte($end); $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $days[$day->toDateString()] = ['generations' => (int) ($row->generations ?? 0), 'members' => (int) ($row->members ?? 0)];
        }

        return $days;
    }

    /** Members whose first generation ever was made in the month. */
    public function firstTimers(string $ym, bool $withoutAdmins = true): int
    {
        [$start, $end] = self::bounds($ym);
        $firsts = self::month(null, $withoutAdmins)->toBase()
            ->selectRaw(self::T.'user_id, min('.self::T.'created_at) as first_made')
            ->groupBy(self::T.'user_id');

        return DB::query()->fromSub($firsts, 'firsts')->whereBetween('first_made', [$start, $end])->count();
    }

    /** "عادل · Adel (Male) — ar-XA-Chirp3-HD-Rasalgeth" → "عادل · Adel (Male)": the name members see, short enough for a list. */
    private static function withoutCode(string $label): string
    {
        return preg_replace('/ — [^ ]+$/u', '', $label) ?? $label;
    }

    /** Everything but failures: queued, being made and made. */
    private static function made(Builder $generations): Builder
    {
        return (clone $generations)->where(self::T.'status', '!=', 'failed');
    }

    /**
     * @param  callable(string): string  $label
     * @return array<string, int>
     */
    private function ranked(Builder $made, string $column, callable $label): array
    {
        $column = self::T.$column;
        $rows = (clone $made)->toBase()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->select($column.' as choice')
            ->selectRaw('count(*) as n')
            ->groupBy('choice')
            ->orderByDesc('n')
            ->limit(self::TOP)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $name = $label((string) $row->choice);
            $items[$name] = ($items[$name] ?? 0) + (int) $row->n;
        }
        arsort($items);

        return $items;
    }

    /** @return array<string, int> */
    private function sources(Builder $made): array
    {
        $labels = ['message' => 'Studio', 'extension' => 'Browser extension', 'api' => 'Developer API'];
        $rows = (clone $made)->toBase()
            ->leftJoin('text_items', 'text_items.id', '=', self::T.'text_item_id')
            ->selectRaw('CASE WHEN '.self::T."api_key_id IS NOT NULL THEN 'api' ELSE COALESCE(text_items.source_type, '') END as choice, count(*) as n")
            ->groupBy('choice')
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $name = $labels[(string) $row->choice] ?? 'Not recorded';
            $items[$name] = ($items[$name] ?? 0) + (int) $row->n;
        }
        arsort($items);

        return $items;
    }

    /** @return array<string, int> */
    private function features(Builder $made): array
    {
        $count = fn (callable $where): int => $where(clone $made)->count();
        $items = array_filter([
            'Dialogue (several speakers)' => $count(fn (Builder $q) => $q->where(self::T.'is_dialogue', true)),
            'Translated, then read' => $count(fn (Builder $q) => $q->where(self::T.'translate', true)),
            'Sound environment' => $count(fn (Builder $q) => $q->whereNotNull(self::T.'settings_snapshot->audio_effect')->where(self::T.'settings_snapshot->audio_effect', '!=', '')),
            'Their own cloned voice' => $count(fn (Builder $q) => $q->where(self::T.'voice_name', 'like', 'cloned_%')),
            'Own style direction' => $count(fn (Builder $q) => $q->whereNotNull(self::T.'style_prompt')->where(self::T.'style_prompt', '!=', '')),
            'Paid from a credit pack' => $count(fn (Builder $q) => $q->where(self::T.'billed_to_pack', true)),
        ]);
        arsort($items);

        return $items;
    }
}
