<?php

namespace App\Support\Agents;

use App\Models\Agent;
use App\Models\AgentSource;
use Illuminate\Support\Str;

/**
 * Looking things up on the business's website during a call (the look_up tool): the passages of
 * its pages (AgentSite) that best answer the agent's question, by their words. Arabic is matched
 * however it is written (أ/إ/ا, ة/ه, ى/ي, with or without "ال" and diacritics, Arabic-Indic
 * digits), and words of one root match (عضوية, عضويتهم). Before the pages are read, the text read
 * when the agent was written is looked up instead.
 */
final class AgentLookup
{
    /** Passages one look-up returns, and their words at most. */
    public const MAX_PASSAGES = 4;

    public const MAX_CHARS = 3_200;

    private const PASSAGE_CHARS = 700;

    private const STOP = [
        'في', 'من', 'على', 'عن', 'الى', 'الي', 'ما', 'ماذا', 'هل', 'كم', 'كيف', 'متي', 'اين', 'هو', 'هي', 'هم', 'انا', 'انت', 'نحن',
        'هذا', 'هذه', 'ذلك', 'تلك', 'التي', 'الذي', 'الذين', 'او', 'ثم', 'مع', 'عند', 'لدي', 'كل', 'بعض', 'اي', 'ايش', 'وش', 'شو', 'ليش', 'لماذا',
        'يا', 'بس', 'كان', 'يكون', 'لكم', 'لك', 'عندكم', 'عندك', 'فيه', 'فيها', 'به', 'بها', 'له', 'لها', 'ان', 'لا', 'نعم', 'قد', 'لقد',
        'the', 'a', 'an', 'of', 'to', 'in', 'on', 'for', 'and', 'or', 'is', 'are', 'was', 'what', 'who', 'when', 'where', 'how', 'much',
        'many', 'do', 'does', 'can', 'you', 'your', 'we', 'our', 'it', 'its', 'this', 'that', 'with', 'about', 'there', 'any', 'be',
    ];

    /** Whether the agent has a website to look things up in. */
    public static function available(Agent $agent): bool
    {
        if (! $agent->exists || ! AgentAccess::tablesReady()) {
            return false;
        }

        return $agent->sources()->whereIn('kind', [AgentSource::PAGE, AgentSource::WEBSITE])->where('status', AgentSource::READY)->where('chars', '>', 0)->exists();
    }

    /** How many pages there are to look things up in (0: only the text read when it was written). */
    public static function pages(Agent $agent): int
    {
        return $agent->exists && AgentAccess::tablesReady() ? $agent->sources()->where('kind', AgentSource::PAGE)->count() : 0;
    }

    /**
     * The passages that best answer $query, the best first.
     *
     * @return list<array{page: string, text: string}>
     */
    public static function search(Agent $agent, string $query, int $limit = self::MAX_PASSAGES): array
    {
        return $agent->exists ? self::searchIn(self::pagesOf($agent), $query, $limit) : [];
    }

    /**
     * The same, in pages given as they are (a check that reads a website without keeping it).
     *
     * @param  list<array{page: string, text: string}>  $pages
     * @return list<array{page: string, text: string}>
     */
    public static function searchIn(array $pages, string $query, int $limit = self::MAX_PASSAGES): array
    {
        $terms = array_values(array_unique(array_filter(self::words($query), fn (string $w): bool => ! in_array($w, self::stops(), true))));
        if ($terms === []) {
            return [];
        }
        $passages = self::passages($pages);
        if ($passages === []) {
            return [];
        }

        // How rare each word is across the passages: a word on every page counts little.
        $df = array_fill_keys($terms, 0);
        foreach ($passages as &$passage) {
            $passage['tokens'] = self::words($passage['page'].' '.$passage['text']);
            $passage['hits'] = [];
            foreach ($terms as $term) {
                $tf = 0;
                foreach ($passage['tokens'] as $token) {
                    if (self::same($term, $token)) {
                        $tf++;
                    }
                }
                if ($tf > 0) {
                    $passage['hits'][$term] = $tf;
                    $df[$term]++;
                }
            }
        }
        unset($passage);
        $n = count($passages);
        $scored = [];
        foreach ($passages as $i => $passage) {
            if ($passage['hits'] === []) {
                continue;
            }
            $score = 0.0;
            foreach ($passage['hits'] as $term => $tf) {
                $score += log(1 + $n / (1 + $df[$term])) * (1 + 0.3 * log($tf));
            }
            // A passage with more of the question's words is the better answer.
            $score *= 1 + count($passage['hits']) / count($terms);
            $scored[$i] = $score;
        }
        arsort($scored);
        // Far weaker passages (a footer that shares one common word) only crowd the answer out.
        $floor = (float) reset($scored) * 0.35;

        $out = [];
        $chars = 0;
        foreach ($scored as $i => $score) {
            if (count($out) >= $limit || $score < $floor) {
                break;
            }
            $text = $passages[$i]['text'];
            if ($chars + mb_strlen($text) > self::MAX_CHARS) {
                $text = Str::limit($text, max(0, self::MAX_CHARS - $chars), '');
                if (mb_strlen($text) < 80) {
                    break;
                }
            }
            $chars += mb_strlen($text);
            $out[] = ['page' => $passages[$i]['page'], 'text' => $text];
        }

        return $out;
    }

    /**
     * The agent's website pages as read (AgentSite), or before they are read, the text read when
     * the agent was written ("## title" each page).
     *
     * @return list<array{page: string, text: string}>
     */
    private static function pagesOf(Agent $agent): array
    {
        $pages = $agent->sources()->where('kind', AgentSource::PAGE)->where('status', AgentSource::READY)->orderBy('id')->get(['title', 'content']);
        $docs = $pages->map(fn (AgentSource $p): array => ['page' => (string) $p->title, 'text' => (string) $p->content])->all();
        if ($docs === []) {
            // The pages are not read yet: the text read when the agent was written ("## title" each page).
            $site = (string) $agent->sources()->where('kind', AgentSource::WEBSITE)->where('status', AgentSource::READY)->value('content');
            foreach (preg_split('/^## /mu', $site) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    [$title, $text] = array_pad(explode("\n", $part, 2), 2, '');
                    $docs[] = ['page' => trim($title), 'text' => trim($text)];
                }
            }
        }

        return $docs;
    }

    /**
     * The pages' words in passages of a few lines, each with its page's title.
     *
     * @param  list<array{page: string, text: string}>  $docs
     * @return list<array{page: string, text: string}>
     */
    private static function passages(array $docs): array
    {
        $out = [];
        foreach ($docs as $doc) {
            $buffer = '';
            foreach (preg_split('/\n+/u', $doc['text']) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                foreach (mb_str_split($line, self::PASSAGE_CHARS) as $piece) {
                    if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($piece) > self::PASSAGE_CHARS) {
                        $out[] = ['page' => $doc['page'], 'text' => $buffer];
                        // The last line of a passage begins the next one, so nothing is cut from its context.
                        $tail = (string) Str::afterLast($buffer, "\n");
                        $buffer = mb_strlen($tail) < 200 && $tail !== $buffer ? $tail : '';
                    }
                    $buffer = $buffer === '' ? $piece : $buffer."\n".$piece;
                }
            }
            if ($buffer !== '') {
                $out[] = ['page' => $doc['page'], 'text' => $buffer];
            }
        }

        return $out;
    }

    /** @return list<string> the words, written one way (see the class comment) */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach (['وال', 'بال', 'كال', 'فال', 'لل', 'ال'] as $prefix) {
                if (str_starts_with($word, $prefix) && mb_strlen($word) - mb_strlen($prefix) >= 2) {
                    $word = mb_substr($word, mb_strlen($prefix));
                    break;
                }
            }
            if (preg_match('/^[a-z]+s$/', $word) && strlen($word) > 3) {
                $word = substr($word, 0, -1);
            }
            $out[] = $word;
        }

        return $out;
    }

    /** One word in two forms: the same, or (three letters or more) the same first four letters. */
    private static function same(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $la = mb_strlen($a);
        $lb = mb_strlen($b);
        if ($la < 3 || $lb < 3 || ctype_digit($a) || ctype_digit($b)) {
            return false;
        }
        $n = min(4, $la, $lb);

        return mb_substr($a, 0, $n) === mb_substr($b, 0, $n) && abs($la - $lb) <= 4;
    }

    /** @return list<string> */
    private static function stops(): array
    {
        static $stops = null;

        return $stops ??= array_values(array_unique(array_merge(...array_map(fn (string $w): array => self::words($w), self::STOP))));
    }
}
