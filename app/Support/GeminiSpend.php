<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * What Google bills for the Gemini calls made while a meter is open: the token
 * counts each reply carries (usageMetadata on generateContent, usage on the
 * Interactions API), priced for its model on the day of the call
 * (config analytics.gemini_prices).
 *
 * Every Gemini request goes through the HTTP client's global middleware
 * (AppServiceProvider), so retries, retakes, pieces sent together, the dialect
 * rewrite and the translation are each counted once. A reply without counts
 * (an error Google does not bill) adds nothing. Meters can be open inside one
 * another: a call counts on each open meter.
 */
final class GeminiSpend
{
    /** Replies up to this size are read whole; a longer one (audio) from its end. */
    private const WHOLE_BODY_BYTES = 262_144;

    private const TAIL_BYTES = 65_536;

    /** @var list<self> */
    private static array $open = [];

    /** @var array<string, true> */
    private static array $unpriced = [];

    /** @var array<string, array{0: bool, 1: float}> whether each table has the columns, and when that was checked */
    private static array $columns = [];

    private int $calls = 0;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private float $cost = 0.0;

    /** @var array<string, array{calls: int, input_tokens: int, output_tokens: int, cost_usd: float}> */
    private array $models = [];

    public static function start(): self
    {
        $meter = new self;
        self::$open[] = $meter;

        return $meter;
    }

    public function stop(): self
    {
        self::$open = array_values(array_filter(self::$open, fn (self $m): bool => $m !== $this));

        return $this;
    }

    public static function listening(): bool
    {
        return self::$open !== [];
    }

    /**
     * Whether $table keeps the counts yet. The migration is run by hand, so a
     * queue worker started before it asks again every ten minutes.
     */
    public static function canStore(string $table): bool
    {
        [$has, $checkedAt] = self::$columns[$table] ?? [false, 0.0];
        if (! $has && microtime(true) - $checkedAt > 600) {
            $has = (bool) rescue(fn (): bool => Schema::hasColumn($table, 'api_calls'), false, false);
            self::$columns[$table] = [$has, microtime(true)];
        }

        return $has;
    }

    /**
     * The counts for a generation row: added to what earlier tries spent, so a voice
     * that took two tries shows both. Empty while the table cannot keep them.
     *
     * @param  object|null  $row  the row as it is now (api_calls null: nothing counted yet)
     * @return array<string, int>
     */
    public function columnsFor(string $table, ?object $row = null): array
    {
        if (! self::canStore($table)) {
            return [];
        }

        return [
            'api_calls' => (int) ($row->api_calls ?? 0) + $this->calls,
            'api_input_tokens' => (int) ($row->api_input_tokens ?? 0) + $this->inputTokens,
            'api_output_tokens' => (int) ($row->api_output_tokens ?? 0) + $this->outputTokens,
        ];
    }

    /** What earlier tries of this row already spent (0 when nothing was counted). */
    public static function spentBefore(string $table, ?object $row): float
    {
        return self::canStore($table) && $row !== null && $row->api_calls !== null ? (float) ($row->tts_cost_estimate_usd ?? 0) : 0.0;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    /** USD, to the millionth of a dollar the generation tables keep. */
    public function cost(): float
    {
        return round($this->cost, 6);
    }

    /**
     * @return array{calls: int, input_tokens: int, output_tokens: int, cost_usd: float, models: array<string, array{calls: int, input_tokens: int, output_tokens: int, cost_usd: float}>}
     */
    public function summary(): array
    {
        return [
            'calls' => $this->calls,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost_usd' => $this->cost(),
            'models' => array_map(fn (array $m): array => ['cost_usd' => round($m['cost_usd'], 6)] + $m, $this->models),
        ];
    }

    /**
     * What a generation cost: the Gemini calls this meter counted, plus what a
     * provider billed per character (Google Cloud voices), which has no tokens.
     *
     * @param  array<string, mixed>  $audio  a TTS result (provider, cost_estimate_usd)
     */
    public function totalWith(array $audio): float
    {
        $provider = (string) ($audio['provider'] ?? '');
        $perCharacter = str_starts_with($provider, 'google') ? (float) ($audio['cost_estimate_usd'] ?? 0) : 0.0;

        return round($this->cost + $perCharacter, 6);
    }

    /**
     * Guzzle middleware: counts each answered Gemini request on the open meters.
     */
    public static function middleware(): callable
    {
        return static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler) {
            $promise = $handler($request, $options);
            if (! self::listening() || ! self::isGemini($request)) {
                return $promise;
            }

            return $promise->then(static function ($response) use ($request) {
                if ($response instanceof ResponseInterface) {
                    self::fromHttp($request, $response);
                }

                return $response;
            });
        };
    }

    public static function fromHttp(RequestInterface $request, ResponseInterface $response): void
    {
        if (! self::listening() || $response->getStatusCode() !== 200) {
            return;
        }

        try {
            $usage = self::readUsage($response);
            if ($usage !== null) {
                self::record(self::modelOf($request, $usage['model']), $usage['input'], $usage['output'], $usage['cached']);
            }
        } catch (\Throwable $e) {
            // The voice is never held up by its bookkeeping.
            Log::warning('Gemini spend: a reply could not be counted.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * One call's tokens on every open meter.
     */
    public static function record(string $model, int $input, int $output, int $cached = 0, ?CarbonInterface $at = null): void
    {
        if (! self::listening()) {
            return;
        }
        $model = self::normalize($model);
        $cost = self::price($model, $input, $output, $cached, $at);
        foreach (self::$open as $meter) {
            $meter->add($model, $input, $output, $cost);
        }
    }

    /**
     * USD for one call's tokens: $input includes the $cached part of the prompt.
     */
    public static function price(string $model, int $input, int $output, int $cached = 0, ?CarbonInterface $at = null): float
    {
        if ((bool) config('analytics.gemini_tts.free_tier', false)) {
            return 0.0;
        }
        $rates = self::rates($model, $at ?? Carbon::now());
        $cached = min(max(0, $cached), max(0, $input));

        return (($input - $cached) * $rates['input'] + $cached * $rates['cached'] + max(0, $output) * $rates['output']) / 1_000_000;
    }

    /**
     * The model's list prices on the day of $at (Google's billing day, Pacific time).
     *
     * @return array{input: float, output: float, cached: float}
     */
    public static function rates(string $model, CarbonInterface $at): array
    {
        $prices = (array) config('analytics.gemini_prices', []);
        $model = self::normalize($model);
        $table = $prices[$model] ?? null;
        if (! is_array($table)) {
            // A speech model we have no price for is priced as today's speech model, any
            // other as the dearer text model, and the owner is told once.
            $fallback = str_contains($model, 'tts') ? GeminiTtsModel::LATEST : 'gemini-3.6-flash';
            if (! isset(self::$unpriced[$model])) {
                self::$unpriced[$model] = true;
                Log::warning('Gemini spend: no price for this model; priced as '.$fallback.'.', ['model' => $model]);
            }
            $table = (array) ($prices[$fallback] ?? []);
        }

        $day = Carbon::instance($at)->setTimezone('America/Los_Angeles')->format('Y-m-d');
        $rates = ['input' => 0.0, 'output' => 0.0];
        foreach ($table as $row) {
            if (($row['from'] ?? null) === null || (string) $row['from'] <= $day) {
                $rates = $row;
            }
        }

        $input = (float) ($rates['input'] ?? 0);

        return [
            'input' => $input,
            'output' => (float) ($rates['output'] ?? 0),
            'cached' => isset($rates['cached']) ? (float) $rates['cached'] : $input,
        ];
    }

    /** gemini-2.5-flash-preview-tts → gemini-2.5-flash-tts; models/x → x. */
    public static function normalize(string $model): string
    {
        $model = strtolower(trim($model));
        $model = preg_replace('#^models/#', '', $model) ?? $model;
        $model = str_replace('-preview', '', $model);

        return preg_replace('/-\d{2}-\d{2}$/', '', $model) ?? $model;
    }

    /**
     * Token counts from a reply's text: input (with any cached part), output
     * (with thinking), the cached part, and the model it names.
     *
     * @return array{input: int, output: int, cached: int, model: ?string}|null
     */
    public static function usageFrom(string $body): ?array
    {
        if (strlen($body) <= self::WHOLE_BODY_BYTES) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                return self::usageFromArray($json);
            }
        }

        // Audio replies are mostly base64, which has no quotes: the counts are read as text.
        $n = static fn (string $key): ?int => preg_match('/"'.$key.'"\s*:\s*"?(\d+)/', $body, $m) ? (int) $m[1] : null;
        $model = preg_match('/"(?:modelVersion|model)"\s*:\s*"(?:models\/)?([^"]+)"/', $body, $m) ? $m[1] : null;
        if ($n('promptTokenCount') !== null || $n('candidatesTokenCount') !== null) {
            return [
                'input' => (int) $n('promptTokenCount') + (int) $n('toolUsePromptTokenCount'),
                'output' => (int) $n('candidatesTokenCount') + (int) $n('thoughtsTokenCount'),
                'cached' => (int) $n('cachedContentTokenCount'),
                'model' => $model,
            ];
        }
        if ($n('total_input_tokens') !== null || $n('total_output_tokens') !== null) {
            return [
                'input' => (int) $n('total_input_tokens') + (int) $n('total_tool_use_tokens'),
                'output' => (int) $n('total_output_tokens') + (int) $n('total_thought_tokens'),
                'cached' => (int) $n('total_cached_tokens'),
                'model' => $model,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{input: int, output: int, cached: int, model: ?string}|null
     */
    public static function usageFromArray(array $json): ?array
    {
        $model = $json['modelVersion'] ?? $json['model'] ?? null;
        $model = is_string($model) ? preg_replace('#^models/#', '', $model) : null;

        $meta = $json['usageMetadata'] ?? $json['usage_metadata'] ?? null;
        if (is_array($meta)) {
            return [
                'input' => (int) ($meta['promptTokenCount'] ?? 0) + (int) ($meta['toolUsePromptTokenCount'] ?? 0),
                'output' => (int) ($meta['candidatesTokenCount'] ?? 0) + (int) ($meta['thoughtsTokenCount'] ?? 0),
                'cached' => (int) ($meta['cachedContentTokenCount'] ?? 0),
                'model' => $model,
            ];
        }

        $usage = $json['usage'] ?? null;
        if (is_array($usage) && (isset($usage['total_input_tokens']) || isset($usage['total_output_tokens']))) {
            return [
                'input' => (int) ($usage['total_input_tokens'] ?? 0) + (int) ($usage['total_tool_use_tokens'] ?? 0),
                'output' => (int) ($usage['total_output_tokens'] ?? 0) + (int) ($usage['total_thought_tokens'] ?? 0),
                'cached' => (int) ($usage['total_cached_tokens'] ?? 0),
                'model' => $model,
            ];
        }

        return null;
    }

    /**
     * @return array{input: int, output: int, cached: int, model: ?string}|null
     */
    private static function readUsage(ResponseInterface $response): ?array
    {
        $body = $response->getBody();
        if (! $body->isSeekable()) {
            return null;
        }
        $at = $body->tell();
        try {
            $size = $body->getSize();
            if ($size !== null && $size > self::WHOLE_BODY_BYTES) {
                // The counts come after the audio: the end of the reply is read first.
                $body->seek(max(0, $size - self::TAIL_BYTES));
                $usage = self::usageFrom($body->getContents());
                if ($usage !== null) {
                    return $usage;
                }
            }
            $body->rewind();

            return self::usageFrom($body->getContents());
        } finally {
            $body->seek($at);
        }
    }

    private static function isGemini(RequestInterface $request): bool
    {
        $host = strtolower($request->getUri()->getHost());
        $configured = strtolower((string) parse_url((string) config('services.gemini_tts.endpoint', ''), PHP_URL_HOST));

        return $host === 'generativelanguage.googleapis.com' || ($configured !== '' && $host === $configured);
    }

    /**
     * The model a request was for: in its address (…/models/{model}:generateContent),
     * else in its body (the Interactions API), else as the reply names it.
     */
    private static function modelOf(RequestInterface $request, ?string $fromReply): string
    {
        if (preg_match('#/models/([^/:]+):#', $request->getUri()->getPath(), $m)) {
            return $m[1];
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $at = $body->tell();
            try {
                $body->rewind();
                $head = $body->read(8192);
            } finally {
                $body->seek($at);
            }
            if (preg_match('/"model"\s*:\s*"(?:models\/)?([^"]+)"/', $head, $m)) {
                return $m[1];
            }
        }

        return $fromReply !== null && $fromReply !== '' ? $fromReply : GeminiTtsModel::LATEST;
    }

    private function add(string $model, int $input, int $output, float $cost): void
    {
        $this->calls++;
        $this->inputTokens += $input;
        $this->outputTokens += $output;
        $this->cost += $cost;
        $row = $this->models[$model] ?? ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
        $row['calls']++;
        $row['input_tokens'] += $input;
        $row['output_tokens'] += $output;
        $row['cost_usd'] += $cost;
        $this->models[$model] = $row;
    }
}
