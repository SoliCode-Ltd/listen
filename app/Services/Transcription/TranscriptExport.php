<?php

namespace App\Services\Transcription;

use App\Models\Transcription;
use App\Support\Transcription\TranscriptionSettings;

/**
 * A transcript downloaded: plain text (TXT), subtitles (SRT, VTT) and a Word
 * document (DOCX), in its own language or a translation of it. Downloading
 * again costs nothing. Right-to-left languages (Arabic, Urdu, Persian) read
 * right to left in each: the subtitle lines start with a right-to-left mark
 * (so the full stop sits at the end of the line in every player) and the
 * Word document's paragraphs are set right to left.
 */
final class TranscriptExport
{
    public const FORMATS = ['txt', 'srt', 'vtt', 'docx'];

    /** Subtitle lines: at most this many letters a line, two lines and this many seconds a cue. */
    private const LINE = 42;

    private const CUE_SECONDS = 7.0;

    /**
     * @return array{body: string, type: string, name: string}
     */
    public function export(Transcription $t, string $format, ?string $language = null): array
    {
        [$paragraphs, $segments, $code] = $this->content($t, $language);
        $rtl = in_array($code, TranscriptionSettings::RTL, true);
        $names = fn (string $letter): string => $t->speakerName($letter, $code === 'ar');
        $several = count(array_unique(array_column($paragraphs, 'speaker'))) > 1;
        $base = self::fileName($t->title).($language !== null && $language !== '' && $language !== $t->language ? '-'.$code : '');

        return match ($format) {
            'srt' => ['body' => self::subtitles($segments, $rtl, $several ? $names : null, false), 'type' => 'application/x-subrip; charset=UTF-8', 'name' => $base.'.srt'],
            'vtt' => ['body' => self::subtitles($segments, $rtl, $several ? $names : null, true), 'type' => 'text/vtt; charset=UTF-8', 'name' => $base.'.vtt'],
            'docx' => ['body' => self::docx($t->title, $paragraphs, $rtl, $several ? $names : null), 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'name' => $base.'.docx'],
            default => ['body' => self::text($paragraphs, $several ? $names : null), 'type' => 'text/plain; charset=UTF-8', 'name' => $base.'.txt'],
        };
    }

    /**
     * The paragraphs and sentences to export: the transcript's own, or a translation's.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: string}
     */
    private function content(Transcription $t, ?string $language): array
    {
        $translated = $language !== null ? (($t->translations ?? [])[$language] ?? null) : null;
        if (is_array($translated)) {
            return [(array) $translated['paragraphs'], (array) $translated['segments'], $language];
        }

        return [(array) $t->paragraphs, (array) $t->segments, (string) ($t->language ?: $t->language_requested)];
    }

    /** @param  (callable(string): string)|null  $names */
    public static function text(array $paragraphs, ?callable $names): string
    {
        return trim(implode("\n\n", array_map(fn ($p) => ($names ? $names((string) $p['speaker']).': ' : '').trim((string) $p['text']), $paragraphs)))."\n";
    }

    /**
     * Subtitles: each sentence one cue or more (none longer than two lines of
     * LINE letters or CUE_SECONDS), the speaker's name where the speaker changes.
     *
     * @param  (callable(string): string)|null  $names
     */
    public static function subtitles(array $segments, bool $rtl, ?callable $names, bool $vtt): string
    {
        $cues = [];
        $was = null;
        foreach ($segments as $s) {
            $text = trim((string) $s['text']);
            if ($names !== null && $s['speaker'] !== $was) {
                $text = $names((string) $s['speaker']).': '.$text;
            }
            $was = $s['speaker'];
            array_push($cues, ...self::cues($text, (float) $s['start'], (float) $s['end']));
        }
        $mark = $rtl ? "\u{200F}" : '';
        $out = $vtt ? "WEBVTT\n\n" : '';
        foreach ($cues as $n => [$start, $end, $lines]) {
            $out .= ($vtt ? '' : ($n + 1)."\n").self::stamp($start, $vtt).' --> '.self::stamp(max($end, $start + 0.3), $vtt)."\n"
                .implode("\n", array_map(fn ($l) => $mark.$l, $lines))."\n\n";
        }

        return $out;
    }

    /**
     * A sentence as cues: its words in lines of LINE letters, two lines a cue,
     * the sentence's time shared out by their letters.
     *
     * @return array<int, array{0: float, 1: float, 2: array<int, string>}>
     */
    public static function cues(string $text, float $start, float $end): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if ($line !== '' && mb_strlen($line.' '.$word) > self::LINE) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = trim($line.' '.$word);
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        $groups = array_chunk($lines, 2);
        // A cue past CUE_SECONDS is split further where it has more than one line.
        $total = max(1, array_sum(array_map(fn ($g) => mb_strlen(implode(' ', $g)), $groups)));
        $cues = [];
        $at = $start;
        foreach ($groups as $g) {
            $share = ($end - $start) * mb_strlen(implode(' ', $g)) / $total;
            if ($share > self::CUE_SECONDS && count($g) > 1) {
                $cues[] = [$at, $at + $share / 2, [$g[0]]];
                $cues[] = [$at + $share / 2, $at + $share, [$g[1]]];
            } else {
                $cues[] = [$at, $at + $share, $g];
            }
            $at += $share;
        }

        return $cues;
    }

    public static function stamp(float $seconds, bool $vtt): string
    {
        $ms = (int) round(max(0.0, $seconds) * 1000);

        return sprintf('%02d:%02d:%02d%s%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $vtt ? '.' : ',', $ms % 1000);
    }

    /**
     * A Word document: the title, then each paragraph (the speaker's name in
     * bold before it where there are several), right to left where the
     * language reads so. Made by hand (a few XML files in a zip): no library.
     *
     * @param  (callable(string): string)|null  $names
     */
    public static function docx(string $title, array $paragraphs, bool $rtl, ?callable $names): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $pPr = $rtl ? '<w:pPr><w:bidi/></w:pPr>' : '';
        $rPr = fn (bool $bold, ?int $size = null): string => '<w:rPr>'.($bold ? '<w:b/><w:bCs/>' : '').($size ? '<w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/>' : '').($rtl ? '<w:rtl/>' : '').'</w:rPr>';
        $run = fn (string $text, bool $bold = false, ?int $size = null): string => '<w:r>'.$rPr($bold, $size).'<w:t xml:space="preserve">'.$e($text).'</w:t></w:r>';
        $body = '<w:p>'.$pPr.$run($title, true, 32).'</w:p>';
        foreach ($paragraphs as $p) {
            $body .= '<w:p>'.$pPr.($names ? $run($names((string) $p['speaker']).': ', true) : '').$run(trim((string) $p['text'])).'</w:p>';
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body
            .'<w:sectPr>'.($rtl ? '<w:bidi/>' : '').'<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>';

        $file = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /** A safe file name from a transcript's title. */
    public static function fileName(string $title): string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}\-_ .]+/u', '', pathinfo($title, PATHINFO_FILENAME) ?: $title));
        $name = trim((string) preg_replace('/\s+/u', '-', $name), '-.');

        return mb_substr($name !== '' ? $name : 'transcript', 0, 80);
    }
}
