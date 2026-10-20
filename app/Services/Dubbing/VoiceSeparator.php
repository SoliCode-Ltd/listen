<?php

namespace App\Services\Dubbing;

use App\Models\SiteSetting;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Dub my video: takes the voices out of a video's sound and leaves its music
 * and effects, as dubbing studios do, so the new voices play over them with
 * nothing of the old ones underneath (VideoDubber mixes them).
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
 * filters.
 */
class VoiceSeparator
{
    public const MODEL_URL = 'https://github.com/TRvlvr/model_repo/releases/download/all_public_uvr_models/UVR-MDX-NET-Inst_HQ_3.onnx';

    public const MODEL_SHA256 = '317554b07fe1ea5279a77f2b1520a41ea4b93432560c4ffd08792c30fddf9adc';

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
     * Starts taking the voices out of $in (raw float stereo, 44.1 kHz) into
     * $out while the dub goes on with its other steps. Null when it is not
     * set up, or another dub is using it now.
     *
     * @return array{process: Process, lock: Lock, out: string, started: float}|null
     */
    public function start(string $in, string $out): ?array
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
            $process = $this->process($in, $out);
            $process->start();
        } catch (\Throwable $e) {
            $lock->release();
            Log::warning('Voice separation could not start.', ['error' => $e->getMessage()]);

            return null;
        }

        return ['process' => $process, 'lock' => $lock, 'out' => $out, 'started' => microtime(true)];
    }

    /**
     * Waits for a separation start() began; the sound without voices, or null
     * when it failed (the dub then uses the filters).
     *
     * @param  array{process: Process, lock: Lock, out: string, started: float}  $job
     */
    public function finish(array $job): ?string
    {
        try {
            $job['process']->wait();
            if (! $job['process']->isSuccessful() || ! is_file($job['out']) || filesize($job['out']) === 0) {
                Log::warning('Voice separation failed.', ['error' => mb_substr(trim($job['process']->getErrorOutput()), -400)]);

                return null;
            }

            return $job['out'];
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
     */
    public function run(string $in, string $out, int $wait): ?string
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
            $process = $this->process($in, $out);
            $process->start();
        } catch (\Throwable $e) {
            $lock->release();
            Log::warning('Voice separation could not start.', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->finish(['process' => $process, 'lock' => $lock, 'out' => $out, 'started' => microtime(true)]);
    }

    private function process(string $in, string $out): Process
    {
        $threads = max(1, (int) config('listen.dubbing.separator.threads', 2));
        $process = new Process([(string) self::python(), '-s', self::script(), '--lib', self::lib(), '--model', self::model(), '--threads', (string) $threads, $in, $out],
            null, ['OMP_NUM_THREADS' => (string) $threads, 'PYTHONDONTWRITEBYTECODE' => '1']);

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

        $say('Testing it…');
        $check = $this->runPython($python, ['-s', self::script(), '--lib', $fresh, '--model', self::model(), '--threads', '1', '--check'], 300);
        if (trim($check) !== 'ok') {
            throw new \RuntimeException('The test run did not pass: '.mb_substr($check, -300));
        }

        $this->removeTree(self::lib());
        rename($fresh, self::lib());
        file_put_contents($dir.'/ready.json', json_encode(['python' => $python, 'python_version' => $info['version'], 'machine' => $info['machine'],
            'packages' => $versions, 'model' => basename(self::MODEL_URL), 'installed_at' => now()->toIso8601String()], JSON_PRETTY_PRINT));
        $say('Ready: dubs now take the old voices out before the new ones go in.');
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
        $response = Http::timeout(900)->withOptions(['sink' => $path])->get($url);
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
