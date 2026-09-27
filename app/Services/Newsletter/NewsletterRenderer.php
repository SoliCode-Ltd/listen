<?php

namespace App\Services\Newsletter;

use App\Models\Newsletter;
use Illuminate\Support\Facades\URL;

class NewsletterRenderer
{
    /**
     * @param  array{email?: string, name?: string, name_en?: string, name_ar?: string, preview?: bool}  $recipient
     */
    public function html(Newsletter $newsletter, string $locale, array $recipient = [], bool $preview = false): string
    {
        $locale = $this->normalizeLocale($locale);
        $sections = $this->sections($newsletter, $locale, $recipient);
        $unsubscribeUrl = $preview ? null : $this->unsubscribeUrl((string) ($recipient['email'] ?? ''), $locale);

        return view('mail.newsletter', [
            'subject' => $this->subject($newsletter, $locale),
            'preheader' => $this->preheader($sections),
            'sections' => $sections,
            'unsubscribeUrl' => $unsubscribeUrl,
            'preview' => $preview,
            'appName' => 'Listen',
        ])->render();
    }

    /**
     * @param  array{email?: string, name?: string, name_en?: string, name_ar?: string, preview?: bool}  $recipient
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
                implode("\n\n", $section['paragraphs_text']),
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
     * @param  array{email?: string, name?: string, name_en?: string, name_ar?: string, preview?: bool}  $recipient
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
     * @param  array{email?: string, name?: string, name_en?: string, name_ar?: string, preview?: bool}  $recipient
     * @return array<string, mixed>
     */
    private function section(Newsletter $newsletter, string $lang, array $recipient): array
    {
        $name = $this->nameFor($lang, $recipient);
        $arabic = $lang === 'ar';
        $intro = (string) ($arabic ? $newsletter->intro_ar : $newsletter->intro_en);
        $body = (string) ($arabic ? $newsletter->body_ar : $newsletter->body_en);
        $paragraphsText = $this->paragraphs($intro."\n\n".$body, $name);

        return [
            'lang' => $lang,
            'dir' => $arabic ? 'rtl' : 'ltr',
            'align' => $arabic ? 'right' : 'left',
            'font' => $arabic
                ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif"
                : "'IBM Plex Sans', 'Segoe UI', Arial, sans-serif",
            'preheader' => $this->personalize((string) ($arabic ? $newsletter->preheader_ar : $newsletter->preheader_en), $name),
            'eyebrow' => $this->personalize((string) ($arabic ? $newsletter->eyebrow_ar : $newsletter->eyebrow_en), $name),
            'headline' => $this->personalize((string) ($arabic ? $newsletter->headline_ar : $newsletter->headline_en), $name),
            'greeting' => $arabic
                ? ('مرحباً'.($name !== '' ? ' '.$name : '').'،')
                : ('Hello'.($name !== '' ? ' '.$name : '').','),
            'paragraphs' => array_map(
                fn (string $paragraph): string => nl2br(e($paragraph), false),
                $paragraphsText
            ),
            'paragraphs_text' => $paragraphsText,
            'highlights' => $this->highlightLines(
                $arabic ? $newsletter->highlights_ar : $newsletter->highlights_en,
                $name
            ),
            'cta_label' => $this->personalize((string) ($arabic ? $newsletter->cta_label_ar : $newsletter->cta_label_en), $name),
            'cta_url' => $this->safeUrl((string) ($arabic ? $newsletter->cta_url_ar : $newsletter->cta_url_en)),
            'footer' => $this->personalize((string) ($arabic ? $newsletter->footer_ar : $newsletter->footer_en), $name),
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
    private function paragraphs(string $text, string $name): array
    {
        $text = trim($this->personalize($text, $name));
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
    private function highlightLines(mixed $highlights, string $name): array
    {
        if (! is_array($highlights)) {
            return [];
        }

        $lines = [];
        foreach ($highlights as $item) {
            $text = is_array($item) ? (string) ($item['text'] ?? '') : (string) $item;
            $text = trim($this->personalize($text, $name));
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

    private function personalize(string $text, string $name): string
    {
        $replaced = str_replace('{name}', $name, $text);

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
