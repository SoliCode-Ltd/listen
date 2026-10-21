<?php

namespace App\Services\Dubbing;

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiKeysOutException;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\TtsCostLimitException;
use App\Support\AudioDuration;
use App\Support\AudioFormats;
use App\Support\TtsStudioConfig;
use App\Support\UsageEstimator;
use App\Support\VoiceFlag;
use App\Support\VoiceTier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Dubs a short video into an Arabic dialect (or English accent):
 *
 *   1. the sound is taken out of the video, and where someone speaks is
 *      found on it, phrase by phrase, by a speech-detection model
 *      (speechMap(): from one pause, where the mouth closes, to the next);
 *   2. one request to the text model writes out what is said, in timed
 *      sentences, with who says it (and whether a man or a woman), how they
 *      say it (an emotion from the studio's list and a short direction, as
 *      a voice actor would be given), and a dubbed line in the chosen
 *      dialect that fits the same time, with the laughs, sighs and pauses
 *      of the original as the studio's sound tags; it also says whether
 *      there is music or other sound behind the speech. What the lines
 *      leave out is heard again on its own (unheard(), hearAgain()), and
 *      each line is set on the starts and ends of the phrases it covers
 *      (onPhrases()). Where switched on (listen.dubbing.phrase_lines), the
 *      model writes a line for each phrase instead (fillPhrases(), lines(),
 *      cuts()): off, for it hears two short phrases as one and puts the
 *      words a phrase or two early;
 *   3. each line is voiced by the studio's own speech engine: each speaker
 *      in their own voice (the member's choice for the main one, a voice
 *      of the same kind for the others) and, unless the member chose a
 *      style or an emotion for the whole video, in the original's mood. A
 *      take far shorter than its words need is taken once more;
 *   4. each line is placed where the original speaker starts it on their
 *      own voice (refineTimes()), without the speech engine's silences, and
 *      fitted to end where the original ends (plan()), at the
 *      loudness the original speaker had. The video's other sound stays (unless the
 *      member turned it off), as dubbing studios keep it: a separation model
 *      (VoiceSeparator, run on this server while steps 2 and 3 wait on the
 *      network) splits the original into its voices and the rest; the rest
 *      plays all through, and the original voices only between the new
 *      lines, never under one. Under a new line, the voices further back
 *      than the main speaker (children reciting, a crowd, chanting) play on
 *      where the speech isolation told them apart (VoiceSeparator).
 *      Where the model is not set up or busy, filters take
 *      the old voice out under each new line (only the sides of a stereo mix
 *      stay there; a mono track goes quiet). A preview copy carries the
 *      Listen mark, which means encoding the picture again.
 *
 * It returns what each step took: the model's tokens, the characters voiced,
 * the server's processing time and the files' sizes.
 */
final class VideoDubber
{
    /** The text models tried in turn, as the dialect rewrite does. */
    public const MODELS = ['gemini-3.5-flash', 'gemini-3.6-flash'];

    /** The most a line is sped up to fit its place. */
    public const MAX_TEMPO = 1.25;

    /** The most a line is slowed down to fill the original's time (more sounds dragged). */
    public const MIN_TEMPO = 0.92;

    /**
     * Sound tags a dubbed line may carry where the original has the sound
     * (GeminiVocalTags). Not breaths, groans, sobs or "hmm": voiced, they came
     * out as noises under the speech (heard as snoring).
     */
    public const SOUND_TAGS = ['laugh', 'chuckle', 'giggle', 'sigh', 'whispers', 'shout', 'short pause', 'long pause'];

    /** Seconds over which the original voices fade out before a new line and back after it, and the music down and up. */
    private const RAMP = 0.3;

    /**
     * The old voice is taken out a little before each new line and a little
     * after it, for the model's timings that are a moment off; gaps between
     * lines shorter than BRIDGE stay without it, so it does not flicker in.
     */
    private const LEAD = 0.2;

    private const TAIL = 0.25;

    private const BRIDGE = 1.0;

    /** A line whose words need under this share of the speech it stands for is heard again (unheard()). */
    private const SHORT_LINE = 0.4;

    /** Seconds of speech no line covers that are heard again (unheard()). */
    private const UNHEARD_SPEECH = 1.5;

    /** The most parts heard again in one dub, and the longest one (a longer part is heard in pieces). */
    private const MAX_HEARD_AGAIN = 4;

    private const LONGEST_HEARD_AGAIN = 90.0;

    /** A take this much shorter than its words need (of a line that needs at least 2.5 s) stopped early or skipped some. */
    private const SHORT_TAKE = 0.5;

    /**
     * The phrases of one sentence read in one take (lines()): one speaker,
     * pauses shorter than JOIN_GAP between them, at most MAX_PARTS phrases
     * and LONGEST_TAKE seconds; the take is cut at its pauses (cuts()), where
     * PART_PAUSE asks the speech engine for one between the phrases.
     */
    private const JOIN_GAP = 0.9;

    private const MAX_PARTS = 4;

    private const LONGEST_TAKE = 10.0;

    private const PART_PAUSE = ' <short pause> ';

    /** How far (seconds of speech) a pause of a take may be from a pause of the speaker it is set on (splitTake()). */
    private const SPLIT_REACH = 0.8;

    /** A take of a sentence shorter than this share of the speaker's time for it is taken once more, slower. */
    private const SLOW_TAKE = 0.88;

    /** How far a phrase found on the sound may still move to the speaker's own start and end (refineTimes()). */
    private const PHRASE_REACH = 0.25;

    /** The most a line is turned up or down to the original speaker's loudness (more raised its breaths and noises). */
    private const MAX_GAIN_DB = 6.0;

    /** The final safety limit, without pumping the music (slow release, no level change of its own). */
    private const LIMITER = 'alimiter=limit=0.97:attack=5:release=150:level=disabled';

    /** A speaker's share of the talk under which, in the lead's gender, they read in the lead's voice. */
    private const MINOR_SHARE = 0.08;

    /**
     * A voice line into stereo at its own level in both sides: ffmpeg's own
     * conversion (and the one it slips in to make a mix's inputs agree)
     * turns each side of a mono track 3 dB down.
     */
    private const VOICE_STEREO = 'aformat=channel_layouts=mono,pan=stereo|c0=c0|c1=c0';

    /** The separation model's sample rate (resources/dubbing/separate.py). */
    private const SEPARATION_RATE = 44100;

    /** What the last dub() that failed had done, step by step, up to where it failed (for the admin). */
    public array $lastReport = [];

    public function __construct(private GeminiKeyManager $keys, private GeminiTtsProvider $tts, private ?VoiceSeparator $separator = null) {}

    /**
     * $o['voice'] reads the main speaker; $o['voices'] (speech engine voices
     * by gender) gives the others theirs. An empty style or emotion follows
     * the video, line by line. $o['background'] 'replace' drops the
     * original sound entirely; otherwise music and effects stay.
     * $o['progress'] hears each step: listening, voicing (line, of lines),
     * mixing, marking.
     *
     * @param  array{language: string, dialect: string, voice: string, voices?: array<string, array<int, string>>, each_speaker?: bool, style?: ?string, emotion?: ?string, background?: string, preview?: bool, out_dir: string, name: string, progress?: callable(string, int, int): void}  $o
     * @return array<string, mixed>
     */
    public function dub(string $video, array $o): array
    {
        $ffmpeg = AudioFormats::ffmpegPath() ?? throw new \RuntimeException('ffmpeg is not available on this server.');
        $report = ['video' => basename($video), 'dialect' => $o['dialect'], 'voice' => $o['voice'], 'input_bytes' => filesize($video)];
        $progress = $o['progress'] ?? static function (): void {};
        $started = microtime(true);
        $tmp = sys_get_temp_dir().'/dub-'.bin2hex(random_bytes(5));
        @mkdir($tmp, 0700, true);
        $made = [];
        $separator = $this->separator ?? app(VoiceSeparator::class);
        $separating = null;

        try {
            $report['video_seconds'] = $this->duration($ffmpeg, $video);

            // 1. The sound, small: mono, 16 kHz, 32 kbps.
            $progress('listening', 0, 1);
            $speech = $tmp.'/speech.mp3';
            $report['extract'] = $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $video, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '32k', $speech]);
            // The whole sound, for the model that takes the voices out: it works while the lines are heard and voiced.
            $wantsBackground = ($o['background'] ?? 'keep') !== 'replace' && VoiceSeparator::active() && $this->probe($video)['sound'];
            if ($wantsBackground) {
                $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $video, '-vn', '-af', self::stereo($this->probe($video)['channels']).',aresample='.self::SEPARATION_RATE, '-f', 'f32le', $tmp.'/sound.f32']);
                $separating = $separator->start($tmp.'/sound.f32', $tmp.'/rest.f32', $tmp.'/voices.f32', $tmp.'/further.f32');
            }
            // Where someone speaks, phrase by phrase: the lines are set on their starts and ends, and the old voice is out in all of them.
            $phrases = $this->speechMap($ffmpeg, $video, $speech, $tmp, $report);
            // Lines written phrase by phrase only where switched on (listen.dubbing.phrase_lines): the model
            // hears two short phrases as one and puts the words a phrase or two early.
            $anchors = config('listen.dubbing.phrase_lines', false) ? $phrases : [];

            // 2. What is said, by whom and how, timed, and the dubbed lines.
            $t = microtime(true);
            try {
                $heard = $this->transcribeAndRewrite((string) file_get_contents($speech), $o['language'], $o['dialect'], $anchors);
            } catch (SpeechNotWrittenOut $e) {
                if ($anchors === []) {
                    throw $e;
                }
                // No answer for the phrases however asked: the model times the lines itself instead.
                $report['speech_map']['used'] = false;
                $anchors = [];
                $heard = $this->transcribeAndRewrite((string) file_get_contents($speech), $o['language'], $o['dialect']);
                $heard['finish'] = array_merge($e->finish, $heard['finish']);
            }
            $report['transcribe'] = ['model' => $heard['model'], 'finish' => $heard['finish']] + $heard['usage'];
            $report['background'] = $heard['background'];
            $segments = [];
            if ($anchors !== [] && ($heard['segments'] !== [] || count($heard['unsaid']) < count($anchors))) {
                // Speech the lines leave out, heard again on its own: a dub must not go quiet, nor bring back the old voice, where someone still talks.
                $segments = $this->fillPhrases($ffmpeg, $speech, $heard['segments'], $heard['unsaid'], $anchors, $o, $tmp, $report);
            }
            if ($segments === []) {
                if ($anchors !== []) {
                    // Not one line from the phrases (the model would not keep to them): it times the lines itself instead.
                    $report['speech_map']['used'] = false;
                    $anchors = [];
                    $heard = $this->transcribeAndRewrite((string) file_get_contents($speech), $o['language'], $o['dialect']);
                    foreach ($heard['usage'] as $key => $tokens) {
                        $report['transcribe'][$key] = ($report['transcribe'][$key] ?? 0) + $tokens;
                    }
                    $report['transcribe'] = ['model' => $heard['model'], 'finish' => array_merge($report['transcribe']['finish'], $heard['finish'])] + $report['transcribe'];
                    $report['background'] = $heard['background'];
                }
                $segments = $this->hearAgain($ffmpeg, $speech, self::inSeconds($heard['segments'], $report['video_seconds']), $o, $tmp, $report);
                // Each line's start and end on those of the phrases it covers: the model's times are a moment off, the sound's are not.
                $segments = self::onPhrases($segments, $phrases);
            }
            $report['transcribe']['seconds'] = round(microtime(true) - $t, 2);
            if ($segments === []) {
                throw new \RuntimeException('No speech was found in the video.');
            }
            $report['segments'] = count($segments);

            // 3. Each line voiced, with the site's own rewrite off: the lines are already in the dialect.
            $voices = self::assignVoices($heard['speakers'], $segments, $o['voice'], (array) ($o['voices'] ?? []));
            // With a voice for each person: how each speaks, described once and given with every line, so it stays the same.
            // With one voice for all, none: a description of one person read in the voice chosen for another changed it.
            $characters = ($o['each_speaker'] ?? false) ? array_column($heard['speakers'], 'voice', 'id') : [];
            $report['speakers'] = array_values(array_map(fn (array $s): array => $s + ['voice' => $voices[$s['id']] ?? $o['voice']], $heard['speakers']));
            // The phrases of a sentence in one take, so it sounds as one reading; cut at its pauses afterwards, a part for each phrase.
            $lines = self::lines($segments);
            $t = microtime(true);
            $rewrite = config('services.gemini_tts.dialect_rewrite');
            config(['services.gemini_tts.dialect_rewrite' => false]);
            $chars = 0;
            $retaken = 0;
            $slowed = 0;
            $placements = [];
            $takes = [];
            $quiet = [];
            $requests = 0;
            try {
                foreach ($lines as $k => $line) {
                    $progress('voicing', $k, count($lines));
                    $first = $segments[$line['segments'][0]];
                    $config = self::voiceConfig($first, $voices[$first['speaker']] ?? $o['voice'], $o, $characters[$first['speaker']] ?? '');
                    try {
                        $requests++;
                        $take = $this->voiceLine($ffmpeg, $line['text'], $o['language'], $config, $tmp.'/line-'.$k, $made);
                    } catch (GeminiKeysOutException|TtsCostLimitException $e) {
                        // The speech engine is out for every line (keys used up, the day's ceiling): the dub fails whole, its credits given back.
                        throw $e;
                    } catch (\Throwable $e) {
                        // Once more; then this line stays quiet rather than the whole dub failing, unless more than a few do.
                        try {
                            $requests++;
                            $take = $this->voiceLine($ffmpeg, $line['text'], $o['language'], $config, $tmp.'/line-'.$k.'-second', $made);
                        } catch (GeminiKeysOutException|TtsCostLimitException $e) {
                            throw $e;
                        } catch (\Throwable $e) {
                            report($e);
                            $quiet[$k] = mb_substr($e->getMessage(), 0, 200);
                            if (count($quiet) > max(1, intdiv(count($lines), 4))) {
                                throw $e;
                            }

                            continue;
                        }
                    }
                    $chars += mb_strlen($line['text']);
                    // A take far shorter than its words need stopped early or skipped some: taken once more, the closer one kept.
                    $needs = self::needs($line['text'], $config);
                    if ($needs >= 2.5 && $take['seconds'] < self::SHORT_TAKE * $needs && $retaken < max(2, (int) ceil(count($lines) / 4))) {
                        $retaken++;
                        try {
                            $requests++;
                            $again = $this->voiceLine($ffmpeg, $line['text'], $o['language'], $config, $tmp.'/line-'.$k.'-again', $made);
                            $chars += mb_strlen($line['text']);
                            if (abs(log(max(0.01, $again['seconds']) / $needs)) < abs(log(max(0.01, $take['seconds']) / $needs))) {
                                $take = $again;
                            }
                        } catch (\Throwable $e) {
                            // The first take stays: a retake that fails never fails the dub.
                            report($e);
                        }
                    }
                    // A take far quicker than the speaker says the sentence ends early on every phrase: taken once more, slower, the closer one kept.
                    $span = count($line['segments']) === 1 ? self::speakerSpan($segments[$line['segments'][0]], $phrases) : null;
                    if ($span !== null && $span >= 2.5 && $take['seconds'] < self::SLOW_TAKE * $span && $slowed < max(2, (int) ceil(count($lines) / 2))) {
                        $slowed++;
                        try {
                            $requests++;
                            $again = $this->voiceLine($ffmpeg, $line['text'], $o['language'], $config + ['speed' => $take['seconds'] < 0.8 * $span ? 0.8 : 0.9],
                                $tmp.'/line-'.$k.'-slower', $made);
                            $chars += mb_strlen($line['text']);
                            if (abs(log(max(0.01, $again['seconds']) / $span)) < abs(log(max(0.01, $take['seconds']) / $span))) {
                                $take = $again;
                            }
                        } catch (GeminiKeysOutException|TtsCostLimitException $e) {
                            throw $e;
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                    $takes[$k] = $take['audio'];
                    foreach ($this->piecesOf($ffmpeg, $take, $line, $segments, $tmp.'/part-'.$k, $phrases) as $piece) {
                        $placements[] = $piece + ['line' => $k];
                    }
                }
            } finally {
                config(['services.gemini_tts.dialect_rewrite' => $rewrite]);
            }
            $report['voicing'] = ['seconds' => round(microtime(true) - $t, 2), 'characters' => $chars, 'requests' => $requests,
                'retaken' => $retaken, 'slowed' => $slowed, 'takes' => count($lines), 'placed_parts' => count($placements),
                'voiced_seconds' => round(array_sum(array_column($placements, 'seconds')), 2)]
                + ($quiet !== [] ? ['quiet_lines' => count($quiet), 'quiet_because' => reset($quiet)] : []);
            if ($placements === []) {
                throw new \RuntimeException('No line could be voiced: '.reset($quiet));
            }

            // 4. Placed and put back onto the picture, over what else the video plays.
            // The member's choice alone decides: the model's guess that there is no music is not reason enough to drop the sound.
            $keep = ($o['background'] ?? 'keep') !== 'replace' && $this->probe($video)['sound'];
            $report['kept_background'] = $keep;
            $background = null;
            if ($keep && $wantsBackground) {
                $t = microtime(true);
                // Busy with another dub when this one began: it waits its turn here, a few minutes at most.
                $background = $separating !== null
                    ? $separator->finish($separating, $progress)
                    : $separator->run($tmp.'/sound.f32', $tmp.'/rest.f32', $tmp.'/voices.f32', 240, $progress, $tmp.'/further.f32');
                $separating = null;
                $report['separation'] = ['method' => $background !== null ? 'model' : 'filters', 'waited_seconds' => round(microtime(true) - $t, 2),
                    'voices_further_back' => ($background['further'] ?? null) !== null];
            } elseif ($keep) {
                $report['separation'] = ['method' => 'filters'];
            }
            $progress('mixing', 0, 1);
            // Exactly where the original speaker starts and stops (the model's times are a moment off),
            // heard on the voices alone where the model split them out.
            $envelope = $this->speechEnvelope($ffmpeg, $background !== null ? $background['voices'] : $video, $background !== null);
            // Lines set on the phrases found on the sound: only a small correction then.
            $segments = self::refineTimes($segments, $envelope, $report['video_seconds'], 100, $phrases !== [] ? self::PHRASE_REACH : 0.7);
            // Each part on its phrase as it is now (a line that could not be cut: from its first phrase to its last);
            // a piece of a sentence on the speaker's phrases inside it.
            $placed = array_map(fn (array $p): array => ['start' => (float) ($p['from'] ?? $segments[$p['segments'][0]]['start']), 'end' => (float) ($p['until'] ?? $segments[end($p['segments'])]['end']),
                'voiced_seconds' => $p['seconds'], 'audio' => $p['audio'], 'line' => $p['line']], $placements);
            $plan = self::plan($placed, $report['video_seconds']);
            $report['fit'] = ['sped_up' => count(array_filter($plan, fn ($p) => $p['tempo'] > 1.0)), 'slowed' => count(array_filter($plan, fn ($p) => $p['tempo'] < 1.0)),
                'max_tempo' => max(array_column($plan, 'tempo')), 'overrun_seconds' => round(array_sum(array_column($plan, 'overrun')), 2),
                'moved_seconds' => round(array_sum(array_map(fn ($s) => abs($s['start'] - ($s['heard_start'] ?? $s['start'])), $segments)), 2)];
            // Every line at the loudness the original speaker had, so none jumps out or falls away (the parts of a take alike).
            $plan = $this->evenLoudness($ffmpeg, $plan, $background !== null ? $background['voices'] : $speech, $background !== null, $takes);
            $report['loudness'] = ['target_db' => $plan[0]['target_db'] ?? null, 'gains_db' => array_column($plan, 'gain_db')];
            // The old voice is out wherever the original speaker makes a sound, a phrase without a new line included.
            $placementOf = [];
            $piecesOf = [];
            foreach ($placements as $pi => $p) {
                foreach ($p['segments'] as $si) {
                    $placementOf[$si] ??= $pi;
                    $piecesOf[$si][] = $pi;
                }
            }
            // A line the speech engine could not voice stays quiet: its old voice is out too.
            $spans = array_merge(array_map(fn (array $p): array => ['from' => $p[0], 'until' => $p[1]], $phrases),
                array_values(array_map(fn (array $s): array => ['from' => (float) $s['start'], 'until' => (float) $s['end']], array_diff_key($segments, $placementOf))));
            @mkdir($o['out_dir'], 0755, true);
            $clean = $o['out_dir'].'/'.$o['name'].'.mp4';
            $report['mux'] = $this->run($this->muxCommand($ffmpeg, $video, $plan, $report['video_seconds'], $keep, $clean, null, $background, $spans));
            $report['output_bytes'] = filesize($clean);
            $report['output'] = $clean;
            if ($o['preview'] ?? true) {
                $progress('marking', 0, 1);
                $preview = $o['out_dir'].'/'.$o['name'].'-preview.mp4';
                $report['preview_encode'] = $this->run($this->muxCommand($ffmpeg, $video, $plan, $report['video_seconds'], $keep, $preview, $this->markFile($ffmpeg, $tmp, $video), $background, $spans));
                $report['preview_bytes'] = filesize($preview);
                $report['preview'] = $preview;
            }
            $report['lines'] = array_map(fn (array $s, int $si): array => ['start' => $s['start'], 'end' => $s['end'], 'heard_start' => $s['heard_start'] ?? $s['start'],
                'speaker' => $s['speaker'], 'emotion' => $s['emotion'], 'delivery' => $s['delivery'], 'original' => $s['original'], 'dubbed' => $s['dubbed'],
                'voiced_seconds' => round(array_sum(array_map(fn (int $pi): float => (float) $placements[$pi]['seconds'], $piecesOf[$si] ?? [])), 2),
                // A sentence read in pieces on the speaker's own pauses: where each went, and how fast.
                'pieces' => count($piecesOf[$si] ?? []) > 1 ? array_map(fn (int $pi): array => ['from' => $plan[$pi]['from'], 'until' => $plan[$pi]['until'],
                    'voiced_seconds' => round((float) $placements[$pi]['seconds'], 2), 'tempo' => $plan[$pi]['tempo']], $piecesOf[$si]) : [],
                'tempo' => isset($placementOf[$si]) ? $plan[$placementOf[$si]]['tempo'] : null,
                'gain_db' => isset($placementOf[$si]) ? ($plan[$placementOf[$si]]['gain_db'] ?? 0.0) : null, 'voice' => $voices[$s['speaker']] ?? $o['voice'],
                'take' => isset($placementOf[$si]) ? $placements[$placementOf[$si]]['line'] : null,
                'placed_whole' => isset($placementOf[$si]) && count($placements[$placementOf[$si]]['segments']) > 1, 'quiet' => ! isset($placementOf[$si]),
                'heard_again' => ! empty($s['again']), 'as_said' => ! empty($s['as_said']), 'written' => ! empty($s['written'])], $segments, array_keys($segments));
            $report['total_seconds'] = round(microtime(true) - $started, 2);

            return $report;
        } catch (\Throwable $e) {
            $this->lastReport = $report + ['error' => mb_substr($e->getMessage(), 0, 300), 'total_seconds' => round(microtime(true) - $started, 2)];

            throw $e;
        } finally {
            if ($separating !== null) {
                $separator->cancel($separating);
            }
            foreach ($made as $path) {
                Storage::disk('public')->delete($path);
            }
            foreach (glob($tmp.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmp);
        }
    }

    /**
     * How one line is read: in its speaker's voice and the dialect; in the
     * style and emotion the member chose for the whole video, or else in the
     * original's mood. Every line of a speaker is given the same description
     * of their voice ($character), and only the line's feeling changes: each
     * line is voiced on its own, and free directions line by line made the
     * same person sound like someone else from one line to the next.
     *
     * @param  array{emotion: ?string, delivery: string}  $segment
     * @param  array<string, mixed>  $o
     * @return array<string, string>
     */
    public static function voiceConfig(array $segment, string $voice, array $o, string $character = ''): array
    {
        $style = trim((string) ($o['style'] ?? ''));
        $emotion = trim((string) ($o['emotion'] ?? ''));
        $config = ['voice_name' => $voice, 'dialect' => $o['dialect']];
        if ($style !== '' || $emotion !== '') {
            return $config + array_filter(['performance_style' => $style, 'emotion' => $emotion !== '' ? $emotion : ($segment['emotion'] ?? '')]);
        }

        // Following the video: the same voice all through, in each line's own mood.
        $mood = (string) ($segment['emotion'] ?? '');
        $direction = trim('One steady voice for this person in every line'.(trim($character) !== '' ? ': '.rtrim(trim($character), '.') : '').'.'
            .($mood !== '' ? ' Feeling of this line: '.$mood.'.' : ''));

        return $config + array_filter(['emotion' => $mood, 'style_prompt' => $direction]);
    }

    /**
     * Which voice reads each speaker: the member's choice for the one who
     * says the most; each other speaker the next voice of their gender that
     * is not taken yet (the member's voice when there is none left). One who
     * says very little (under MINOR_SHARE of the talk) in the lead's gender
     * reads in the lead's voice: that is most often the lead split in two by
     * the model, and a voice that changes for one line is the worse mistake.
     *
     * @param  array<int, array{id: string, gender: string}>  $speakers
     * @param  array<int, array{speaker: string, start: float, end: float}>  $segments
     * @param  array<string, array<int, string>>  $pool  speech engine voices by gender
     * @return array<string, string>
     */
    public static function assignVoices(array $speakers, array $segments, string $main, array $pool): array
    {
        $talk = [];
        foreach ($segments as $s) {
            $talk[$s['speaker']] = ($talk[$s['speaker']] ?? 0) + max(0.1, $s['end'] - $s['start']);
        }
        arsort($talk);
        $lead = (string) array_key_first($talk);
        $voices = [$lead => $main];
        $taken = [$main => true];
        $all = max(0.1, array_sum($talk));
        $leadGender = collect($speakers)->firstWhere('id', $lead)['gender'] ?? null;
        foreach ($speakers as $speaker) {
            if (isset($voices[$speaker['id']])) {
                continue;
            }
            if ($speaker['gender'] === $leadGender && ($talk[$speaker['id']] ?? 0) / $all < self::MINOR_SHARE) {
                $voices[$speaker['id']] = $main;

                continue;
            }
            $pick = null;
            foreach ((array) ($pool[$speaker['gender']] ?? []) as $candidate) {
                if (! isset($taken[$candidate])) {
                    $pick = $candidate;
                    break;
                }
            }
            $voices[$speaker['id']] = $pick ?? $main;
            $taken[$voices[$speaker['id']]] = true;
        }

        return $voices;
    }

    /**
     * Where each voiced line goes and how fast, to keep to the original's
     * timing as dubbing does (isochrony): it starts where the original line
     * starts and is fitted to end where it ends, slowed down a little (to
     * MIN_TEMPO) or sped up (to MAX_TEMPO); faster still only as far as it
     * must not run into the next line or past the video's end. Each also
     * says until when the original voice is taken out for it: to where the
     * original or the new line ends, whichever is later, so the old voice
     * never comes back under or after its own line.
     *
     * @param  array<int, array{start: float, end: float, voiced_seconds: float, audio?: string, line?: int}>  $segments
     * @return array<int, array{audio: ?string, delay_ms: int, tempo: float, overrun: float, from: float, until: float, line: ?int}>
     */
    public static function plan(array $segments, float $videoSeconds): array
    {
        $out = [];
        $segments = array_values($segments);
        $count = count($segments);
        foreach ($segments as $i => $s) {
            $start = max(0.0, (float) $s['start']);
            $end = (float) ($s['end'] ?? $start);
            $next = $i + 1 < $count ? (float) $segments[$i + 1]['start'] : $videoSeconds;
            $room = max(0.3, $next - $start - 0.05);
            $target = max(0.3, min($room, $end - $start));
            $length = max(0.01, (float) $s['voiced_seconds']);
            $tempo = max(self::MIN_TEMPO, min(self::MAX_TEMPO, $length / $target));
            $out[] = [
                'audio' => $s['audio'] ?? null,
                'delay_ms' => (int) round($start * 1000),
                'tempo' => round($tempo, 3),
                // What still runs past its room after speeding up.
                'overrun' => max(0.0, round($length / $tempo - $room, 2)),
                'from' => round($start, 3),
                'until' => round(max($end, $start + $length / $tempo), 3),
                'line' => isset($s['line']) ? (int) $s['line'] : null,
            ];
        }

        return $out;
    }

    /**
     * The model's lines set on the phrases found on the sound: each start
     * moved to the nearest start of a phrase, each end to the nearest end of
     * one, within $reach seconds and never across the line before or after
     * (the model's times are a moment off; a phrase starts and ends where
     * the speaker's mouth opens and closes). A line with no phrase near
     * keeps its times.
     *
     * @param  array<int, array<string, mixed>>  $segments  in order of time
     * @param  array<int, array{0: float, 1: float}>  $phrases
     * @return array<int, array<string, mixed>>
     */
    public static function onPhrases(array $segments, array $phrases, float $reach = 0.8): array
    {
        $segments = array_values($segments);
        $count = count($segments);
        $previousEnd = 0.0;
        foreach ($segments as $i => $s) {
            $start = (float) $s['start'];
            $end = (float) $s['end'];
            $nextStart = $i + 1 < $count ? (float) $segments[$i + 1]['start'] : INF;
            $near = fn (array $times, float $at, float $from, float $to): ?float => array_reduce($times,
                fn (?float $best, float $t): ?float => $t >= $from && $t <= $to && abs($t - $at) <= $reach && ($best === null || abs($t - $at) < abs($best - $at)) ? $t : $best);
            // Where a start or an end falls in the speaker's silence (or a start in the last moment of a phrase, an end in
            // the first moment of one), the speaker starts at the next phrase and stopped at the one before.
            $silent = fn (float $t, bool $isStart): bool => $phrases !== [] && array_filter($phrases, fn (array $p): bool => $isStart
                ? $t >= $p[0] - 0.05 && $t <= max($p[0] + 0.05, $p[1] - 0.3)
                : $t >= min($p[1] - 0.05, $p[0] + 0.3) && $t <= $p[1] + 0.05) === [];
            $newStart = $near(array_column($phrases, 0), $start, $previousEnd - 0.01, $end) ?? max($start, $previousEnd);
            if ($silent($newStart, true)) {
                $later = array_filter(array_column($phrases, 0), fn (float $t): bool => $t > $newStart && $t < $end - 0.3);
                $newStart = $later !== [] ? min($later) : $newStart;
            }
            $newEnd = $near(array_column($phrases, 1), $end, $newStart + 0.3, $nextStart + 0.1) ?? max($end, $newStart + 0.3);
            if ($silent($newEnd, false)) {
                $earlier = array_filter(array_column($phrases, 1), fn (float $t): bool => $t < $newEnd && $t > $newStart + 0.3);
                $newEnd = $earlier !== [] ? max($earlier) : $newEnd;
            }
            $segments[$i]['start'] = round($newStart, 2);
            $segments[$i]['end'] = round($newEnd, 2);
            $previousEnd = $newEnd;
        }

        return $segments;
    }

    /**
     * The model's times in seconds: past the first minute it now and then
     * writes a time as minutes and seconds run together (106.8 for 1:06.8),
     * which puts the last lines after the video's end, so they are never
     * heard. Where a time lies past the end and reads as such a time, every
     * time of that form is read so; a line that still starts after the end
     * is dropped, and an end past it is the video's end.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<int, array<string, mixed>>
     */
    public static function inSeconds(array $segments, float $seconds): array
    {
        $clock = fn (float $t): ?float => $t >= 100 && fmod($t, 100) < 60 ? floor($t / 100) * 60 + fmod($t, 100) : null;
        $past = array_filter(array_merge(array_column($segments, 'start'), array_column($segments, 'end')), fn ($t): bool => (float) $t > $seconds + 0.5);
        if ($past !== [] && array_filter($past, fn ($t): bool => ($clock((float) $t) ?? INF) > $seconds + 0.5) === []) {
            foreach ($segments as $i => $s) {
                foreach (['start', 'end'] as $key) {
                    $segments[$i][$key] = round($clock((float) $s[$key]) ?? (float) $s[$key], 2);
                }
            }
        }
        $kept = [];
        foreach ($segments as $s) {
            if ((float) $s['start'] < $seconds - 0.2) {
                $kept[] = ['end' => min((float) $s['end'], $seconds)] + $s;
            }
        }
        usort($kept, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $kept;
    }

    /** Seconds a line's words need, read as the speech engine reads them ($config: its voice settings). */
    public static function needs(string $text, array $config = []): float
    {
        return UsageEstimator::rawSeconds(UsageEstimator::features($text), VoiceTier::HD, 1.0, $config);
    }

    /**
     * Where the lines leave speech out, to be heard again on its own: a
     * stretch of speech (UNHEARD_SPEECH or more of it) that no line covers,
     * and a line whose words need far less time (under SHORT_LINE of it)
     * than the speech it stands for, as when the model sums up or drops the
     * end of a long part ('replace': that line). $envelope is how loud the
     * original's speech band is, in dB every 10 ms. The longest parts come
     * first, at most MAX_HEARD_AGAIN, a long one in pieces.
     *
     * @param  array<int, array{start: float, end: float, dubbed: string}>  $segments  in order of time
     * @param  array<int, float>  $envelope
     * @return array<int, array{from: float, to: float, replace: ?int}>
     */
    public static function unheard(array $segments, array $envelope, int $fps = 100): array
    {
        $frames = count($envelope);
        if ($frames < 20 || $segments === []) {
            return [];
        }
        $sorted = $envelope;
        sort($sorted);
        $floor = $sorted[(int) floor(0.2 * ($frames - 1))];
        $loud = $sorted[(int) floor(0.95 * ($frames - 1))];
        if ($loud < $floor + 8) {
            // No speech that stands out of the rest of the sound: nothing to go by.
            return [];
        }
        $level = max($floor + 10, $loud - 30);
        $at = fn (float $t): int => max(0, min($frames, (int) round($t * $fps)));
        $spans = [];
        $covered = array_fill(0, $frames, false);
        foreach (array_values($segments) as $i => $s) {
            for ($f = $at((float) $s['start'] - 0.25), $last = $at((float) $s['end'] + 0.25); $f < $last; $f++) {
                $covered[$f] = true;
            }
            $speech = 0;
            for ($f = $at((float) $s['start']), $last = $at((float) $s['end']); $f < $last; $f++) {
                $speech += $envelope[$f] >= $level ? 1 : 0;
            }
            if ($speech / $fps >= 3.0 && self::needs((string) $s['dubbed']) < self::SHORT_LINE * $speech / $fps) {
                $spans[] = ['from' => (float) $s['start'], 'to' => (float) $s['end'], 'replace' => $i];
            }
        }
        // Speech outside every line, in runs that break only at half a second without it.
        $run = null;
        for ($f = 0; $f <= $frames; $f++) {
            $speaking = $f < $frames && ! $covered[$f] && $envelope[$f] >= $level;
            if ($speaking) {
                $run = $run === null ? ['first' => $f, 'last' => $f, 'speech' => 1] : ['last' => $f, 'speech' => $run['speech'] + 1] + $run;
            } elseif ($run !== null && ($f === $frames || $covered[$f] || $f - $run['last'] > 0.5 * $fps)) {
                if ($run['speech'] >= self::UNHEARD_SPEECH * $fps) {
                    $spans[] = ['from' => $run['first'] / $fps, 'to' => ($run['last'] + 1) / $fps, 'replace' => null];
                }
                $run = null;
            }
        }
        usort($spans, fn (array $a, array $b): int => ($b['to'] - $b['from']) <=> ($a['to'] - $a['from']));
        $pieces = [];
        foreach ($spans as $span) {
            // A long stretch no line covers is heard in pieces; a line is heard again whole.
            $count = $span['replace'] === null ? (int) ceil(($span['to'] - $span['from']) / self::LONGEST_HEARD_AGAIN) : 1;
            $step = ($span['to'] - $span['from']) / $count;
            for ($k = 0; $k < $count && count($pieces) < self::MAX_HEARD_AGAIN; $k++) {
                $pieces[] = ['from' => round($span['from'] + $k * $step, 2), 'to' => round($span['from'] + ($k + 1) * $step, 2), 'replace' => $span['replace']];
            }
        }

        return $pieces;
    }

    /**
     * The lines with those heard again for one part ($span; $fresh in the
     * part's own time, from $offset in the video, the ones whose middle is
     * in the part): they take the place of the line they replace when they
     * say more than it (by a third), and give way where they run into
     * another line. One whose words are mostly those of the lines around
     * the part is left out: the model's times were a little off, and it
     * was said already. Each reads in the voice of the line it replaces, or
     * else the nearest one's.
     *
     * @param  array<int, array<string, mixed>>  $segments  in order of time
     * @param  array{from: float, to: float, replace: ?int}  $span
     * @param  array<int, array<string, mixed>>  $fresh
     * @return array<int, array<string, mixed>>
     */
    public static function withHeardAgain(array $segments, array $span, array $fresh, float $offset): array
    {
        $segments = array_values($segments);
        $replace = $span['replace'];
        $around = [];
        foreach ($segments as $i => $s) {
            if ($i !== $replace && (float) $s['end'] >= $span['from'] - 3 && (float) $s['start'] <= $span['to'] + 3) {
                $around += array_flip(self::words($s));
            }
        }
        $lines = [];
        foreach ($fresh as $s) {
            $middle = ((float) $s['start'] + (float) $s['end']) / 2 + $offset;
            $words = self::words($s);
            $said = count(array_filter($words, fn (string $w): bool => isset($around[$w])));
            if ($middle >= $span['from'] && $middle < $span['to'] && ($words === [] || $said < 0.6 * count($words))) {
                $lines[] = ['start' => round((float) $s['start'] + $offset, 2), 'end' => round((float) $s['end'] + $offset, 2), 'again' => true] + $s;
            }
        }
        $says = fn (array $list): float => array_sum(array_map(fn (array $s): float => self::needs((string) $s['dubbed']), $list));
        if ($lines === [] || ($replace !== null && $says($lines) < 1.3 * self::needs((string) $segments[$replace]['dubbed']))) {
            return $segments;
        }
        $nearest = $replace;
        if ($nearest === null) {
            $distance = INF;
            foreach ($segments as $i => $s) {
                $gap = max((float) $s['start'] - $span['to'], $span['from'] - (float) $s['end'], 0.0);
                if ($gap < $distance) {
                    [$distance, $nearest] = [$gap, $i];
                }
            }
        }
        foreach ($lines as $k => $line) {
            $lines[$k]['speaker'] = $segments[$nearest]['speaker'] ?? $line['speaker'];
        }
        if ($replace !== null) {
            unset($segments[$replace]);
        }
        $all = array_merge(array_values($segments), $lines);
        usort($all, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $kept = [];
        foreach ($all as $s) {
            $last = count($kept) - 1;
            if ($last >= 0 && $s['start'] < $kept[$last]['end']) {
                if (! empty($s['again'])) {
                    $s['start'] = $kept[$last]['end'];
                } elseif (! empty($kept[$last]['again'])) {
                    $kept[$last]['end'] = $s['start'];
                    if ($kept[$last]['end'] - $kept[$last]['start'] < 0.3) {
                        array_pop($kept);
                    }
                }
            }
            if (empty($s['again']) || $s['end'] - $s['start'] >= 0.3) {
                $kept[] = $s;
            }
        }

        return $kept;
    }

    /**
     * The words of a line as said in the original (or else as dubbed), in
     * lower case, without marks or punctuation, of two letters or more.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, string>
     */
    private static function words(array $line): array
    {
        $text = trim((string) ($line['original'] ?? '')) !== '' ? (string) $line['original'] : (string) ($line['dubbed'] ?? '');
        $plain = mb_strtolower((string) preg_replace('/[\p{Mn}\p{P}\p{S}]+/u', ' ', $text));

        return array_values(array_filter(preg_split('/\s+/u', $plain) ?: [], fn (string $w): bool => mb_strlen($w) >= 2));
    }

    /**
     * The lines, with the speech they leave out heard again (unheard()):
     * each part cut out of the sound and written out on its own, which the
     * text model does far more closely than as a moment of the whole video.
     * The parts go from the last to the first, so a line's place in the list
     * holds until its own part comes. A request that fails changes nothing.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<string, mixed>  $o
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function hearAgain(string $ffmpeg, string $speech, array $segments, array $o, string $tmp, array &$report): array
    {
        $spans = self::unheard($segments, $this->speechEnvelope($ffmpeg, $speech, false));
        usort($spans, fn (array $a, array $b): int => $b['from'] <=> $a['from']);
        $report['heard_again'] = [];
        foreach ($spans as $k => $span) {
            $from = max(0.0, $span['from'] - 0.5);
            $clip = $tmp.'/again-'.$k.'.mp3';
            $count = count($segments);
            $length = $span['to'] + 0.5 - $from;
            try {
                $this->run([$ffmpeg, '-v', 'error', '-y', '-ss', (string) $from, '-t', (string) $length, '-i', $speech, '-c:a', 'libmp3lame', '-b:a', '32k', $clip]);
                $heard = $this->transcribeAndRewrite((string) file_get_contents($clip), $o['language'], $o['dialect'], [], true);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
            // What it cost, counted with the first request.
            foreach ($heard['usage'] as $key => $tokens) {
                $report['transcribe'][$key] = ($report['transcribe'][$key] ?? 0) + $tokens;
            }
            // Times from the part's start, as asked; or, now and then, from the video's.
            $starts = array_column($heard['segments'], 'start');
            $offset = $starts !== [] && min($starts) >= $from - 1 && max($starts) > $length + 1 ? 0.0 : $from;
            $before = $segments;
            $segments = self::withHeardAgain($segments, $span, $heard['segments'], $offset);
            $report['heard_again'][] = ['from' => $span['from'], 'to' => $span['to'], 'line_replaced' => $span['replace'] !== null,
                'lines_heard' => count($heard['segments']), 'used' => $segments !== $before, 'lines_added' => count($segments) - $count];
        }

        return $segments;
    }

    /**
     * One line voiced, without the silence the speech engine leaves before
     * and after it so it starts with the lips: the file and how long it speaks.
     *
     * @param  array<string, string>  $config
     * @param  array<int, string>  $made  the speech engine's files, to delete when done
     * @return array{audio: string, seconds: float}
     */
    private function voiceLine(string $ffmpeg, string $text, string $language, array $config, string $out, array &$made): array
    {
        $result = $this->tts->synthesize($text, $language, $config);
        $made[] = $result['audio_path'];
        [$audio, $trimmed] = $this->trimmed($ffmpeg, Storage::disk('public')->path($result['audio_path']), $out.'.wav');

        return ['audio' => $audio, 'seconds' => $trimmed ?? AudioDuration::ofStoredFile($result['audio_path']) ?? (float) ($result['duration_seconds'] ?? 0)];
    }

    /**
     * Where someone speaks, phrase by phrase: from the speech-detection model
     * where the separator is set up with it (VoiceSeparator::phrases()), else
     * from the speech band's loudness (phrasesFromLevels()). Empty where
     * neither finds speech with pauses in it: the text model then times the
     * lines itself.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, array{0: float, 1: float}>
     */
    private function speechMap(string $ffmpeg, string $video, string $speech, string $tmp, array &$report): array
    {
        $t = microtime(true);
        $phrases = null;
        $method = 'model';
        if (VoiceSeparator::vadReady()) {
            try {
                $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $video, '-vn', '-ac', '1', '-ar', '16000', '-f', 'f32le', $tmp.'/speech16.f32']);
                $phrases = VoiceSeparator::phrases($tmp.'/speech16.f32');
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if ($phrases === null) {
            $method = 'loudness';
            $phrases = self::phrasesFromLevels($this->speechEnvelope($ffmpeg, $speech, false));
        }
        $report['speech_map'] = ['method' => $phrases === [] ? 'none' : $method, 'phrases' => count($phrases), 'seconds' => round(microtime(true) - $t, 2)];

        return $phrases;
    }

    /**
     * Phrases from the speech band's loudness alone ($envelope: dB every
     * 10 ms), on a server without the speech-detection model: speech stands
     * 10 dB over the quiet of the track and within 30 dB of its loudest; a
     * pause is where it falls under that, or 25 dB under the speech around
     * it, for 0.15 s or more. A phrase over 8 s is cut where it is quietest.
     * None where the track has no such quiet (music all through), or is
     * nearly all one long sound: pauses cannot be told then.
     *
     * @param  array<int, float>  $envelope
     * @return array<int, array{0: float, 1: float}>
     */
    public static function phrasesFromLevels(array $envelope, int $fps = 100): array
    {
        $n = count($envelope);
        if ($n < 50) {
            return [];
        }
        $sorted = $envelope;
        sort($sorted);
        $floor = $sorted[(int) floor(0.2 * ($n - 1))];
        $loud = $sorted[(int) floor(0.95 * ($n - 1))];
        if ($loud < $floor + 15) {
            return [];
        }
        $speech = max($floor + 10, $loud - 30);
        // The loud end of the speech around each moment, a quarter of a second at a time, a second either way.
        $block = max(1, (int) round(0.25 * $fps));
        $around = [];
        for ($b = 0; $b * $block < $n; $b++) {
            $heard = [];
            for ($f = max(0, $b * $block - $fps), $last = min($n, ($b + 1) * $block + $fps); $f < $last; $f++) {
                if ($envelope[$f] >= $speech) {
                    $heard[] = $envelope[$f];
                }
            }
            sort($heard);
            $around[$b] = $heard === [] ? INF : $heard[(int) floor(0.8 * (count($heard) - 1))];
        }
        $least = (int) ceil(0.15 * $fps);
        $runs = [];
        $start = null;
        $quiet = 0;
        $lastSpeech = 0;
        for ($f = 0; $f <= $n; $f++) {
            if ($f < $n && $envelope[$f] >= $speech && $envelope[$f] >= $around[intdiv($f, $block)] - 25) {
                $start ??= $f;
                $quiet = 0;
                $lastSpeech = $f;
            } elseif ($start !== null && ($f === $n || ++$quiet >= $least)) {
                $runs[] = [$start, $lastSpeech + 1];
                $start = null;
                $quiet = 0;
            }
        }
        $phrases = [];
        while ($runs !== []) {
            [$a, $b] = array_shift($runs);
            if ($b - $a > 8 * $fps) {
                $cut = $a + (int) (0.2 * ($b - $a));
                for ($f = $cut, $last = $b - (int) (0.2 * ($b - $a)); $f < $last; $f++) {
                    $cut = $envelope[$f] < $envelope[$cut] ? $f : $cut;
                }
                array_unshift($runs, [$a, $cut], [$cut, $b]);

                continue;
            }
            if ($b - $a >= 0.2 * $fps) {
                $phrases[] = [$a, $b];
            }
        }
        $spoken = array_sum(array_map(fn (array $p): int => $p[1] - $p[0], $phrases));
        if ($phrases === [] || ($spoken > 0.9 * $n && $spoken / count($phrases) > 6 * $fps)) {
            return [];
        }

        // A little before and after each, as far as the phrases around it allow.
        return array_map(fn (array $p, int $k): array => [
            round(max($p[0] / $fps - 0.03, $k > 0 ? $phrases[$k - 1][1] / $fps : 0.0), 2),
            round(min($p[1] / $fps + 0.03, $k + 1 < count($phrases) ? $phrases[$k + 1][0] / $fps : $n / $fps), 2),
        ], $phrases, array_keys($phrases));
    }

    /**
     * The phrases the model left without a dubbed line though someone speaks
     * in them ($unsaid), filled: heard again on their own, a few at a time,
     * as the phrases of the part cut out (in its own time). What is still
     * missing then is read as it was said where the dub is in Arabic and so
     * was the original (a verse, a saying of the Prophet), or else dubbed
     * from what was heard, by the text model alone. A phrase nothing could
     * be made of stays quiet: the old voice never comes back for it.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<int, array{phrase: int, original: string, speaker: string}>  $unsaid
     * @param  array<int, array{0: float, 1: float}>  $phrases
     * @param  array<string, mixed>  $o
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function fillPhrases(string $ffmpeg, string $speech, array $segments, array $unsaid, array $phrases, array $o, string $tmp, array &$report): array
    {
        $report['heard_again'] = [];
        $missing = array_column($unsaid, null, 'phrase');
        ksort($missing);
        // Runs of missing phrases close together (2 s apart at most), 30 s at most each.
        $runs = [];
        foreach (array_keys($missing) as $k) {
            $last = count($runs) - 1;
            if ($last >= 0 && $phrases[$k][0] - $phrases[end($runs[$last])][1] <= 2.0 && $phrases[$k][1] - $phrases[$runs[$last][0]][0] <= 30.0) {
                $runs[$last][] = $k;
            } else {
                $runs[] = [$k];
            }
        }
        foreach (array_slice($runs, 0, self::MAX_HEARD_AGAIN) as $n => $run) {
            $from = max(0.0, $phrases[$run[0]][0] - 0.5);
            $to = $phrases[end($run)][1] + 0.5;
            $clip = $tmp.'/again-'.$n.'.mp3';
            try {
                $this->run([$ffmpeg, '-v', 'error', '-y', '-ss', (string) $from, '-t', (string) ($to - $from), '-i', $speech, '-c:a', 'libmp3lame', '-b:a', '32k', $clip]);
                $local = array_map(fn (int $k): array => [round($phrases[$k][0] - $from, 2), round($phrases[$k][1] - $from, 2)], $run);
                $heard = $this->transcribeAndRewrite((string) file_get_contents($clip), $o['language'], $o['dialect'], $local, true);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
            // What it cost, counted with the first request.
            foreach ($heard['usage'] as $key => $tokens) {
                $report['transcribe'][$key] = ($report['transcribe'][$key] ?? 0) + $tokens;
            }
            $filled = 0;
            foreach ($heard['segments'] as $s) {
                $k = $run[$s['phrase']];
                $segments[] = ['start' => $phrases[$k][0], 'end' => $phrases[$k][1], 'phrase' => $k, 'again' => true,
                    'speaker' => $missing[$k]['speaker'] !== '' ? $missing[$k]['speaker'] : self::nearestSpeaker($segments, $phrases[$k][0], $s['speaker'])] + $s;
                unset($missing[$k]);
                $filled++;
            }
            // Words it heard there but did not dub, for what follows.
            foreach ($heard['unsaid'] as $u) {
                $k = $run[$u['phrase']];
                if (isset($missing[$k]) && $missing[$k]['original'] === '') {
                    $missing[$k]['original'] = $u['original'];
                }
            }
            $report['heard_again'][] = ['from' => $phrases[$run[0]][0], 'to' => $phrases[end($run)][1], 'phrases' => count($run), 'filled' => $filled];
        }

        // Words heard and written out whole (a quotation's first words and "…" are not).
        $words = array_filter($missing, fn (array $m): bool => preg_match('/\p{L}/u', $m['original']) === 1 && ! str_ends_with(trim($m['original']), '…'));
        $texts = [];
        foreach ($words as $k => $m) {
            if ($o['language'] === 'ar' && preg_match('/\p{Arabic}/u', $m['original']) === 1) {
                $texts[$k] = ['dubbed' => $m['original'], 'as_said' => true];
            }
        }
        $others = array_diff_key($words, $texts);
        if ($others !== []) {
            try {
                $written = $this->dubTexts(array_values(array_column($others, 'original')), $o['language'], $o['dialect'], $report);
                foreach (array_keys($others) as $i => $k) {
                    if (($written[$i] ?? '') !== '') {
                        $texts[$k] = ['dubbed' => $written[$i], 'written' => true];
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        foreach ($texts as $k => $text) {
            $segments[] = $text + ['start' => $phrases[$k][0], 'end' => $phrases[$k][1], 'phrase' => $k,
                'speaker' => $words[$k]['speaker'] !== '' ? $words[$k]['speaker'] : self::nearestSpeaker($segments, $phrases[$k][0], 'A'),
                'emotion' => null, 'delivery' => '', 'original' => $words[$k]['original']];
        }
        $report['phrases_left_quiet'] = count($missing) - count($texts);
        usort($segments, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_values($segments);
    }

    /** @param  array<int, array<string, mixed>>  $segments */
    private static function nearestSpeaker(array $segments, float $at, string $otherwise): string
    {
        $speaker = $otherwise;
        $distance = INF;
        foreach ($segments as $s) {
            $gap = max((float) $s['start'] - $at, $at - (float) $s['end'], 0.0);
            if ($gap < $distance && empty($s['again'])) {
                [$distance, $speaker] = [$gap, (string) $s['speaker']];
            }
        }

        return $speaker;
    }

    /**
     * Dubbed lines written from what was said, from the text alone (for
     * phrases heard but not dubbed from the sound): one for each, in order;
     * an empty one where the model gave none.
     *
     * @param  array<int, string>  $originals
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function dubTexts(array $originals, string $language, string $dialect, array &$report): array
    {
        $list = implode("\n", array_map(fn (string $text, int $i): string => ($i + 1).'. '.$text, $originals, array_keys($originals)));
        $answer = $this->askModel([['text' => 'You are writing the lines of a professional dub of a video. Write each of these lines, said in the video, '
            .'as a dubbed line in '.self::target($language, $dialect).': the same meaning, naturally, as a native speaker would say it, and about as long. '
            .'Leave nothing out and never sum anything up; quotations (verses of the Quran, sayings of the Prophet, prayers, poems) are dubbed too, translated faithfully.'
            ."\n{$list}\n".'Return only JSON: {"lines": ["...", "..."]}, one line for each, in the same order.']]);
        foreach ($answer['usage'] as $key => $tokens) {
            $report['transcribe'][$key] = ($report['transcribe'][$key] ?? 0) + $tokens;
        }

        return array_map(fn ($line): string => is_string($line) ? trim($line) : '', array_values(is_array($answer['data']) ? (array) ($answer['data']['lines'] ?? []) : []));
    }

    /**
     * The lines read in one take each: the phrases of a sentence together
     * (one speaker; pauses shorter than JOIN_GAP between them; at most
     * MAX_PARTS phrases and LONGEST_TAKE seconds; a new take after a sentence
     * ends), joined with a pause (PART_PAUSE) where the take is to be cut, so
     * the reading carries on as one while each part still goes on its own
     * phrase. Lines the model timed itself are read one by one.
     *
     * @param  array<int, array<string, mixed>>  $segments  in order of time
     * @return array<int, array{segments: array<int, int>, text: string}>
     */
    public static function lines(array $segments): array
    {
        $segments = array_values($segments);
        $lines = [];
        foreach ($segments as $i => $s) {
            $k = count($lines) - 1;
            $previous = $k >= 0 ? $segments[end($lines[$k]['segments'])] : null;
            if ($previous !== null && isset($s['phrase'], $previous['phrase']) && $s['speaker'] === $previous['speaker']
                && (float) $s['start'] - (float) $previous['end'] < self::JOIN_GAP && count($lines[$k]['segments']) < self::MAX_PARTS
                && (float) $s['end'] - (float) $segments[$lines[$k]['segments'][0]]['start'] <= self::LONGEST_TAKE
                && preg_match('/[.!?؟…]["»”\')\]]*$/u', trim((string) $previous['dubbed'])) !== 1) {
                $lines[$k]['segments'][] = $i;
            } else {
                $lines[] = ['segments' => [$i]];
            }
        }

        return array_map(fn (array $line): array => $line + ['text' => implode(self::PART_PAUSE,
            array_map(fn (int $i): string => trim((string) $segments[$i]['dubbed']), $line['segments']))], $lines);
    }

    /**
     * Where a take of several phrases is cut into its parts: at a pause in
     * it (0.12 s or more, 30 dB under its loud end) for each joint, as near
     * as may be to where the joint should come by how long each part's words
     * take ($weights), a longer pause counting for more; none farther than a
     * quarter of the take (0.8 s at least) from where it should come. Null
     * when the take has too few such pauses: it is placed whole then.
     *
     * @param  array<int, float>  $levels  the take's loudness, dB every 10 ms
     * @param  array<int, float>  $weights  seconds each part's words need, in order
     * @return array<int, array{0: float, 1: float}>|null  the pauses it is cut at (from, to: seconds into the take)
     */
    public static function cuts(array $levels, array $weights, int $fps = 100): ?array
    {
        $parts = count($weights);
        $frames = count($levels);
        if ($parts < 2) {
            return [];
        }
        if ($frames < 20) {
            return null;
        }
        $pauses = self::takePauses($levels, $fps);
        $m = count($pauses);
        if ($m < $parts - 1) {
            return null;
        }
        $length = $frames / $fps;
        $total = max(0.01, array_sum($weights));
        $reach = max(0.8, 0.25 * $length);
        $cost = [];
        $sum = 0.0;
        for ($k = 0; $k < $parts - 1; $k++) {
            $sum += $weights[$k];
            $expected = $length * $sum / $total;
            foreach ($pauses as $j => [$a, $b]) {
                $off = abs(($a + $b) / 2 - $expected);
                $cost[$k][$j] = $off <= $reach ? $off - 0.8 * min(0.6, $b - $a) : INF;
            }
        }
        // The best pause for each joint, each after the one before.
        $best = [];
        $from = [];
        for ($k = 0; $k < $parts - 1; $k++) {
            for ($j = 0; $j < $m; $j++) {
                $best[$k][$j] = $k === 0 ? $cost[$k][$j] : INF;
                for ($i = 0; $k > 0 && $i < $j; $i++) {
                    if ($best[$k - 1][$i] + $cost[$k][$j] < $best[$k][$j]) {
                        $best[$k][$j] = $best[$k - 1][$i] + $cost[$k][$j];
                        $from[$k][$j] = $i;
                    }
                }
            }
        }
        $j = null;
        foreach ($best[$parts - 2] as $i => $value) {
            if ($value < INF && ($j === null || $value < $best[$parts - 2][$j])) {
                $j = $i;
            }
        }
        if ($j === null) {
            return null;
        }
        $chosen = [];
        for ($k = $parts - 2; $k >= 0; $k--) {
            $chosen[$k] = $pauses[$j];
            $j = $from[$k][$j] ?? 0;
        }
        ksort($chosen);

        return array_values($chosen);
    }

    /**
     * The pauses inside a take (0.12 s or more, 30 dB under its loud end),
     * not counting the silence at its very start or end: [from, to] in
     * seconds into the take.
     *
     * @param  array<int, float>  $levels  the take's loudness, dB every 10 ms
     * @return array<int, array{0: float, 1: float}>
     */
    public static function takePauses(array $levels, int $fps = 100): array
    {
        $frames = count($levels);
        if ($frames < 20) {
            return [];
        }
        $sorted = $levels;
        sort($sorted);
        $gate = $sorted[(int) floor(0.95 * ($frames - 1))] - 30;
        $pauses = [];
        $run = null;
        for ($f = 0; $f <= $frames; $f++) {
            if ($f < $frames && $levels[$f] < $gate) {
                $run ??= $f;
            } elseif ($run !== null) {
                if ($run > 0 && $f < $frames && $f - $run >= 0.12 * $fps) {
                    $pauses[] = [round($run / $fps, 2), round($f / $fps, 2)];
                }
                $run = null;
            }
        }

        return $pauses;
    }

    /**
     * Where a sentence the text model timed itself is read in pieces: its
     * take cut at its own pauses and each piece set on the speaker's phrases
     * inside the same sentence, so where the speaker stops for breath the new
     * voice stops too instead of running on and ending early. A pause of the
     * take goes with a pause of the speaker when it comes at the same share
     * of the speech (the pauses left out of both), within SPLIT_REACH
     * seconds of the speaker's; the most such pairs are taken, in order, the
     * nearest first. A pause of the speaker with no pause of the take near
     * it stays inside its piece. Null when none pairs: the take stays whole.
     * Words never move from one sentence to another: the pieces are of this
     * sentence's take, on this sentence's phrases.
     *
     * @param  array<int, array{0: float, 1: float}>  $pauses  the take's (takePauses())
     * @param  array<int, array{0: float, 1: float}>  $phrases  the speaker's phrases inside the sentence, in order
     * @return array<int, array{take: array{0: float, 1: float}, at: array{0: float, 1: float}}>|null  each piece: from-to in the take, and the speaker's from-to
     */
    public static function splitTake(array $pauses, float $takeSeconds, array $phrases): ?array
    {
        $phrases = array_values($phrases);
        $n = count($phrases) - 1;
        $m = count($pauses);
        if ($n < 1 || $m < 1) {
            return null;
        }
        // The speaker's joints and the take's pauses, each as seconds of speech before it.
        $joints = [];
        $said = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $said += $phrases[$i][1] - $phrases[$i][0];
            $joints[] = $said;
        }
        $speech = $said + $phrases[$n][1] - $phrases[$n][0];
        $quiet = array_sum(array_map(fn (array $p): float => $p[1] - $p[0], $pauses));
        $voiced = $takeSeconds - $quiet;
        if ($speech <= 0 || $voiced <= 0) {
            return null;
        }
        $at = [];
        $before = 0.0;
        foreach ($pauses as [$a, $b]) {
            // On the speaker's scale: the take reads at its own pace.
            $at[] = ($a - $before) * $speech / $voiced;
            $before += $b - $a;
        }
        // The most pairs, in order; of those, the nearest in all.
        $best = array_fill(0, $n + 1, array_fill(0, $m + 1, [0, 0.0]));
        $better = fn (array $x, array $y): bool => $x[0] > $y[0] || ($x[0] === $y[0] && $x[1] < $y[1]);
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $best[$i][$j] = $better($best[$i - 1][$j], $best[$i][$j - 1]) ? $best[$i - 1][$j] : $best[$i][$j - 1];
                $off = abs($joints[$i - 1] - $at[$j - 1]);
                $pair = [$best[$i - 1][$j - 1][0] + 1, $best[$i - 1][$j - 1][1] + $off];
                if ($off <= self::SPLIT_REACH && $better($pair, $best[$i][$j])) {
                    $best[$i][$j] = $pair;
                }
            }
        }
        if ($best[$n][$m][0] === 0) {
            return null;
        }
        $pairs = [];
        for ($i = $n, $j = $m; $i > 0 && $j > 0;) {
            $off = abs($joints[$i - 1] - $at[$j - 1]);
            $pair = [$best[$i - 1][$j - 1][0] + 1, $best[$i - 1][$j - 1][1] + $off];
            if ($off <= self::SPLIT_REACH && $pair[0] === $best[$i][$j][0] && abs($pair[1] - $best[$i][$j][1]) < 1e-9) {
                $pairs[] = [$i - 1, $j - 1];
                $i--;
                $j--;
            } elseif ($best[$i - 1][$j] === $best[$i][$j]) {
                $i--;
            } else {
                $j--;
            }
        }
        $pairs = array_reverse($pairs);
        $pieces = [];
        $takeFrom = 0.0;
        $first = 0;
        foreach ([...$pairs, [$n, null]] as [$joint, $pause]) {
            $pieces[] = ['take' => [round($takeFrom, 2), round($pause === null ? $takeSeconds : $pauses[$pause][0], 2)],
                'at' => [$phrases[$first][0], $phrases[$joint][1]]];
            if ($pause !== null) {
                $takeFrom = $pauses[$pause][1];
                $first = $joint + 1;
            }
        }
        foreach ($pieces as $piece) {
            if ($piece['take'][1] - $piece['take'][0] < 0.3 || $piece['at'][1] - $piece['at'][0] < 0.3) {
                return null;
            }
        }

        return $pieces;
    }

    /**
     * How long the speaker takes over a sentence the text model timed itself:
     * from the start of the first of their phrases in it to the end of the
     * last (their pauses inside it included). Null for a line written for
     * a phrase, or where no phrase is found in it.
     *
     * @param  array<string, mixed>  $segment
     * @param  array<int, array{0: float, 1: float}>  $phrases
     */
    public static function speakerSpan(array $segment, array $phrases): ?float
    {
        $inside = isset($segment['phrase']) ? [] : self::phrasesIn($phrases, (float) $segment['start'], (float) $segment['end']);

        return $inside === [] ? null : round(end($inside)[1] - $inside[0][0], 2);
    }

    /**
     * The speaker's phrases inside a line (those of which half or more lies
     * in it), cut to the line's own start and end.
     *
     * @param  array<int, array{0: float, 1: float}>  $phrases
     * @return array<int, array{0: float, 1: float}>
     */
    public static function phrasesIn(array $phrases, float $start, float $end): array
    {
        $inside = array_values(array_filter($phrases, fn (array $p): bool => $p[1] > $p[0]
            && min($p[1], $end) - max($p[0], $start) >= 0.5 * ($p[1] - $p[0])));
        if ($inside !== []) {
            $inside[0][0] = max($inside[0][0], $start);
            $inside[count($inside) - 1][1] = min($inside[count($inside) - 1][1], $end);
        }

        return $inside;
    }

    /**
     * A line's take as the parts it is placed in: cut at its pauses (cuts())
     * into one piece for each phrase, or whole (from its first phrase to its
     * last) when it cannot be. A sentence the model timed itself is cut on
     * the speaker's own pauses inside it where its take pauses there too
     * (splitTake()): each piece then says where it goes ('from', 'until';
     * the first starts where the line starts, the last ends where it ends).
     *
     * @param  array{audio: string, seconds: float}  $take
     * @param  array{segments: array<int, int>, text: string}  $line
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<int, array{0: float, 1: float}>  $phrases  where the speaker speaks
     * @return array<int, array{segments: array<int, int>, audio: string, seconds: float, from?: float, until?: float}>
     */
    private function piecesOf(string $ffmpeg, array $take, array $line, array $segments, string $out, array $phrases = []): array
    {
        $whole = [['segments' => $line['segments'], 'audio' => $take['audio'], 'seconds' => (float) $take['seconds']]];
        if (count($line['segments']) < 2) {
            $s = $segments[$line['segments'][0]];
            $inside = isset($s['phrase']) ? [] : self::phrasesIn($phrases, (float) $s['start'], (float) $s['end']);
            $split = count($inside) < 2 ? null
                : self::splitTake(self::takePauses($this->speechEnvelope($ffmpeg, $take['audio'], false)), (float) $take['seconds'], $inside);
            if ($split === null) {
                return $whole;
            }
            $pieces = [];
            $last = count($split) - 1;
            foreach ($split as $k => $piece) {
                $file = $out.'-'.$k.'.wav';
                try {
                    $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $take['audio'], '-af', 'atrim=start='.$piece['take'][0].':end='.$piece['take'][1].',asetpts=PTS-STARTPTS', '-ac', '1', $file]);
                } catch (\Throwable) {
                    return $whole;
                }
                $pieces[] = ['segments' => $line['segments'], 'audio' => $file, 'seconds' => round($piece['take'][1] - $piece['take'][0], 3)]
                    + ($k > 0 ? ['from' => $piece['at'][0]] : []) + ($k < $last ? ['until' => $piece['at'][1]] : []);
            }

            return $pieces;
        }
        $cuts = self::cuts($this->speechEnvelope($ffmpeg, $take['audio'], false),
            array_map(fn (int $i): float => max(0.3, self::needs((string) $segments[$i]['dubbed'])), $line['segments']));
        if ($cuts === null) {
            return $whole;
        }
        $bounds = [0.0];
        foreach ($cuts as [$a, $b]) {
            array_push($bounds, $a, $b);
        }
        $bounds[] = (float) $take['seconds'];
        $pieces = [];
        foreach ($line['segments'] as $k => $i) {
            $file = $out.'-'.$k.'.wav';
            try {
                $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $take['audio'], '-af', 'atrim=start='.$bounds[2 * $k].':end='.$bounds[2 * $k + 1].',asetpts=PTS-STARTPTS', '-ac', '1', $file]);
            } catch (\Throwable) {
                return $whole;
            }
            $pieces[] = ['segments' => [$i], 'audio' => $file, 'seconds' => round($bounds[2 * $k + 1] - $bounds[2 * $k], 3)];
        }

        return $pieces;
    }

    /**
     * Where each original line really starts and ends, from how loud the
     * speech is moment by moment ($envelope: dB every 10 ms): the model that
     * hears the video places lines a moment early or late, and a dub that
     * starts before or after the lips move is the first thing anyone sees.
     * Each start is moved to where the speech begins within $reach seconds
     * of it (0.7 s for the model's times, less for phrases found on the
     * sound), each end to where it stops, never across the line before or
     * after. A line where no clear speech is found keeps its times. The
     * times it had stay as heard_start and heard_end.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<int, float>  $envelope
     * @return array<int, array<string, mixed>>
     */
    public static function refineTimes(array $segments, array $envelope, float $seconds, int $fps = 100, float $reach = 0.7): array
    {
        $frames = count($envelope);
        if ($frames < 20) {
            return $segments;
        }
        $sorted = $envelope;
        sort($sorted);
        $floor = $sorted[(int) floor(0.2 * ($frames - 1))];
        $at = fn (float $t): int => max(0, min($frames - 1, (int) round($t * $fps)));
        $above = fn (int $f, float $level): bool => $envelope[$f] >= $level && $envelope[min($frames - 1, $f + 1)] >= $level && $envelope[min($frames - 1, $f + 2)] >= $level;
        $segments = array_values($segments);
        $count = count($segments);
        $previousEnd = 0.0;
        foreach ($segments as $i => $s) {
            $start = (float) $s['start'];
            $end = max($start + 0.2, (float) $s['end']);
            $nextStart = $i + 1 < $count ? (float) $segments[$i + 1]['start'] : $seconds;
            $segments[$i]['heard_start'] = $start;
            $segments[$i]['heard_end'] = $end;
            $slice = array_slice($envelope, $at($start), max(1, $at($end) - $at($start) + 1));
            rsort($slice);
            $peak = $slice[(int) floor(0.05 * (count($slice) - 1))];
            if ($peak < $floor + 8) {
                $previousEnd = $end;

                continue;
            }
            // Speech: within 28 dB of the line's loudest, and clearly above the quiet of the whole track.
            $level = max($floor + 10, $peak - 28);
            $onset = null;
            for ($f = $at(max($previousEnd + 0.05, $start - $reach)), $last = $at(min($end - 0.2, $start + $reach)); $f <= $last; $f++) {
                if ($above($f, $level)) {
                    $onset = $f / $fps;
                    break;
                }
            }
            $offset = null;
            for ($f = $at(min($nextStart - 0.05, $end + $reach, $seconds)), $first = $at(max(($onset ?? $start) + 0.3, $end - $reach)); $f >= $first; $f--) {
                if ($envelope[$f] >= $level && $envelope[max(0, $f - 1)] >= $level && $envelope[max(0, $f - 2)] >= $level) {
                    $offset = ($f + 1) / $fps;
                    break;
                }
            }
            $segments[$i]['start'] = round($onset ?? $start, 2);
            $segments[$i]['end'] = round(max($segments[$i]['start'] + 0.3, $offset ?? $end), 2);
            $previousEnd = $segments[$i]['end'];
        }

        return $segments;
    }

    /**
     * How loud the speech is every 10 ms, in dB: on the model's voices (raw
     * float stereo) or, without them, on the original sound's speech band.
     *
     * @return array<int, float>
     */
    private function speechEnvelope(string $ffmpeg, string $source, bool $raw): array
    {
        $input = $raw ? ['-f', 'f32le', '-ar', (string) self::SEPARATION_RATE, '-ac', '2', '-i', $source] : ['-i', $source];
        $process = new Process(array_merge([$ffmpeg, '-v', 'error'], $input, ['-vn', '-af', 'aformat=channel_layouts=mono,highpass=f=150,lowpass=f=4000,aresample=8000',
            '-f', 's16le', '-acodec', 'pcm_s16le', '-']));
        $process->setTimeout(120)->run();
        $pcm = $process->isSuccessful() ? $process->getOutput() : '';
        $envelope = [];
        $samples = unpack('s*', $pcm) ?: [];
        $values = array_values($samples);
        for ($i = 0, $n = count($values); $i + 80 <= $n; $i += 80) {
            $sum = 0.0;
            for ($j = $i; $j < $i + 80; $j++) {
                $sum += $values[$j] * $values[$j];
            }
            $envelope[] = 10 * log10($sum / 80 / (32768 * 32768) + 1e-10);
        }

        return $envelope;
    }

    /**
     * A voiced line without the silence before and after its speech, and how
     * long it is then; the line as it was (and null) when that fails.
     *
     * @return array{0: string, 1: ?float}
     */
    private function trimmed(string $ffmpeg, string $audio, string $out): array
    {
        try {
            $edge = 'silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.04';
            $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $audio, '-af', $edge.',areverse,'.$edge.',areverse', '-ac', '1', $out]);
            $seconds = $this->duration($ffmpeg, $out);

            return $seconds >= 0.2 ? [$out, $seconds] : [$audio, null];
        } catch (\Throwable) {
            return [$audio, null];
        }
    }

    /**
     * One request: what is said, by whom and how, with a dubbed line for it,
     * and what plays behind it. With $phrases (found on the sound itself,
     * speechMap()) the model writes out each phrase and a dubbed part for it,
     * so every moment someone speaks gets its line, timed by the phrase;
     * without, it splits the speech into timed segments itself. $part says
     * the audio is a part cut out of the video (heard again): its times are
     * from the part's own start.
     *
     * The model will not copy a well-known text word for word (a verse, a
     * saying of the Prophet, a song): asked to, it gives no answer at all
     * (finish RECITATION). Quotations are dubbed by their meaning, and when
     * an answer still comes back empty it is asked once more without their
     * words being written out. An empty answer is never taken for a video
     * nobody speaks in: that fails as not written out.
     *
     * @param  array<int, array{0: float, 1: float}>  $phrases
     * @return array{segments: array<int, array<string, mixed>>, speakers: array<int, array{id: string, gender: string}>, background: string, unsaid: array<int, array<string, mixed>>, usage: array<string, int>, model: string, finish: array<int, string>}
     */
    private function transcribeAndRewrite(string $audio, string $language, string $dialect, array $phrases = [], bool $part = false): array
    {
        $finish = [];
        $usage = [];
        foreach ([false, true] as $careful) {
            $answer = $this->askModel([['inline_data' => ['mime_type' => 'audio/mpeg', 'data' => base64_encode($audio)]],
                ['text' => $this->hearingPrompt($language, $dialect, $phrases, $part, $careful)]]);
            $finish[] = $answer['finish'];
            foreach ($answer['usage'] as $key => $tokens) {
                $usage[$key] = ($usage[$key] ?? 0) + $tokens;
            }
            if ($answer['data'] !== null) {
                $heard = $phrases !== [] ? self::readAnchored($answer['data'], $phrases) : self::readHeard($answer['data']) + ['unsaid' => []];

                return $heard + ['usage' => $usage, 'model' => $answer['model'], 'finish' => $finish];
            }
            Log::warning('Dub: the text model gave no answer to read.', ['finish' => $answer['finish'], 'model' => $answer['model'], 'careful' => $careful]);
        }

        throw new SpeechNotWrittenOut($finish);
    }

    /** Whether a dubbed line has words to say: not only a sound tag, a pause or "…" (the speech engine takes none of those alone). */
    public static function spoken(string $text): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', (string) preg_replace('/<[^<>]*>|\[[^\[\]]*\]/u', ' ', $text)) === 1;
    }

    /**
     * What the text model is asked (transcribeAndRewrite()); $careful: the
     * words of a quotation are not written out, only its meaning dubbed.
     *
     * @param  array<int, array{0: float, 1: float}>  $phrases
     */
    private function hearingPrompt(string $language, string $dialect, array $phrases, bool $part, bool $careful): string
    {
        $target = self::target($language, $dialect);
        $emotions = implode(', ', TtsStudioConfig::emotionValues());
        $tags = implode(', ', array_map(fn (string $tag): string => '<'.$tag.'>', self::SOUND_TAGS));
        $people = "Tell the people apart: give each a letter (A, B, ...), say whether they sound male or female, and describe how they speak for a voice actor "
            ."in at most 10 words of English: pace, energy and manner only, not age or gender (for example \"slow and calm, documentary narrator\"). "
            ."One person is one letter all through, also across music, pauses or a change of mood; give a new letter only when a clearly different person speaks.\n";
        $manner = "say how it is spoken, as a director would brief a voice actor: \"emotion\" is the one word from this list "
            ."that fits best: {$emotions}; \"delivery\" is a short direction in English (energy, pace, loudness, attitude), at most 15 words, "
            ."for example \"bright and fast, smiling, building to a big finish\".\n";
        $faithful = 'Leave nothing out and never sum anything up. Quotations are dubbed too (verses of the Quran, sayings of the Prophet, prayers, poems and songs), '
            .'but never copy them out: '
            .($careful
                ? 'for a quotation write only "…" as its original, and in the dubbed line give its whole meaning in plain words of your own. '
                : 'for a quotation write only its first three words and "…" as its original, and in the dubbed line give its whole meaning in the dub\'s own words. ')
            ."Keep names, brands and numbers right. Where the speaker laughs, sighs, gasps, whispers, shouts or pauses, put the matching tag at that place in the dubbed line, only from these: {$tags}.\n";
        $sound = "Say what plays besides the speech: \"music\" if there is music, \"ambient\" for other sound (crowd, street, room, effects), \"none\" if only the voice.\n";
        $prompt = "You are preparing a professional dub of a video. Listen to its audio closely.\n"
            .($part ? "This audio is a short part cut out of a longer video; it may start or end in the middle of a word. All times are from the start of this audio.\n" : '');
        if ($phrases !== []) {
            $list = implode("\n", array_map(fn (array $p, int $k): string => sprintf('%d. %.2f-%.2f (%.1f s)', $k + 1, $p[0], $p[1], $p[1] - $p[0]), $phrases, array_keys($phrases)));
            $prompt .= 'Where someone speaks in it was found from the sound itself, as these '.count($phrases)." numbered phrases (start-end, in seconds from the start of the audio):\n{$list}\n"
                .'1. For each phrase write out exactly what is said in it ("original"), every word, in the language spoken. A phrase is often only a part of a sentence: '
                .'give only the words spoken inside its own time. A phrase with no words in it (a laugh, a breath, a cough, music, a noise) has an empty original and dubbed.'."\n"
                .'2. '.$people
                .'3. For each phrase '.$manner
                ."4. For each phrase write its dubbed part in {$target}: what is said in it, naturally, as a native speaker would say it there, so that it can be spoken "
                .'in the phrase\'s own time (not longer). Where a sentence runs over several phrases, split the dubbed sentence over them in the same order, so each part '
                .'is heard where its own words are. '.$faithful
                .'5. '.$sound
                .'Return only JSON, with every phrase from 1 to '.count($phrases).' in order: {"background": "music", "speakers": [{"id": "A", "gender": "male", "voice": "..."}], '
                .'"phrases": [{"n": 1, "speaker": "A", "emotion": "excited", "delivery": "...", "original": "...", "dubbed": "..."}]}.';
        } else {
            $prompt .= "1. Split what is said into segments at natural sentence or phrase boundaries, each 2 to 10 seconds long and spoken by one person, "
                ."with its start and end in seconds from the start of the audio, as plain seconds also past the first minute (75.4, never 1:15.4 or 115.4). Cover everything that is said, from the first word to the very last one "
                ."at the end of the audio: never leave out, merge away or sum up any part, and give each segment every word spoken in it.\n"
                .'2. '.$people
                .'3. For each segment '.$manner
                ."4. For each segment write a dubbed line in {$target} that says all of the same thing naturally, as a native speaker would say it in that moment, "
                .'and can be spoken in about the same time as the original: not longer than the original, nor much shorter (keep every name in full), '
                .'with a comma where the speaker pauses. '.$faithful
                .'5. '.$sound
                .'Return only JSON: {"background": "music", "speakers": [{"id": "A", "gender": "male", "voice": "..."}], "segments": [{"start": 0.0, "end": 3.2, "speaker": "A", '
                .'"emotion": "excited", "delivery": "...", "original": "...", "dubbed": "..."}]}. If nothing is spoken, return {"background": "music", "speakers": [], "segments": []}.';
        }

        return $prompt;
    }

    /** What the dubbed lines are written in: the dialect (or accent), as people there really speak it. */
    private static function target(string $language, string $dialect): string
    {
        if ($dialect === 'ar-XA') {
            return 'Modern Standard Arabic (Fusha), clear and natural';
        }

        return $language === 'ar'
            ? 'Arabic in the '.VoiceFlag::label($dialect, false).' dialect, written the way people there really speak (not Modern Standard Arabic unless that is the dialect asked for)'
            : 'English with a '.VoiceFlag::label($dialect, false).' accent and wording';
    }

    /**
     * One request to the text model, the models tried in turn and each one's
     * keys: its answer as JSON (its text parts together, or the JSON found in
     * them; null when there is none, as when it would not answer), what it
     * used, which model gave it and why it stopped (STOP, RECITATION, ...).
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array{data: ?array<string, mixed>, usage: array<string, int>, model: string, finish: string}
     */
    private function askModel(array $parts): array
    {
        $base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.3, 'thinkingConfig' => ['thinkingLevel' => 'low']],
        ];
        $last = 'no key';
        foreach (self::MODELS as $model) {
            foreach ($this->keys->keysInOrder($model) as $key) {
                $response = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key['key']])->post($base.'/models/'.$model.':generateContent', $body);
                if ($response->status() === 400 && str_contains(strtolower($response->body()), 'thinking')) {
                    unset($body['generationConfig']['thinkingConfig']);
                    $response = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key['key']])->post($base.'/models/'.$model.':generateContent', $body);
                }
                $this->keys->recordUsage($key['name']);
                if (in_array($response->status(), [404], true)) {
                    $last = 'model '.$model.' not found';

                    continue 2;
                }
                if (! $response->ok()) {
                    $last = 'HTTP '.$response->status();

                    continue;
                }
                $raw = '';
                foreach ((array) $response->json('candidates.0.content.parts', []) as $piece) {
                    $raw .= is_array($piece) && empty($piece['thought']) ? (string) ($piece['text'] ?? '') : '';
                }
                $raw = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $raw));
                $data = json_decode($raw, true);
                if (! is_array($data) && preg_match('/\{.*\}/s', $raw, $m) === 1) {
                    $data = json_decode($m[0], true);
                }

                return ['data' => is_array($data) ? $data : null, 'usage' => self::usage((array) $response->json('usageMetadata')), 'model' => $model,
                    'finish' => (string) ($response->json('candidates.0.finishReason') ?? $response->json('promptFeedback.blockReason') ?? '')];
            }
        }

        throw new \RuntimeException('The speech could not be written out ('.$last.').');
    }

    /**
     * The model's answer for the phrases made safe (as readHeard()): a line
     * for each phrase it dubbed, timed by the phrase itself ('phrase': its
     * number, from 0); and the phrases it left without a dubbed line though
     * it heard words in them, or gave no answer for at all ('unsaid', with
     * what it heard). A phrase it heard no words in (a laugh, music) is
     * neither.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{0: float, 1: float}>  $phrases
     * @return array{segments: array<int, array<string, mixed>>, speakers: array<int, array{id: string, gender: string}>, background: string, unsaid: array<int, array{phrase: int, original: string, speaker: string}>}
     */
    public static function readAnchored(array $data, array $phrases): array
    {
        $answers = [];
        foreach ((array) ($data['phrases'] ?? []) as $p) {
            $n = is_array($p) && is_numeric($p['n'] ?? null) ? (int) $p['n'] : 0;
            if ($n >= 1 && $n <= count($phrases) && ! isset($answers[$n - 1])) {
                $answers[$n - 1] = $p;
            }
        }
        $timed = [];
        $unsaid = [];
        foreach (array_values($phrases) as $k => [$start, $end]) {
            $p = $answers[$k] ?? null;
            $original = self::text($p['original'] ?? '');
            if ($p !== null && self::spoken(self::text($p['dubbed'] ?? ''))) {
                $timed[] = ['start' => $start, 'end' => $end, 'phrase' => $k] + $p;
            } elseif ($p === null || $original !== '') {
                $unsaid[] = ['phrase' => $k, 'original' => $original, 'speaker' => mb_substr(self::text($p['speaker'] ?? ''), 0, 8)];
            }
        }

        return self::readHeard(['background' => $data['background'] ?? null, 'speakers' => $data['speakers'] ?? [], 'segments' => $timed]) + ['unsaid' => $unsaid];
    }

    /** A value of the model's answer as text: a word or a number as it is, anything else (a list, nothing) as empty. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The model's answer made safe: lines with words and a start, in order;
     * a speaker for every line and a gender for every speaker; an emotion
     * only from the studio's list; a background of music, ambient or none.
     *
     * @param  array<string, mixed>  $data
     * @return array{segments: array<int, array<string, mixed>>, speakers: array<int, array{id: string, gender: string}>, background: string}
     */
    public static function readHeard(array $data): array
    {
        $emotions = array_flip(TtsStudioConfig::emotionValues());
        $genders = [];
        $characters = [];
        foreach ((array) ($data['speakers'] ?? []) as $speaker) {
            $id = mb_substr(self::text($speaker['id'] ?? ''), 0, 8);
            if ($id !== '') {
                $genders[$id] = strtolower(self::text($speaker['gender'] ?? '')) === 'female' ? 'female' : 'male';
                $characters[$id] = mb_substr(trim((string) preg_replace('/\s+/', ' ', self::text($speaker['voice'] ?? ''))), 0, 120);
            }
        }
        $segments = [];
        foreach ((array) ($data['segments'] ?? []) as $s) {
            $dubbed = self::text($s['dubbed'] ?? '');
            if (! self::spoken($dubbed) || ! is_numeric($s['start'] ?? null)) {
                continue;
            }
            $speaker = mb_substr(self::text($s['speaker'] ?? ''), 0, 8) ?: 'A';
            $genders[$speaker] ??= 'male';
            $emotion = strtolower(self::text($s['emotion'] ?? ''));
            $segments[] = [
                'start' => (float) $s['start'],
                'end' => max((float) $s['start'], is_numeric($s['end'] ?? null) ? (float) $s['end'] : (float) $s['start']),
                'speaker' => $speaker,
                'emotion' => isset($emotions[$emotion]) ? $emotion : null,
                'delivery' => mb_substr(self::text($s['delivery'] ?? ''), 0, 160),
                'original' => self::text($s['original'] ?? ''),
                'dubbed' => $dubbed,
            ] + (isset($s['phrase']) ? ['phrase' => (int) $s['phrase']] : []);
        }
        usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);
        $background = strtolower(self::text($data['background'] ?? ''));

        return [
            'segments' => $segments,
            'speakers' => array_values(array_map(fn (string $id, string $gender): array => ['id' => $id, 'gender' => $gender, 'voice' => $characters[$id] ?? ''],
                array_keys($genders), $genders)),
            // When the model does not say, the original sound is kept: dropping music is the worse mistake.
            'background' => in_array($background, ['music', 'ambient', 'none'], true) ? $background : 'ambient',
        ];
    }

    /**
     * The model's own count of what the request used.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, int>
     */
    public static function usage(array $meta): array
    {
        $audio = 0;
        foreach ((array) ($meta['promptTokensDetails'] ?? []) as $detail) {
            if (strtoupper((string) ($detail['modality'] ?? '')) === 'AUDIO') {
                $audio += (int) ($detail['tokenCount'] ?? 0);
            }
        }

        return [
            'input_tokens' => (int) ($meta['promptTokenCount'] ?? 0),
            'audio_tokens' => $audio,
            'output_tokens' => (int) ($meta['candidatesTokenCount'] ?? 0),
            'thinking_tokens' => (int) ($meta['thoughtsTokenCount'] ?? 0),
        ];
    }

    /**
     * The new voice over the picture. With $keepBackground the video's other
     * sound stays: with $background (the model's two tracks, raw float
     * stereo) its rest plays all through and its voices only between the new
     * lines; without it, the original plays between lines and under each
     * line only its sides (the old voice, in the middle, goes, and with it a
     * mono track's music), so no old voice is heard under a new one. $spans
     * (from, until) are taken out as the lines are: the phrases of the
     * original speaker that have no line of their own.
     *
     * @param  array<int, array{audio: ?string, delay_ms: int, tempo: float, from: float, until: float}>  $plan
     * @param  array{path: string, margin: int}|null  $mark
     * @param  array{rest: string, voices: string, further?: ?string}|null  $background
     * @param  array<int, array{from: float, until: float}>  $spans
     * @return array<int, string>
     */
    private function muxCommand(string $ffmpeg, string $video, array $plan, float $seconds, bool $keepBackground, string $out, ?array $mark, ?array $background = null, array $spans = []): array
    {
        $cmd = [$ffmpeg, '-v', 'error', '-y', '-i', $video];
        foreach ($plan as $p) {
            array_push($cmd, '-i', (string) $p['audio']);
        }
        $next = count($plan) + 1;
        $filters = [];
        $labels = '';
        foreach ($plan as $i => $p) {
            $tempo = $p['tempo'] > 1.0 ? 'atempo='.$p['tempo'].',' : '';
            $gain = abs((float) ($p['gain_db'] ?? 0)) >= 0.1 ? 'volume='.$p['gain_db'].'dB,' : '';
            // Without the speech engine's low rumble.
            $filters[] = '['.($i + 1).':a]'.self::VOICE_STEREO.',aresample=48000,highpass=f=70,'.$gain.$tempo.'adelay=delays='.$p['delay_ms'].':all=1[s'.$i.']';
            $labels .= '[s'.$i.']';
        }
        $filters[] = $labels.'amix=inputs='.count($plan).':normalize=0:dropout_transition=0[dub]';
        $audioOut = '[dub]';
        if ($keepBackground && $background !== null) {
            // The rest of the sound (music, effects) all through; the original voices only between the new lines,
            // and under them the voices further back than the main speaker (children reciting, a crowd), when they were told apart.
            $further = $background['further'] ?? null;
            foreach (array_filter([$background['rest'], $background['voices'], $further]) as $track) {
                array_push($cmd, '-f', 'f32le', '-ar', (string) self::SEPARATION_RATE, '-ac', '2', '-i', $track);
            }
            $under = self::underLines(array_merge($plan, $spans));
            // The music and effects as they were, all through.
            $filters[] = '['.$next.':a]aresample=48000[bg]';
            $filters[] = '['.($next + 1).":a]aresample=48000,volume='1-({$under})':eval=frame[ov]";
            if ($further !== null) {
                $filters[] = '['.($next + 2).":a]aresample=48000,volume='{$under}':eval=frame[fv]";
                $filters[] = '[bg][ov][fv][dub]amix=inputs=4:normalize=0:dropout_transition=0,'.self::LIMITER.'[mix]';
            } else {
                $filters[] = '[bg][ov][dub]amix=inputs=3:normalize=0:dropout_transition=0,'.self::LIMITER.'[mix]';
            }
            $audioOut = '[mix]';
            $next += $further !== null ? 3 : 2;
        } elseif ($keepBackground) {
            $under = self::underLines(array_merge($plan, $spans));
            $filters[] = '[0:a]'.self::stereo($this->probe($video)['channels']).',aresample=48000,asplit=2[oa][ob]';
            // Between lines: the original as it is.
            $filters[] = "[oa]volume='1-({$under})':eval=frame[clear]";
            // Under lines: only the sides of a stereo mix, where music spreads and a voice does not (a mono track goes quiet).
            $filters[] = "[ob]pan=stereo|c0=c0-c1|c1=c1-c0,volume='{$under}':eval=frame[carved]";
            $filters[] = '[clear][carved][dub]amix=inputs=3:normalize=0:dropout_transition=0,'.self::LIMITER.'[mix]';
            $audioOut = '[mix]';
        }
        $videoMap = ['-map', '0:v:0', '-c:v', 'copy'];
        if ($mark !== null) {
            $markInput = $next;
            array_push($cmd, '-i', $mark['path']);
            $filters[] = '['.$markInput.':v]format=rgba,colorchannelmixer=aa=0.92[mk]';
            $filters[] = '[0:v][mk]overlay=W-w-'.$mark['margin'].':H-h-'.$mark['margin'].'[v]';
            $videoMap = ['-map', '[v]', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p'];
        }

        return array_merge($cmd, ['-filter_complex', implode(';', $filters)], $videoMap,
            ['-map', $audioOut, '-c:a', 'aac', '-b:a', '160k', '-t', (string) $seconds, '-movflags', '+faststart', $out]);
    }

    /**
     * Where the old voices are taken out: each new line from a little before
     * it starts (LEAD) to a little after it or the original ends (TAIL),
     * with gaps shorter than BRIDGE closed.
     *
     * @param  array<int, array{from: float, until: float}>  $plan
     * @return array<int, array{0: float, 1: float}>
     */
    public static function windows(array $plan): array
    {
        $spans = array_map(fn (array $p): array => [max(0.0, $p['from'] - self::LEAD), $p['until'] + self::TAIL], $plan);
        usort($spans, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($spans as $span) {
            $last = count($merged) - 1;
            if ($last >= 0 && $span[0] - $merged[$last][1] < self::BRIDGE) {
                $merged[$last][1] = max($merged[$last][1], $span[1]);
            } else {
                $merged[] = $span;
            }
        }

        return array_map(fn (array $w): array => [round($w[0], 3), round($w[1], 3)], $merged);
    }

    /**
     * An expression of the time t, for ffmpeg's volume filter: 1 where the
     * old voices are taken out (windows()), 0 elsewhere, fading over RAMP
     * seconds either side.
     *
     * @param  array<int, array{from: float, until: float}>  $plan
     */
    public static function underLines(array $plan): string
    {
        $r = self::RAMP;
        $parts = [];
        foreach (self::windows($plan) as [$a, $b]) {
            $a0 = round($a - $r, 3);
            $b1 = round($b + $r, 3);
            $parts[] = "clip((t-{$a0})/{$r},0,1)*clip(({$b1}-t)/{$r},0,1)";
        }

        return $parts === [] ? '0' : 'min(1,'.implode('+', $parts).')';
    }

    /**
     * Each line's gain to the loudness the original speaker had: the voices
     * of the original where the lines are ($reference: the model's voices,
     * raw float stereo, or the whole sound), both measured on the speech
     * alone (silences left out). A line is turned up or down by at most
     * MAX_GAIN_DB; nothing changes where the original cannot be measured.
     * The parts of one take ($takes: its file, by the plan's 'line') are
     * measured as the take and turned up or down alike, so a reading keeps
     * its own rise and fall.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<int, string>  $takes
     * @return array<int, array<string, mixed>>
     */
    private function evenLoudness(string $ffmpeg, array $plan, string $reference, bool $raw, array $takes = []): array
    {
        $speechOnly = 'silenceremove=start_periods=1:start_threshold=-45dB:stop_periods=-1:stop_threshold=-45dB:stop_duration=0.25';
        $ranges = implode('+', array_map(fn (array $p): string => 'between(t,'.$p['from'].','.$p['until'].')', $plan));
        $input = $raw ? ['-f', 'f32le', '-ar', (string) self::SEPARATION_RATE, '-ac', '2', '-i', $reference] : ['-i', $reference];
        $target = $ranges === '' ? null : $this->meanVolume(array_merge([$ffmpeg, '-hide_banner'], $input, ['-af', "aselect='{$ranges}',{$speechOnly},volumedetect", '-f', 'null', '-']));
        $target = $target === null ? null : max(-32.0, min(-12.0, $target));
        $levels = [];
        foreach ($plan as $i => $p) {
            $source = isset($p['line'], $takes[$p['line']]) ? $takes[$p['line']] : ($p['audio'] ?? null);
            if (! array_key_exists((string) $source, $levels)) {
                $levels[(string) $source] = $target === null || ! is_string($source) ? null
                    : $this->meanVolume([$ffmpeg, '-hide_banner', '-i', $source, '-af', $speechOnly.',volumedetect', '-f', 'null', '-']);
            }
            $level = $levels[(string) $source];
            $plan[$i]['target_db'] = $target;
            $plan[$i]['gain_db'] = $level === null ? 0.0 : round(max(-self::MAX_GAIN_DB, min(self::MAX_GAIN_DB, $target - $level)), 1);
        }

        return $plan;
    }

    /** @param  array<int, string>  $cmd  an ffmpeg run ending in volumedetect */
    private function meanVolume(array $cmd): ?float
    {
        $process = new Process($cmd);
        $process->setTimeout(120)->run();

        return preg_match('/mean_volume:\s*(-?[\d.]+) dB/', $process->getErrorOutput(), $m) ? (float) $m[1] : null;
    }

    /**
     * The Listen mark for the preview: the site's logo with a soft shadow, sized to the picture
     * (about a quarter of its short side) so it looks the same on a phone clip and a wide one.
     *
     * @return array{path: string, margin: int}
     */
    private function markFile(string $ffmpeg, string $tmp, string $video): array
    {
        $process = new Process([$ffmpeg, '-hide_banner', '-i', $video]);
        $process->setTimeout(30)->run();
        $short = preg_match('/Video:.*?, (\d{2,5})x(\d{2,5})/', $process->getErrorOutput(), $m) ? min((int) $m[1], (int) $m[2]) : 720;
        $png = $tmp.'/mark.png';
        $width = max(120, (int) round($short * 0.26 / 2) * 2);
        $this->run([$ffmpeg, '-v', 'error', '-y', '-i', public_path('images/dub-mark.png'), '-vf', 'scale='.$width.':-1:flags=lanczos', $png]);

        return ['path' => $png, 'margin' => max(10, (int) round($short * 0.03))];
    }

    /**
     * A video's sound into stereo, at its own level: a mono track the same in
     * both sides, a stereo one as it is, more channels mixed down.
     */
    public static function stereo(int $channels): string
    {
        return $channels === 1 ? 'pan=stereo|c0=c0|c1=c0' : 'aformat=channel_layouts=stereo';
    }

    /**
     * How long a video runs, whether it has a picture and a sound track, and
     * how many channels the sound has; throws when it cannot be read at all.
     *
     * @return array{seconds: float, picture: bool, sound: bool, channels: int}
     */
    public function probe(string $video): array
    {
        $ffmpeg = AudioFormats::ffmpegPath() ?? throw new \RuntimeException('ffmpeg is not available on this server.');
        $process = new Process([$ffmpeg, '-hide_banner', '-i', $video]);
        $process->setTimeout(30)->run();
        $info = $process->getErrorOutput();

        $layout = preg_match('/Stream #.*Audio:[^\n]*?Hz, ([^,\n]+)/', $info, $m) ? trim($m[1]) : 'stereo';
        $channels = match (true) {
            $layout === 'mono' => 1,
            $layout === 'stereo' => 2,
            preg_match('/^(\d+) channels/', $layout, $c) === 1 => (int) $c[1],
            default => 6,
        };

        return ['seconds' => $this->duration($ffmpeg, $video), 'picture' => preg_match('/Stream #.*Video:/', $info) === 1,
            'sound' => preg_match('/Stream #.*Audio:/', $info) === 1, 'channels' => $channels];
    }

    private function duration(string $ffmpeg, string $video): float
    {
        $process = new Process([$ffmpeg, '-hide_banner', '-i', $video]);
        $process->setTimeout(30)->run();
        if (! preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $process->getErrorOutput(), $m)) {
            throw new \RuntimeException('The video could not be read.');
        }

        return round($m[1] * 3600 + $m[2] * 60 + (float) $m[3], 2);
    }

    /**
     * Runs ffmpeg and says how long it took, on the clock and on the CPU.
     *
     * @param  array<int, string>  $cmd
     * @return array{seconds: float, cpu_seconds: float}
     */
    private function run(array $cmd): array
    {
        $before = getrusage(1);
        $t = microtime(true);
        $process = new Process($cmd);
        $process->setTimeout(600)->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed: '.mb_substr(trim($process->getErrorOutput()), 0, 300));
        }
        $after = getrusage(1);
        $cpu = ($after['ru_utime.tv_sec'] + $after['ru_stime.tv_sec'] - $before['ru_utime.tv_sec'] - $before['ru_stime.tv_sec'])
            + ($after['ru_utime.tv_usec'] + $after['ru_stime.tv_usec'] - $before['ru_utime.tv_usec'] - $before['ru_stime.tv_usec']) / 1e6;

        return ['seconds' => round(microtime(true) - $t, 2), 'cpu_seconds' => round($cpu, 2)];
    }
}
