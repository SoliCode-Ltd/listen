<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * A public page as Markdown, for AI agents that ask for it (Accept: text/markdown):
 * the page's title, description and address, then what its <main> says, with the
 * headings, lists, tables and links kept and the controls, icons, pictures and
 * scripts left out, and any part marked data-markdown="skip" (a tool for people, such
 * as the pricing calculator). Built with PHP's own DOM, so the server needs nothing new.
 */
class HtmlToMarkdown
{
    /** Never read: code, styling, controls and decoration. */
    private const SKIP = [
        'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed',
        'img', 'picture', 'video', 'audio', 'source', 'track', 'map',
        'button', 'input', 'select', 'option', 'textarea', 'form', 'label', 'dialog', 'nav',
    ];

    private const BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'details', 'dialog', 'dd', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hr', 'li', 'main', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'ul',
    ];

    private string $baseUrl;

    public function __construct(string $pageUrl)
    {
        $this->baseUrl = $pageUrl;
    }

    public static function convert(string $html, string $pageUrl): string
    {
        return (new self($pageUrl))->render($html);
    }

    public function render(string $html): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The XML declaration makes the parser read the page as UTF-8 (Arabic stays Arabic).
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $meta = static function (string $query) use ($xpath): string {
            $node = $xpath->query($query)->item(0);

            return $node instanceof DOMElement ? trim((string) $node->getAttribute('content') ?: (string) $node->getAttribute('href')) : '';
        };
        $title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
        $front = array_filter([
            'title' => $title,
            'description' => $meta('//meta[@name="description"]'),
            'url' => $meta('//link[@rel="canonical"]') ?: $this->baseUrl,
            'language' => trim((string) ($dom->documentElement?->getAttribute('lang') ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $root = $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
        $body = $root instanceof DOMNode ? $this->blocks($root) : '';

        $lines = ['---'];
        foreach ($front as $key => $value) {
            $lines[] = $key.': '.$this->yaml($value);
        }
        $lines[] = '---';

        return implode("\n", $lines)."\n\n".$this->tidy($body)."\n";
    }

    /** The children of a node as Markdown blocks, separated by blank lines. */
    private function blocks(DOMNode $node): string
    {
        $out = [];
        $inline = '';
        $flush = function () use (&$out, &$inline): void {
            $text = $this->line($inline);
            if ($text !== '') {
                $out[] = $text;
            }
            $inline = '';
        };

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $inline .= $this->text($child->wholeText);

                continue;
            }
            if (! $child instanceof DOMElement || $this->skipped($child)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (! in_array($tag, self::BLOCKS, true)) {
                $inline .= $this->element($child);

                continue;
            }
            $flush();
            $block = $this->block($child, $tag);
            if (trim($block) !== '') {
                $out[] = $block;
            }
        }
        $flush();

        return implode("\n\n", $out);
    }

    private function block(DOMElement $node, string $tag): string
    {
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $text = $this->line($this->inline($node));

                return $text === '' ? '' : str_repeat('#', (int) $tag[1]).' '.$text;
            case 'p': case 'summary': case 'dt': case 'figcaption':
                $text = $this->line($this->inline($node));

                return $tag === 'summary' || $tag === 'dt' ? ($text === '' ? '' : '**'.$text.'**') : $text;
            case 'ul': case 'ol':
                return $this->list($node, $tag === 'ol');
            case 'table':
                return $this->table($node);
            case 'blockquote':
                return implode("\n", array_map(static fn (string $line): string => rtrim('> '.$line), explode("\n", $this->blocks($node))));
            case 'pre':
                return "```\n".rtrim($node->textContent)."\n```";
            case 'hr':
                return '---';
            default:
                return $this->blocks($node);
        }
    }

    private function list(DOMElement $node, bool $ordered): string
    {
        $items = [];
        $number = 1;
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->tagName) !== 'li' || $this->skipped($child)) {
                continue;
            }
            $content = $this->blocks($child);
            if (trim($content) === '') {
                continue;
            }
            $marker = $ordered ? ($number++).'. ' : '- ';
            $pad = str_repeat(' ', strlen($marker));
            $lines = explode("\n", $content);
            $first = array_shift($lines);
            $rest = array_map(static fn (string $line): string => $line === '' ? '' : $pad.$line, $lines);
            $items[] = implode("\n", [$marker.$first, ...$rest]);
        }

        return implode("\n", $items);
    }

    private function table(DOMElement $node): string
    {
        $rows = [];
        foreach ((new DOMXPath($node->ownerDocument))->query('.//tr', $node) as $tr) {
            if (! $tr instanceof DOMElement || $this->skipped($tr)) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['th', 'td'], true) && ! $this->skipped($cell)) {
                    $text = $this->line(str_replace("\n", ' ', $this->inline($cell)));
                    $cells[] = str_replace('|', '\|', $text === '' ? ' ' : $text);
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return '';
        }
        $width = max(array_map('count', $rows));
        $line = static fn (array $cells): string => '| '.implode(' | ', array_pad($cells, $width, ' ')).' |';
        $out = [$line($rows[0]), '|'.str_repeat(' --- |', $width)];
        foreach (array_slice($rows, 1) as $row) {
            $out[] = $line($row);
        }

        return implode("\n", $out);
    }

    /** A node's words on one line, with emphasis, struck text, code and links kept. */
    private function inline(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= $this->text($child->wholeText);
            } elseif ($child instanceof DOMElement && ! $this->skipped($child)) {
                $out .= $this->element($child);
            }
        }

        return $out;
    }

    /** One element within a line. */
    private function element(DOMElement $node): string
    {
        $tag = strtolower($node->tagName);
        if ($tag === 'svg') {
            return $this->label($node);
        }
        $inner = $this->inline($node);

        return match ($tag) {
            'br' => "\n",
            'strong', 'b' => $this->wrap($inner, '**'),
            'em', 'i' => $this->wrap($inner, '*'),
            's', 'del', 'strike' => $this->wrap($inner, '~~'),
            'code', 'kbd' => $this->wrap($inner, '`'),
            'a' => $this->link($node, $inner),
            // A block inside a line (a card inside a link) reads as words apart.
            default => in_array($tag, self::BLOCKS, true) ? ' '.$inner.' ' : $inner,
        };
    }

    private function link(DOMElement $node, string $text): string
    {
        $text = $this->line($text);
        $href = trim($node->getAttribute('href'));
        // A link within the page or to a script is only words to an agent.
        if ($text === '' || $href === '' || str_starts_with($href, '#') || preg_match('/^javascript:/i', $href) === 1) {
            return $text;
        }

        return '['.str_replace(['[', ']'], ['\[', '\]'], $text).']('.$this->absolute($href).')';
    }

    private function absolute(string $href): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            return $href;
        }
        $parts = parse_url($this->baseUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin.$dir.$href;
    }

    private function wrap(string $text, string $mark): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return $text;
        }
        // Keep the spaces outside the marks, where Markdown needs them.
        $lead = $text !== ltrim($text) ? ' ' : '';
        $tail = $text !== rtrim($text) ? ' ' : '';

        return $lead.$mark.$trimmed.$mark.$tail;
    }

    private function skipped(DOMElement $node): bool
    {
        // An icon that stands for a word (the table's tick) reads as that word.
        if (strtolower($node->tagName) === 'svg' && $this->label($node) !== '') {
            return strtolower($node->getAttribute('aria-hidden')) === 'true';
        }

        return in_array(strtolower($node->tagName), self::SKIP, true)
            || $node->hasAttribute('hidden')
            || $node->getAttribute('data-markdown') === 'skip'
            || strtolower($node->getAttribute('aria-hidden')) === 'true';
    }

    private function label(DOMElement $node): string
    {
        return $node->getAttribute('role') === 'img' ? trim($node->getAttribute('aria-label')) : '';
    }

    private function text(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    private function line(string $text): string
    {
        return trim((string) preg_replace('/[ \t]*\n[ \t]*/u', "\n", (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text)));
    }

    private function tidy(string $markdown): string
    {
        $lines = array_map('rtrim', explode("\n", $markdown));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    private function yaml(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $value).'"';
    }
}
