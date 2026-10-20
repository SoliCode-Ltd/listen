<?php

namespace App\Services\Dubbing;

use App\Models\SiteSetting;
use App\Models\User;
use App\Models\VideoDub;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dubbed videos, kept in the site's Google Drive instead of on the server:
 * Listen Studio / Paid or Free / a folder per member, each video named by
 * its date, its file and its dialect. A paid dub goes there as soon as it
 * is ready, a free one once its member's day is over (dubs:prune). Members
 * watch and download theirs from the site while their time lasts
 * (VideoDubbing::availableUntil()), streamed from Drive; the admin can
 * watch every one of them, and the files are never deleted by the site
 * unless the admin removes a dub. Switched off (Video Dubbing → Settings),
 * the videos stay on the server.
 *
 * The admin connects a Google account once (Video Dubbing → Settings), with
 * the site's own Google sign-in client (or one set there). The site asks only for drive.file:
 * it sees the files and folders it made itself, never the rest of the Drive.
 * The refresh token is kept encrypted in the site settings.
 */
class DriveArchive
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public const FOLDER_NAME = 'Listen Studio';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Uploaded in pieces of 8 MB (a multiple of 256 KB, as Drive asks). */
    public static int $chunkBytes = 8 * 1024 * 1024;

    private const GROUP = 'video_dubbing';

    /** The Google client the connection uses: the one in Video Dubbing → Settings, else the site's Google sign-in client. */
    public static function clientId(): string
    {
        return self::setting('video_dubbing_drive_client_id') ?? (string) config('services.google.client_id', '');
    }

    public static function clientSecret(): string
    {
        $stored = self::setting('video_dubbing_drive_client_secret');
        $secret = $stored === null ? null : rescue(fn () => Crypt::decryptString($stored), null, false);

        return $secret ?? (string) config('services.google.client_secret', '');
    }

    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function connected(): bool
    {
        return self::refreshToken() !== null && self::folderId() !== null;
    }

    /** The admin's switch: keep videos in Google Drive (on), or on the server. */
    public static function enabled(): bool
    {
        return (string) SiteSetting::get('video_dubbing_drive_enabled', '1') !== '0';
    }

    /** Whether new videos go to Google Drive now. */
    public static function active(): bool
    {
        return self::enabled() && self::connected();
    }

    /** The Google account the videos go to. */
    public static function account(): ?string
    {
        return self::setting('video_dubbing_drive_account');
    }

    /** The site's Listen Studio folder in that Drive. */
    public static function folderId(): ?string
    {
        return self::setting('video_dubbing_drive_folder');
    }

    public static function folderUrl(): ?string
    {
        return self::folderId() ? 'https://drive.google.com/drive/folders/'.self::folderId() : null;
    }

    public static function fileUrl(string $fileId): string
    {
        return 'https://drive.google.com/file/d/'.$fileId.'/view';
    }

    /** Where Google sends the admin back; it must be listed on the Google sign-in client. */
    public static function redirectUrl(): string
    {
        return route('admin.drive.callback');
    }

    public function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUrl(),
            'response_type' => 'code',
            'scope' => 'openid email '.self::SCOPE,
            'access_type' => 'offline',
            // Always asked again, so Google always hands back a refresh token.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Finishes connecting: the code for tokens, the account's address, and
     * the Listen Studio folder found (one the site made before) or made.
     */
    public function connect(string $code): void
    {
        $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUrl(),
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->ok() || ! filled($response->json('refresh_token'))) {
            throw new \RuntimeException('Google did not give access ('.$response->status().' '.mb_substr((string) $response->json('error_description', $response->json('error', '')), 0, 120).').');
        }
        $scopes = explode(' ', (string) $response->json('scope'));
        if (! in_array(self::SCOPE, $scopes, true)) {
            throw new \RuntimeException('Google Drive access was not ticked on the Google page. Connect again and allow it.');
        }

        SiteSetting::set('video_dubbing_drive_token', Crypt::encryptString((string) $response->json('refresh_token')), self::GROUP);
        Cache::put('video-dubbing-drive-access', (string) $response->json('access_token'), now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 120)));
        SiteSetting::set('video_dubbing_drive_account', self::emailFrom((string) $response->json('id_token')), self::GROUP);
        SiteSetting::set('video_dubbing_drive_folder', $this->folder(self::FOLDER_NAME, 'root'), self::GROUP);
        SiteSetting::flushGroupCache(self::GROUP);
    }

    /** Stops using the Drive: Google forgets the site's access; the videos already there stay. */
    public function disconnect(): void
    {
        $token = self::refreshToken();
        if ($token !== null) {
            rescue(fn () => Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $token]), null, false);
        }
        foreach (['video_dubbing_drive_token', 'video_dubbing_drive_account', 'video_dubbing_drive_folder'] as $key) {
            SiteSetting::set($key, '', self::GROUP);
        }
        SiteSetting::flushGroupCache(self::GROUP);
        Cache::forget('video-dubbing-drive-access');
    }

    /**
     * Puts a dub's clean video in its member's folder (under Paid or Free),
     * then deletes it, and the marked copy, from the server. Once only;
     * false when Drive is off or there is nothing to send. Throws when Drive
     * refuses: the server copy stays and dubs:prune tries again.
     */
    public function archive(VideoDub $dub): bool
    {
        if (! self::active() || $dub->drive_file_id || $dub->status !== VideoDub::READY || ! $dub->has_clean || $dub->files_deleted_at) {
            return false;
        }
        $disk = Storage::disk('local');
        $path = $dub->path('dub.mp4');
        if (! $disk->exists($path)) {
            return false;
        }

        $user = $dub->user;
        // A free dub its member paid for since goes with the paid ones.
        $kind = $this->folder(is_array($dub->reservation) ? 'Paid' : 'Free', (string) self::folderId());
        $folder = $this->folder(self::memberFolderName($user), $kind);
        $fileId = $this->upload($disk->path($path), self::fileName($dub), $folder, 'Listen dub #'.$dub->id.' for '.($user?->email ?? 'a member'));

        $dub->forceFill(['drive_file_id' => $fileId, 'archived_at' => now()])->save();
        $disk->deleteDirectory((string) $dub->folder);

        return true;
    }

    /**
     * A video from the Drive, streamed through the site (the member never
     * needs access to the Drive itself). Passes the browser's Range on, so
     * the player can seek.
     */
    public function stream(string $fileId, ?string $range, string $filename, bool $download): StreamedResponse
    {
        try {
            $token = $this->accessToken();
        } catch (\RuntimeException $e) {
            // Drive was disconnected since: the video cannot be reached for now.
            Log::warning('A dubbed video is in Google Drive, which is not connected.', ['file' => $fileId, 'error' => $e->getMessage()]);
            abort(404);
        }
        $response = Http::withToken($token)
            ->withHeaders(array_filter(['Range' => $range]))
            ->withOptions(['stream' => true])
            ->timeout(120)
            ->get(self::API.'/files/'.rawurlencode($fileId), ['alt' => 'media']);
        if (! in_array($response->status(), [200, 206], true)) {
            Log::warning('A dubbed video could not be read from Google Drive.', ['file' => $fileId, 'status' => $response->status()]);
            abort(404);
        }
        $body = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(256 * 1024);
                flush();
            }
        }, $response->status(), array_filter([
            'Content-Type' => 'video/mp4',
            'Content-Length' => $response->header('Content-Length') ?: null,
            'Content-Range' => $response->header('Content-Range') ?: null,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    public function delete(string $fileId): void
    {
        Http::withToken($this->accessToken())->timeout(30)->delete(self::API.'/files/'.rawurlencode($fileId));
    }

    /** A member's folder: their name and their number, so two members of one name never share one. */
    public static function memberFolderName(?User $user): string
    {
        $name = trim((string) ($user?->name ?: strstr((string) $user?->email, '@', true)));

        return mb_substr($name !== '' ? $name : 'Member', 0, 60).' · #'.($user?->id ?? 0);
    }

    /** "2026-10-20 18.31 · holiday clip · Saudi (Najdi).mp4" */
    public static function fileName(VideoDub $dub): string
    {
        $original = pathinfo((string) $dub->original_name, PATHINFO_FILENAME) ?: 'video';
        $dialect = \App\Support\VoiceFlag::label((string) $dub->dialect, false);
        $name = $dub->created_at?->format('Y-m-d H.i').' · '.$original.' · '.$dialect;

        return mb_substr(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $name), 0, 180).'.mp4';
    }

    /**
     * The folder of this name in its parent, made when missing (only ones the
     * site made are seen). Looked up each time, so a folder deleted in Drive
     * by hand is simply made again.
     */
    private function folder(string $name, string $parent): string
    {
        $query = sprintf("name = '%s' and mimeType = 'application/vnd.google-apps.folder' and '%s' in parents and trashed = false",
            str_replace(['\\', "'"], ['\\\\', "\\'"], $name), $parent);
        $found = Http::withToken($this->accessToken())->timeout(30)->get(self::API.'/files', ['q' => $query, 'fields' => 'files(id)', 'pageSize' => 1]);
        $id = $found->ok() ? $found->json('files.0.id') : null;
        if (! $id) {
            $made = Http::withToken($this->accessToken())->timeout(30)->post(self::API.'/files?fields=id', [
                'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent],
            ]);
            if (! $made->ok()) {
                throw new \RuntimeException('A Google Drive folder could not be made ('.$made->status().').');
            }
            $id = (string) $made->json('id');
        }

        return $id;
    }

    /** A resumable upload in pieces, so a large video never has to sit in memory. */
    private function upload(string $path, string $name, string $folder, string $description): string
    {
        $size = (int) filesize($path);
        $start = Http::withToken($this->accessToken())
            ->withHeaders(['X-Upload-Content-Type' => 'video/mp4', 'X-Upload-Content-Length' => (string) $size])
            ->timeout(30)
            ->post(self::UPLOAD.'?uploadType=resumable&fields=id', ['name' => $name, 'parents' => [$folder], 'mimeType' => 'video/mp4', 'description' => $description]);
        $session = (string) $start->header('Location');
        if (! $start->ok() || $session === '') {
            throw new \RuntimeException('Google Drive did not start the upload ('.$start->status().').');
        }

        $handle = fopen($path, 'rb');
        try {
            $offset = 0;
            $stalled = 0;
            while (true) {
                $chunk = (string) fread($handle, self::$chunkBytes);
                $end = $offset + strlen($chunk) - 1;
                $put = Http::withToken($this->accessToken())
                    ->withHeaders(['Content-Range' => $size === 0 ? 'bytes */0' : "bytes {$offset}-{$end}/{$size}"])
                    ->withBody($chunk, 'video/mp4')
                    ->timeout(300)
                    ->put($session);
                if (in_array($put->status(), [200, 201], true) && filled($put->json('id'))) {
                    return (string) $put->json('id');
                }
                if ($put->status() !== 308) {
                    throw new \RuntimeException('Google Drive stopped the upload ('.$put->status().').');
                }
                // Where Drive says it has got to (no Range: nothing yet).
                $reached = preg_match('/bytes=0-(\d+)/', (string) $put->header('Range'), $m) ? (int) $m[1] + 1 : 0;
                $stalled = $reached > $offset ? 0 : $stalled + 1;
                if ($stalled >= 3) {
                    throw new \RuntimeException('Google Drive is not taking the upload.');
                }
                $offset = $reached;
                fseek($handle, $offset);
            }
        } finally {
            fclose($handle);
        }
    }

    /** A short-lived access token from the refresh token, kept for most of its hour. */
    private function accessToken(): string
    {
        $cached = Cache::get('video-dubbing-drive-access');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $refresh = self::refreshToken() ?? throw new \RuntimeException('Google Drive is not connected.');
        $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->ok() || ! filled($response->json('access_token'))) {
            throw new \RuntimeException('Google Drive refused the saved access ('.$response->status().'): connect it again.');
        }
        Cache::put('video-dubbing-drive-access', (string) $response->json('access_token'), now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 120)));

        return (string) $response->json('access_token');
    }

    private static function refreshToken(): ?string
    {
        $stored = self::setting('video_dubbing_drive_token');

        return $stored === null ? null : rescue(fn () => Crypt::decryptString($stored), null, false);
    }

    private static function setting(string $key): ?string
    {
        $value = trim((string) SiteSetting::get($key, ''));

        return $value === '' ? null : $value;
    }

    /** The address in Google's signed answer (it came straight from Google, over TLS). */
    private static function emailFrom(string $idToken): string
    {
        $parts = explode('.', $idToken);
        $claims = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);

        return (string) ($claims['email'] ?? '');
    }
}
