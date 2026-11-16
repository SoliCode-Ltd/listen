<?php

namespace App\Services\Newsletter;

use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\User;
use App\Support\SiteFont;
use App\Support\Transcription\TranscriptionAccess;
use Illuminate\Support\Facades\URL;

class NewsletterRenderer
{
    /** The person's plan, read once for both languages of one email. @var array<string, Plan|null> */
    private array $plans = [];

    /**
     * The emails' font: the website's own (Admin → Site Settings, App\Support\SiteFont), in
     * both languages, so an email reads like the site. Arial where a mail app loads no web
     * fonts: one by name, as Outlook does not take a family like sans-serif.
     */
    public static function fontStack(): string
    {
        return "'".SiteFont::current()."', Arial, sans-serif";
    }

    /**
     * How big the site draws the font's letters in that language (font-size-adjust): the
     * same sizes as the site wherever a mail app draws the font. Mail apps that do not know
     * the setting show the font sizes as written, as before.
     */
    public static function sizeAdjust(string $lang): float
    {
        return SiteFont::sizeAdjust($lang === 'ar' ? 'ar' : 'en');
    }

    public static function fontStylesheetUrl(): string
    {
        return SiteFont::stylesheetUrl();
    }

    /**
     * @param  array{email?: string, name?: string, name_en?: string, name_ar?: string, preview?: bool, no_greeting?: bool}  $recipient
     */
    public function html(Newsletter $newsletter, string $locale, array $recipient = [], bool $preview = false): string
    {
        $locale = $this->normalizeLocale($locale);
        $sections = $this->sections($newsletter, $locale, $recipient);
        $email = (string) ($recipient['email'] ?? '');
        $primaryLang = ($sections[0]['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';

        return view('mail.newsletter', [
            'subject' => $this->subject($newsletter, $locale),
            'preheader' => $this->preheader($sections),
            'sections' => $sections,
            'unsubscribeUrlEn' => $preview ? null : $this->unsubscribeUrl($email, Newsletter::LANGUAGE_EN),
            'unsubscribeUrlAr' => $preview ? null : $this->unsubscribeUrl($email, Newsletter::LANGUAGE_AR),
            'preview' => $preview,
            'logoUrl' => asset($primaryLang === 'ar' ? 'images/email-logo-ar.png' : 'images/email-logo-en.png'),
            'logoAlt' => $primaryLang === 'ar' ? 'منصة إسمع' : 'Listen',
            'siteName' => 'listenai.studio',
        ])->render();
    }

    /**
     * @param  array{email?: string, name?: string, name_en?: string, name_ar?: string, preview?: bool, no_greeting?: bool}  $recipient
     */
    public function text(Newsletter $newsletter, string $locale, array $recipient = [], bool $preview = false): string
    {
        $locale = $this->normalizeLocale($locale);
        $sections = $this->sections($newsletter, $locale, $recipient);
        $blocks = [];

        foreach ($sections as $section) {
            $blocks[] = trim(implode("\n\n", array_filter([
                $section['headline'],
                $section['greeting'],
                implode("\n\n", array_map(fn (array $block): string => $block['text'], $section['blocks'])),
                $section['highlights'] === [] ? '' : implode("\n", array_map(
                    fn (string $line): string => '- '.$line,
                    $section['highlights']
                )),
                $section['cta_label'] !== '' && $section['cta_url'] !== ''
                    ? $section['cta_label'].': '.$section['cta_url']
                    : '',
                $section['footer'],
            ])));
        }

        $text = implode("\n\n----------\n\n", array_filter($blocks));
        if (! $preview) {
            $url = $this->unsubscribeUrl((string) ($recipient['email'] ?? ''), $locale);
            if ($url !== '') {
                $text .= "\n\nUnsubscribe: ".$url;
            }
        }

        return trim($text);
    }

    public function subject(Newsletter $newsletter, string $locale): string
    {
        $locale = $this->normalizeLocale($locale);
        $english = trim((string) $newsletter->subject_en);
        $arabic = trim((string) $newsletter->subject_ar);

        if ($locale === Newsletter::LANGUAGE_AR) {
            return $arabic !== '' ? $arabic : $english;
        }

        if ($locale === Newsletter::LANGUAGE_EN) {
            return $english !== '' ? $english : $arabic;
        }

        if ($english !== '' && $arabic !== '') {
            return $english.' · '.$arabic;
        }

        return $english !== '' ? $english : $arabic;
    }

    public function unsubscribeUrl(string $email, string $locale): string
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        return URL::temporarySignedRoute('newsletter.unsubscribe', now()->addYears(2), [
            'email' => $email,
            'lang' => $locale === Newsletter::LANGUAGE_AR ? 'ar' : 'en',
        ]);
    }

    /**
     * @param  array{email?: string, name?: string, name_en?: string, name_ar?: string, preview?: bool, no_greeting?: bool}  $recipient
     * @return list<array<string, mixed>>
     */
    private function sections(Newsletter $newsletter, string $locale, array $recipient): array
    {
        $sections = [];
        foreach ($this->localeOrder($newsletter, $locale) as $lang) {
            $sections[] = $this->section($newsletter, $lang, $recipient);
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function localeOrder(Newsletter $newsletter, string $locale): array
    {
        if ($locale === Newsletter::LANGUAGE_AR) {
            return ['ar'];
        }

        if ($locale === Newsletter::LANGUAGE_EN) {
            return ['en'];
        }

        return $newsletter->bilingual_order === 'ar_en' ? ['ar', 'en'] : ['en', 'ar'];
    }

    /**
     * @param  array{email?: string, name?: string, name_en?: string, name_ar?: string, preview?: bool, no_greeting?: bool}  $recipient
     * @return array<string, mixed>
     */
    private function section(Newsletter $newsletter, string $lang, array $recipient): array
    {
        $name = $this->nameFor($lang, $recipient);
        $fill = $this->fillFor($newsletter, $lang, $recipient, $name);
        $arabic = $lang === 'ar';
        $intro = (string) ($arabic ? $newsletter->intro_ar : $newsletter->intro_en);
        $body = (string) ($arabic ? $newsletter->body_ar : $newsletter->body_en);
        $paragraphsText = $this->paragraphs($intro."\n\n".$body, $fill);

        return [
            'lang' => $lang,
            'dir' => $arabic ? 'rtl' : 'ltr',
            'align' => $arabic ? 'right' : 'left',
            'font' => self::fontStack(),
            'adjust' => self::sizeAdjust($lang),
            'preheader' => $this->personalize((string) ($arabic ? $newsletter->preheader_ar : $newsletter->preheader_en), $fill),
            'eyebrow' => $this->personalize((string) ($arabic ? $newsletter->eyebrow_ar : $newsletter->eyebrow_en), $fill),
            'headline' => $this->personalize((string) ($arabic ? $newsletter->headline_ar : $newsletter->headline_en), $fill),
            // "Hi Sara," with the person's first name ("Hi there," without one). The paid welcome
            // email has no greeting line; its headline greets.
            'greeting' => ! empty($recipient['no_greeting']) ? '' : ($arabic
                ? ('مرحباً'.($name !== '' ? ' '.$name : '').'،')
                : ('Hi '.($name !== '' ? $name : 'there').',')),
            'blocks' => array_merge(...array_map(fn (string $paragraph): array => $this->blocks($paragraph), $paragraphsText ?: [''])),
            'highlights' => $this->highlightLines(
                $arabic ? $newsletter->highlights_ar : $newsletter->highlights_en,
                $fill
            ),
            'cta_label' => $this->personalize((string) ($arabic ? $newsletter->cta_label_ar : $newsletter->cta_label_en), $fill),
            'cta_url' => $this->safeUrl((string) ($arabic ? $newsletter->cta_url_ar : $newsletter->cta_url_en)),
            'footer' => $this->personalize((string) ($arabic ? $newsletter->footer_ar : $newsletter->footer_en), $fill),
        ];
    }

    /**
     * One paragraph of the opening or body, as written in the admin: a line starting "## " is
     * a subheading (the lines after it a paragraph), lines that all start "- " a list, any
     * other a paragraph. **Words** between two pairs of stars are bold.
     *
     * @return list<array{type: string, html: string, items: list<string>, text: string}>
     */
    private function blocks(string $paragraph): array
    {
        if (trim($paragraph) === '') {
            return [];
        }
        $lines = preg_split("/\r\n|\n|\r/", $paragraph) ?: [];
        $bold = fn (string $text): string => preg_replace('/\*\*(.+?)\*\*/u', '<strong style="font-weight:600;color:#16161D;">$1</strong>', e($text)) ?? e($text);
        $plain = fn (string $text): string => preg_replace('/\*\*(.+?)\*\*/u', '$1', $text) ?? $text;

        if (str_starts_with($lines[0] ?? '', '## ')) {
            $heading = trim(substr((string) array_shift($lines), 3));
            $rest = trim(implode("\n", $lines));

            return [['type' => 'heading', 'html' => e($plain($heading)), 'items' => [], 'text' => $plain($heading)], ...$this->blocks($rest)];
        }

        if ($lines !== [] && array_filter($lines, fn (string $line): bool => ! preg_match('/^\s*-\s+/', $line)) === []) {
            $items = array_map(fn (string $line): string => trim((string) preg_replace('/^\s*-\s+/', '', $line)), $lines);

            return [['type' => 'list', 'html' => '', 'items' => array_map($bold, $items),
                'text' => implode("\n", array_map(fn (string $item): string => '- '.$plain($item), $items))]];
        }

        return [['type' => 'paragraph', 'html' => nl2br($bold($paragraph), false), 'items' => [], 'text' => $plain($paragraph)]];
    }

    /**
     * What {name}, {plan} and {stt_minutes} become for this person: their first name, their
     * plan's name in this language and its speech to text minutes a month. A preview, or an
     * address with no plan, reads the first plan the newsletter goes to.
     *
     * @return array<string, string>
     */
    private function fillFor(Newsletter $newsletter, string $lang, array $recipient, string $name): array
    {
        $userId = (int) ($recipient['user_id'] ?? 0);
        $key = spl_object_id($newsletter).'|'.$userId;
        if (! array_key_exists($key, $this->plans)) {
            $plan = null;
            try {
                $user = $userId > 0 ? User::query()->find($userId) : null;
                $plan = $user ? TranscriptionAccess::planOf($user) : null;
                if ($plan === null && $newsletter->audience === Newsletter::AUDIENCE_PLAN && ($first = $newsletter->chosenPlanIds()[0] ?? null)) {
                    $plan = Plan::query()->find($first);
                }
            } catch (\Throwable) {
                $plan = null;
            }
            $this->plans = [$key => $plan];
        }
        $plan = $this->plans[$key];

        return [
            '{name}' => $name,
            '{plan}' => $plan ? ((string) ($plan->getLocalizedName($lang) ?: $plan->name)) : ($lang === 'ar' ? 'إسمع' : 'Listen'),
            '{stt_minutes}' => $plan ? number_format(TranscriptionAccess::monthlyMinutes($plan)) : '',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function preheader(array $sections): string
    {
        $parts = [];
        foreach ($sections as $section) {
            $preheader = trim((string) ($section['preheader'] ?? ''));
            if ($preheader !== '') {
                $parts[] = $preheader;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function paragraphs(string $text, array $fill): array
    {
        $text = trim($this->personalize($text, $fill));
        if ($text === '') {
            return [];
        }

        $chunks = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $paragraphs = [];
        $buffer = [];
        $flush = function () use (&$buffer, &$paragraphs): void {
            $paragraph = trim(implode("\n", $buffer));
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
            $buffer = [];
        };

        foreach ($chunks as $line) {
            if (trim($line) === '') {
                $flush();

                continue;
            }
            $buffer[] = rtrim($line);
        }
        $flush();

        return $paragraphs;
    }

    /**
     * @return list<string>
     */
    private function highlightLines(mixed $highlights, array $fill): array
    {
        if (! is_array($highlights)) {
            return [];
        }

        $lines = [];
        foreach ($highlights as $item) {
            $text = is_array($item) ? (string) ($item['text'] ?? '') : (string) $item;
            $text = trim($this->personalize($text, $fill));
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /**
     * @param  array{name?: string, name_en?: string, name_ar?: string, preview?: bool}  $recipient
     */
    private function nameFor(string $lang, array $recipient): string
    {
        if (! empty($recipient['preview'])) {
            return $lang === 'ar'
                ? (string) ($recipient['name_ar'] ?? 'أحمد')
                : (string) ($recipient['name_en'] ?? 'Alex');
        }

        $name = trim((string) ($recipient['name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $name) ?: [];

        return $parts[0] ?? $name;
    }

    /** @param  array<string, string>  $fill */
    private function personalize(string $text, array $fill): string
    {
        $replaced = strtr($text, $fill);

        return preg_replace('/[ \t]{2,}/', ' ', $replaced) ?? $replaced;
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        if (! preg_match('/^https?:\/\//i', $url)) {
            return '';
        }

        return $url;
    }

    private function normalizeLocale(string $locale): string
    {
        return in_array($locale, [
            Newsletter::LANGUAGE_EN,
            Newsletter::LANGUAGE_AR,
            Newsletter::LANGUAGE_BOTH,
        ], true) ? $locale : Newsletter::LANGUAGE_EN;
    }
}
