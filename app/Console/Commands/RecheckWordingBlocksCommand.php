<?php

namespace App\Console\Commands;

use App\Models\BlockedGeneration;
use App\Models\User;
use App\Services\BlockedUserService;
use App\Services\ProfanityFilter;
use App\Support\PaidMember;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The old word list refused ordinary words ("x", "الزريبة", the start of
 * "يا ابن الحلال"), and two refused texts in a day block an account by
 * themselves. This re-checks every account the wording rule blocked on its
 * own against today's list and rule: two texts it still refuses, sent from
 * the site within a day, by a member who has never paid. The rest are
 * unblocked with --apply. Accounts an admin blocked, and accounts blocked for
 * another reason, are left alone. Prints member ids and words, never a text.
 */
class RecheckWordingBlocksCommand extends Command
{
    protected $signature = 'listen:recheck-wording-blocks
        {--apply : Unblock the accounts today\'s rule would not have blocked}';

    protected $description = 'Re-check the accounts the wording rule blocked by itself against today\'s word list and rule; a dry run unless --apply.';

    // The browser extension's reader: its texts come from other sites and never block an account.
    private const READER_ROUTE = 'api.reader.queue';

    public function handle(BlockedUserService $blocks, ProfanityFilter $filter): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Unblocking the accounts today\'s rule would not have blocked.' : 'Dry run: nothing changes (add --apply to unblock).');

        $members = User::query()
            ->where('is_blocked', true)
            ->whereNull('blocked_by')
            ->where(fn ($query) => $query->where('blocked_reason_en', BlockedUserService::DEFAULT_REASON_EN)
                ->orWhere('blocked_reason_ar', BlockedUserService::DEFAULT_REASON_AR));
        $this->line('Accounts the wording rule blocked by itself: '.(clone $members)->count());

        $kept = 0;
        $freed = [];
        $members->orderBy('id')->chunkById(100, function ($chunk) use ($blocks, $filter, $apply, &$kept, &$freed): void {
            foreach ($chunk as $member) {
                $texts = BlockedGeneration::query()
                    ->where('user_id', $member->id)
                    ->where(fn ($query) => $query->whereNull('route_name')->orWhere(fn ($named) => $named
                        ->where('route_name', 'not like', 'v1.%')
                        ->where('route_name', '<>', self::READER_ROUTE)))
                    ->orderBy('created_at')
                    ->get(['created_at', 'input_text', 'matched_term']);

                $refusedAt = [];
                $wordsNow = [];
                $wordsThen = [];
                foreach ($texts as $text) {
                    $wordsThen[(string) $text->matched_term] = ($wordsThen[(string) $text->matched_term] ?? 0) + 1;
                    $match = $filter->firstMatch((string) $text->input_text);
                    if ($match !== null) {
                        $refusedAt[] = Carbon::instance($text->created_at);
                        $wordsNow[$match['term']] = ($wordsNow[$match['term']] ?? 0) + 1;
                    }
                }

                // Today's rule: two refused texts within a day, never for a member who pays or has paid.
                $stays = ! PaidMember::is($member) && self::twoWithinADay($refusedAt);
                $line = sprintf('  #%-6d refused %d, refused today %d', $member->id, $texts->count(), count($refusedAt));
                if ($stays) {
                    $kept++;
                    $this->line($line.': stays blocked ('.self::words($wordsNow).')');

                    continue;
                }

                $freed[] = $member->id;
                $this->line($line.': '.($apply ? 'UNBLOCKED' : 'would be unblocked').' (refused then for: '.self::words($wordsThen).')');
                if ($apply) {
                    // Not trusted for later: the rule made a mistake, nobody vouched for the member.
                    $blocks->unblock($member, trust: false);
                    Log::info('Unblocked an account the old word list blocked by mistake.', [
                        'user_id' => $member->id,
                        'texts' => $texts->count(),
                        'refused_today' => count($refusedAt),
                    ]);
                }
            }
        });

        if ($apply && $freed !== []) {
            Cache::forget('admin:suspicious_users_count');
        }
        $this->line(sprintf('Stay blocked: %d. %s: %d%s', $kept, $apply ? 'Unblocked' : 'Would be unblocked', count($freed),
            $freed === [] ? '' : ' ('.implode(', ', $freed).')'));

        return self::SUCCESS;
    }

    /**
     * @param  list<Carbon>  $times  in order
     */
    private static function twoWithinADay(array $times): bool
    {
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            if ($times[$i - 1]->copy()->addDay()->gte($times[$i])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private static function words(array $counts): string
    {
        arsort($counts);
        $parts = [];
        foreach ($counts as $word => $n) {
            $parts[] = ($word === '' ? '?' : $word).' ×'.$n;
        }

        return $parts === [] ? 'none' : implode(', ', $parts);
    }
}
