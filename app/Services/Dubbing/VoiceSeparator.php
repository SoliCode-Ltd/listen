<?php

namespace App\Services\Dubbing;

use App\Models\SiteSetting;
use App\Support\AudioFormats;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Dub my video: takes the voices out of a video's sound and leaves its music
 * and effects, as dubbing studios do, so the new voices play over them with
 * nothing of the old ones underneath (VideoDubber mixes them). A speech
 * isolation program then tells the main, close speaker from the voices
 * further back (children reciting, a crowd, chanting): only the main speaker
 * is replaced, the others stay. Measured on a narrator over background
 * voices, music and effects: the narrator's trace 27 to 31 dB down, 52 to
 * 61% of the background voices kept (39 to 40% without it, the narrator
 * 29 to 34 dB down), the music and effects whole.
 *
 * An MDX-Net model (resources/dubbing/separate.py) runs on this server, on
 * its own Python 3 with onnxruntime and numpy unpacked from their wheels:
 * nothing is sent anywhere and nothing is paid per video. install() sets it
 * up (Video Dubbing → Settings, or `php artisan dubs:separator install`):
 * the wheels that fit this server's Python and the model, each checked
 * against its published checksum, then a test run. It takes about 1.4 GB of
 * memory while it runs, so the whole site runs one at a time, and only when
 * the server has the memory free (enoughMemory()); should the server still
 * run short, the system stops it first (it says so itself), never the site.
 * A dub that cannot wait for it, or a server without it, uses VideoDubber's
 * filters. The same install sets up a small speech-detection model
 * (phrases()): where someone speaks, phrase by phrase, for the dub's timing.
 */
class VoiceSeparator
{
    public const MODEL_URL = 'https://github.com/TRvlvr/model_repo/releases/download/all_public_uvr_models/UVR-MDX-NET-Inst_HQ_3.onnx';

    public const MODEL_SHA256 = '317554b07fe1ea5279a77f2b1520a41ea4b93432560c4ffd08792c30fddf9adc';

    /**
     * The speech-isolation program (DeepFilterNet's deep-filter, one file
     * with its model inside, for 64-bit Intel/AMD Linux): it tells the main,
     * close speaker from the voices further back, so the dub keeps those.
     */
    public const ISOLATOR_URL = 'https://github.com/Rikorose/DeepFilterNet/releases/download/v0.5.6/deep-filter-0.5.6-x86_64-unknown-linux-musl';

    public const ISOLATOR_SHA256 = '70775e251eee44c0f2451a1e833326cf8bcbbe304d3e7cd12851e6fce72ef7da';

    /**
     * The speech-detection model (Silero VAD, MIT licence), taken from its
     * wheel: it finds where someone speaks, phrase by phrase, so a dub's
     * lines are written for those moments and placed on them
     * (resources/dubbing/phrases.py, VideoDubber's speech map).
     */
    public const VAD_WHEEL_URL = 'https://files.pythonhosted.org/packages/84/ef/9099037ed6f180ea33220178df4107112c0ce2bf5fb4d6f6ab19db2844ed/silero_vad-6.2.3-py3-none-any.whl';

    public const VAD_WHEEL_SHA256 = '7b7f5436cfcb02fae583a05b512ea96467fd449fe54cb49a5e4f06c51a1e43b8';

    public const VAD_MEMBER = 'silero_vad/data/silero_vad.onnx';

    public const VAD_SHA256 = '1a153a22f4509e292a94e67d6f9b85e8deb25b4988682b7e174c65279d8788e3';

    /** The packages it needs, unpacked from their wheels. */
    public const PACKAGES = ['numpy', 'onnxruntime'];

    private const LOCK = 'video-dub-separator';

    /** A separation of a three-minute video takes about three minutes on two threads; this leaves room. */
    private const TIMEOUT = 900;

    private const INSTALL_STATUS = 'video-dubbing-separator-install';

    public static function dir(): string
    {
        return (string) config('listen.dubbing.separator.dir') ?: storage_path('app/separator');
    }

    public static function script(): string
    {
        return resource_path('dubbing/separate.py');
    }

    public static function model(): string
    {
        return self::dir().'/model.onnx';
    }

    public static function lib(): string
    {
        return self::dir().'/lib';
    }

    public static function isolator(): string
    {
        return self::dir().'/deep-filter';
    }

    /** Whether the speech-isolation program is set up (install() puts it there on servers it runs on). */
    public static function isolatorReady(): bool
    {
        return @is_file(self::isolator()) && @is_executable(self::isolator()) && AudioFormats::ffmpegPath() !== null;
    }

    public static function vad(): string
    {
        return self::dir().'/vad.onnx';
    }

    /** Whether the speech detection is set up (with the rest: it runs on the same Python and packages). */
    public static function vadReady(): bool
    {
        return self::installed() && @is_file(self::vad());
    }

    /**
     * Where someone speaks in $audio (raw float mono, 16 kHz), phrase by
     * phrase, in seconds (resources/dubbing/phrases.py): a second or two of
     * work for a minute of sound, on one thread, so it needs no turn. Null
     * when it is not set up or did not answer.
     *
     * @return array<int, array{0: float, 1: float}>|null
     */
    public static function phrases(string $audio): ?array
    {
        if (! self::vadReady()) {
            return null;
        }
        $process = new Process([(string) self::python(), '-s', resource_path('dubbing/phrases.py'), '--lib', self::lib(), '--vad', self::vad(), $audio],
            null, ['OMP_NUM_THREADS' => '1', 'PYTHONDONTWRITEBYTECODE' => '1']);
        $process->setTimeout(180)->run();
        $lines = preg_split('/\r?\n/', trim($process->getOutput())) ?: [];
        $data = $process->isSuccessful() ? json_decode((string) end($lines), true) : null;
        if (! is_array($data) || ! is_array($data['phrases'] ?? null)) {
            Log::warning('The speech detection did not answer.', ['error' => mb_substr(trim($process->getErrorOutput()), -300)]);

            return null;
        }
        $phrases = [];
        foreach ($data['phrases'] as $p) {
            if (is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null) && (float) $p[1] > (float) $p[0]) {
                $phrases[] = [(float) $p[0], (float) $p[1]];
            }
        }

        return $phrases;
    }

    /** This server's Python 3: the configured one, else the usual places. */
    public static function python(): ?string
    {
        $configured = trim((string) config('listen.dubbing.separator.python', ''));
        foreach ($configured !== '' ? [$configured] : ['/usr/bin/python3', '/usr/local/bin/python3', '/bin/python3', '/opt/python3/bin/python3'] as $path) {
            if (@is_file($path) && @is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Set up and tested on this server (install() writes ready.json last). */
    public static function installed(): bool
    {
        return self::python() !== null && is_file(self::dir().'/ready.json') && is_file(self::model()) && is_dir(self::lib());
    }

    /** The admin's switch (Video Dubbing → Settings): on unless switched off. */
    public static function enabled(): bool
    {
        return (string) SiteSetting::get('video_dubbing_separation', '1') !== '0';
    }

    public static function active(): bool
    {
        return self::enabled() && self::installed();
    }

    /** @return array{total_mb: int, free_mb: int}|null The server's memory, as the system counts it. */
    public static function memory(): ?array
    {
        $info = @file_get_contents('/proc/meminfo');
        if (! is_string($info) || ! preg_match('/^MemTotal:\s+(\d+)/m', $info, $t) || ! preg_match('/^MemAvailable:\s+(\d+)/m', $info, $a)) {
            return null;
        }

        return ['total_mb' => intdiv((int) $t[1], 1024), 'free_mb' => intdiv((int) $a[1], 1024)];
    }

    /**
     * Whether the server has the memory free for one separation, with room to
     * spare (listen.dubbing.separator.min_free_mb), from the system's own
     * count of what is available. Where it cannot be read, it goes ahead: the
     * system would stop it before anything else.
     */
    public static function enoughMemory(): bool
    {
        $memory = self::memory();

        return $memory === null || $memory['free_mb'] >= max(0, (int) config('listen.dubbing.separator.min_free_mb', 2048));
    }

    /** @return array<string, mixed>|null What install() set up: the versions and when. */
    public static function details(): ?array
    {
        $ready = self::dir().'/ready.json';

        return is_file($ready) ? json_decode((string) file_get_contents($ready), true) : null;
    }

    /** @return array{state: string, message: string, at: string}|null The last install from the settings page. */
    public static function installStatus(): ?array
    {
        $status = Cache::get(self::INSTALL_STATUS);

        return is_array($status) ? $status : null;
    }

    /**
     * Starts splitting $in (raw float stereo, 44.1 kHz) into $rest (its music
     * and effects) and $voices, and, with the isolation program, $further
     * (the voices further back than the main speaker), while the dub goes on
     * with its other steps. Null when it is not set up, another dub is using
     * it now, or the server has too little memory free.
     *
     * @return array{process: Process, lock: Lock, rest: string, voices: string, further: string, started: float}|null
     */
    public function start(string $in, string $rest, string $voices, string $further = ''): ?array
    {
        if (! self::active()) {
            return null;
        }
        $lock = Cache::lock(self::LOCK, self::TIMEOUT + 60);
        if (! $lock->get()) {
            return null;
        }
        if (! self::enoughMemory()) {
            $lock->release();
            Log::info('Voice separation skipped: the server has too little memory free just now.');

            return null;
        }
        try {
            $process = $this->process($in, $rest, $voices, $further);
            $process->start();
        } catch (\Throwable $e) {
            $lock->release();
            Log::warning('Voice separation could not start.', ['error' => $e->getMessage()]);

            return null;
        }

        return ['process' => $process, 'lock' => $lock, 'rest' => $rest, 'voices' => $voices, 'further' => $further, 'started' => microtime(true)];
    }

    /**
     * Waits for a separation start() began, telling $progress how far it is
     * ('separating', chunks done, chunks); the two tracks, or null when it
     * failed (the dub then uses the filters).
     *
     * @param  array{process: Process, lock: Lock, rest: string, voices: string, further: string, started: float}  $job
     * @param  (callable(string, int, int): void)|null  $progress
     * @return array{rest: string, voices: string, further: ?string}|null
     */
    public function finish(array $job, ?callable $progress = null): ?array
    {
        try {
            $process = $job['process'];
            while ($process->isRunning()) {
                if ($progress !== null && preg_match_all('/progress (\d+) (\d+)/', $process->getIncrementalOutput(), $m) > 0) {
                    $progress('separating', (int) end($m[1]), (int) end($m[2]));
                }
                usleep(500_000);
                $process->checkTimeout();
            }
            if (! $process->isSuccessful() || ! is_file($job['rest']) || filesize($job['rest']) === 0 || ! is_file($job['voices'])) {
                Log::warning('Voice separation failed.', ['error' => mb_substr(trim($process->getErrorOutput()), -400)]);

                return null;
            }

            if ($job['further'] !== '' && (! is_file($job['further']) || filesize($job['further']) === 0)) {
                Log::info('Voice separation: the voices further back could not be told apart; the dub goes on without them.',
                    ['error' => mb_substr(trim($process->getErrorOutput()), -300)]);
            }

            return ['rest' => $job['rest'], 'voices' => $job['voices'],
                'further' => $job['further'] !== '' && is_file($job['further']) && filesize($job['further']) > 0 ? $job['further'] : null];
        } catch (\Throwable $e) {
            Log::warning('Voice separation failed.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            $job['lock']->release();
        }
    }

    /** Stops a separation that is no longer needed (the member chose to replace all the sound). */
    public function cancel(array $job): void
    {
        try {
            $job['process']->stop(1);
        } finally {
            $job['lock']->release();
        }
    }

    /**
     * Separates now, waiting up to $wait seconds for another dub's to finish:
     * for a dub whose start() found it busy.
     *
     * @param  (callable(string, int, int): void)|null  $progress
     * @return array{rest: string, voices: string, further: ?string}|null
     */
    public function run(string $in, string $rest, string $voices, int $wait, ?callable $progress = null, string $further = ''): ?array
    {
        if (! self::active()) {
            return null;
        }
        $lock = Cache::lock(self::LOCK, self::TIMEOUT + 60);
        try {
            $lock->block($wait);
        } catch (\Throwable) {
            return null;
        }
        if (! self::enoughMemory()) {
            $lock->release();
            Log::info('Voice separation skipped: the server has too little memory free just now.');

            return null;
        }
        try {
            $process = $this->process($in, $rest, $voices, $further);
            $process->start();
        } catch (\Throwable $e) {
            $lock->release();
            Log::warning('Voice separation could not start.', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->finish(['process' => $process, 'lock' => $lock, 'rest' => $rest, 'voices' => $voices, 'further' => $further, 'started' => microtime(true)], $progress);
    }

    /**
     * Processor threads one separation uses: the configured number, else all
     * but one of the server's cores, four at most (it runs one at a time).
     */
    public static function threads(): int
    {
        $configured = (int) config('listen.dubbing.separator.threads', 0);
        if ($configured > 0) {
            return $configured;
        }
        $info = @file_get_contents('/proc/cpuinfo');
        $cores = is_string($info) ? preg_match_all('/^processor\s*:/m', $info) : 2;

        return max(1, min(4, $cores - 1));
    }

    private function process(string $in, string $rest, string $voices, string $further = ''): Process
    {
        $threads = self::threads();
        $isolate = $further !== '' && self::isolatorReady();
        $command = array_merge([(string) self::python(), '-s', self::script(), '--lib', self::lib(), '--model', self::model(), '--threads', (string) $threads],
            $isolate ? ['--isolator', self::isolator(), '--ffmpeg', (string) AudioFormats::ffmpegPath()] : [],
            [$in, $rest, $voices], $isolate ? [$further] : []);
        $process = new Process($command, null, ['OMP_NUM_THREADS' => (string) $threads, 'PYTHONDONTWRITEBYTECODE' => '1']);

        return $process->setTimeout(self::TIMEOUT);
    }

    /**
     * Sets it up on this server: the wheels of numpy and onnxruntime that fit
     * its Python and processor, unpacked, the model, each checked against its
     * published checksum, then a test run of the model. What was there stays
     * until the new one passes. $say hears each step.
     *
     * @param  callable(string): void  $say
     */
    public function install(callable $say): void
    {
        $python = self::python() ?? throw new \RuntimeException('Python 3 was not found on this server (looked in /usr/bin, /usr/local/bin and /bin). Ask the host to install python3, or set LISTEN_DUB_SEPARATOR_PYTHON to its path.');
        $info = json_decode($this->runPython($python, ['-c', 'import sys,platform,json;print(json.dumps({"version":"%d%d"%sys.version_info[:2],"machine":platform.machine(),"libc":platform.libc_ver()[1]}))']), true);
        if (! is_array($info) || ! isset($info['version'], $info['machine'])) {
            throw new \RuntimeException('This server\'s Python did not say what it is.');
        }
        $say(sprintf('Python %s.%s on %s (C library %s).', $info['version'][0], substr($info['version'], 1), $info['machine'], $info['libc'] ?: 'unknown'));

        $dir = self::dir();
        @mkdir($dir, 0755, true);
        $fresh = $dir.'/lib.new';
        $this->removeTree($fresh);
        @mkdir($fresh, 0755, true);
        $versions = [];
        foreach (self::PACKAGES as $package) {
            $wheel = $this->wheel($package, (string) $info['version'], (string) $info['machine'], (string) ($info['libc'] ?? ''));
            $say("Downloading {$wheel['filename']}…");
            $file = $dir.'/'.$wheel['filename'];
            $this->download($wheel['url'], $file, $wheel['sha256']);
            $this->runPython($python, ['-c', 'import sys,zipfile;zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])', $file, $fresh]);
            @unlink($file);
            $versions[$package] = $wheel['version'];
        }

        if (! is_file(self::model()) || hash_file('sha256', self::model()) !== self::MODEL_SHA256) {
            $say('Downloading the model (67 MB)…');
            $this->download(self::MODEL_URL, $dir.'/model.onnx.part', self::MODEL_SHA256);
            rename($dir.'/model.onnx.part', self::model());
        }

        // The speech isolation, where it runs (64-bit Intel/AMD): without it the dubs still work, without keeping the voices further back.
        $isolate = $info['machine'] === 'x86_64' && AudioFormats::ffmpegPath() !== null;
        if ($isolate && (! is_file(self::isolator()) || hash_file('sha256', self::isolator()) !== self::ISOLATOR_SHA256)) {
            $say('Downloading the speech isolation (36 MB)…');
            $this->download(self::ISOLATOR_URL, self::isolator().'.part', self::ISOLATOR_SHA256);
            rename(self::isolator().'.part', self::isolator());
        }
        if ($isolate) {
            @chmod(self::isolator(), 0755);
        }

        // The speech detection: the one model file inside its wheel, checked against its own checksum too.
        if (! is_file(self::vad()) || hash_file('sha256', self::vad()) !== self::VAD_SHA256) {
            $say('Downloading the speech detection (11 MB)…');
            $wheel = $dir.'/vad.whl';
            $this->download(self::VAD_WHEEL_URL, $wheel, self::VAD_WHEEL_SHA256);
            try {
                $this->runPython($python, ['-c', 'import sys,zipfile;open(sys.argv[3],"wb").write(zipfile.ZipFile(sys.argv[1]).read(sys.argv[2]))', $wheel, self::VAD_MEMBER, self::vad().'.part']);
            } finally {
                @unlink($wheel);
            }
            if (hash_file('sha256', self::vad().'.part') !== self::VAD_SHA256) {
                @unlink(self::vad().'.part');
                throw new \RuntimeException('The speech detection did not match its checksum.');
            }
            rename(self::vad().'.part', self::vad());
        }

        $say('Testing it…');
        $check = $this->runPython($python, array_merge(['-s', self::script(), '--lib', $fresh, '--model', self::model(), '--threads', '1'],
            $isolate ? ['--isolator', self::isolator(), '--ffmpeg', (string) AudioFormats::ffmpegPath()] : [], ['--check']), 300);
        if (trim($check) !== 'ok') {
            throw new \RuntimeException('The test run did not pass: '.mb_substr($check, -300));
        }
        $check = $this->runPython($python, ['-s', resource_path('dubbing/phrases.py'), '--lib', $fresh, '--vad', self::vad(), '--check'], 120);
        if (trim($check) !== 'ok') {
            throw new \RuntimeException('The speech detection test did not pass: '.mb_substr($check, -300));
        }

        $this->removeTree(self::lib());
        rename($fresh, self::lib());
        file_put_contents($dir.'/ready.json', json_encode(['python' => $python, 'python_version' => $info['version'], 'machine' => $info['machine'],
            'packages' => $versions, 'model' => basename(self::MODEL_URL), 'isolation' => $isolate, 'speech_detection' => true,
            'installed_at' => now()->toIso8601String()], JSON_PRETTY_PRINT));
        $say($isolate
            ? 'Ready: dubs now take the main speaker out and keep the music, effects and the voices further back.'
            : 'Ready: dubs now take the old voices out before the new ones go in (the voices further back are not kept on this server).');
    }

    /** Takes it off this server (the dubs use the filters again). */
    public function remove(): void
    {
        $this->removeTree(self::dir());
    }

    /** Runs install() after the reply, for the settings page, keeping its progress for the page to show. */
    public static function installAfterResponse(): void
    {
        Cache::put(self::INSTALL_STATUS, ['state' => 'running', 'message' => 'Starting…', 'at' => now()->toIso8601String()], now()->addDay());
        dispatch(function (): void {
            ignore_user_abort(true);
            if (function_exists('set_time_limit')) {
                @set_time_limit(1800);
            }
            $say = fn (string $message) => Cache::put(self::INSTALL_STATUS, ['state' => 'running', 'message' => $message, 'at' => now()->toIso8601String()], now()->addDay());
            try {
                app(self::class)->install($say);
                Cache::put(self::INSTALL_STATUS, ['state' => 'done', 'message' => 'Installed and tested.', 'at' => now()->toIso8601String()], now()->addDay());
            } catch (\Throwable $e) {
                Log::warning('Voice separation could not be installed.', ['error' => $e->getMessage()]);
                Cache::put(self::INSTALL_STATUS, ['state' => 'failed', 'message' => $e->getMessage(), 'at' => now()->toIso8601String()], now()->addDay());
            }
        })->afterResponse();
    }

    /**
     * The newest release of $package with a wheel for this Python (cpXY),
     * processor and C library (manylinux), from the package index.
     *
     * @return array{filename: string, url: string, sha256: string, version: string}
     */
    private function wheel(string $package, string $python, string $machine, string $libc): array
    {
        $response = Http::timeout(60)->acceptJson()->get("https://pypi.org/pypi/{$package}/json");
        if (! $response->ok()) {
            throw new \RuntimeException("The package index did not answer for {$package} ({$response->status()}).");
        }
        $releases = (array) $response->json('releases', []);
        $versions = array_filter(array_keys($releases), fn ($v): bool => preg_match('/^\d+(\.\d+)*$/', (string) $v) === 1);
        usort($versions, fn ($a, $b): int => version_compare((string) $b, (string) $a));
        $glibc = array_map('intval', explode('.', $libc !== '' ? $libc : '2.17'));
        foreach ($versions as $version) {
            foreach ((array) $releases[$version] as $file) {
                $name = (string) ($file['filename'] ?? '');
                if (! str_ends_with($name, '.whl') || ! str_contains($name, "-cp{$python}-cp{$python}-") || ($file['yanked'] ?? false)) {
                    continue;
                }
                if (self::fitsPlatform($name, $machine, $glibc)) {
                    return ['filename' => $name, 'url' => (string) $file['url'], 'sha256' => (string) ($file['digests']['sha256'] ?? ''), 'version' => (string) $version];
                }
            }
        }
        throw new \RuntimeException("No {$package} build fits this server (Python {$python}, {$machine}, C library {$libc}).");
    }

    /**
     * Whether a wheel's platform tags fit: a manylinux tag for this processor
     * whose C library is no newer than the server's.
     *
     * @param  array<int, int>  $glibc
     */
    public static function fitsPlatform(string $filename, string $machine, array $glibc): bool
    {
        $tags = explode('.', preg_replace('/^.*-([^-]+)\.whl$/', '$1', $filename));
        $legacy = ['manylinux1' => [2, 5], 'manylinux2010' => [2, 12], 'manylinux2014' => [2, 17]];
        foreach ($tags as $tag) {
            if (preg_match('/^manylinux_(\d+)_(\d+)_(.+)$/', $tag, $m)) {
                $needs = [(int) $m[1], (int) $m[2]];
                $arch = $m[3];
            } elseif (preg_match('/^(manylinux1|manylinux2010|manylinux2014)_(.+)$/', $tag, $m)) {
                $needs = $legacy[$m[1]];
                $arch = $m[2];
            } else {
                continue;
            }
            if ($arch === $machine && ($needs[0] < ($glibc[0] ?? 2) || ($needs[0] === ($glibc[0] ?? 2) && $needs[1] <= ($glibc[1] ?? 17)))) {
                return true;
            }
        }

        return false;
    }

    private function download(string $url, string $path, string $sha256): void
    {
        // Into a file this method closes itself: one left open cannot be run straight after ("text file busy").
        $handle = fopen($path, 'w+b');
        try {
            $response = Http::timeout(900)->withOptions(['sink' => $handle])->get($url);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        if (! $response->ok()) {
            @unlink($path);
            throw new \RuntimeException('A download failed ('.$response->status().'): '.basename($url));
        }
        if ($sha256 !== '' && hash_file('sha256', $path) !== $sha256) {
            @unlink($path);
            throw new \RuntimeException('A download did not match its checksum: '.basename($url));
        }
    }

    /** @param  array<int, string>  $args */
    private function runPython(string $python, array $args, int $timeout = 120): string
    {
        $process = new Process(array_merge([$python], $args), null, ['PYTHONDONTWRITEBYTECODE' => '1']);
        $process->setTimeout($timeout)->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Python failed: '.mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), -300));
        }

        return trim($process->getOutput());
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
