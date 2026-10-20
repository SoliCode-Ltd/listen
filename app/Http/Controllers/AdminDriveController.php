<?php

namespace App\Http\Controllers;

use App\Filament\Pages\VideoDubbingSettings;
use App\Services\Dubbing\DriveArchive;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Connects the site's Google Drive for dubbed videos (App\Services\Dubbing\DriveArchive),
 * from Video Dubbing → Settings: off to Google's page, and back here.
 */
class AdminDriveController extends Controller
{
    private const STATE = 'video_dubbing_drive_state';

    public function connect(Request $request, DriveArchive $drive): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        if (! DriveArchive::configured()) {
            return $this->back('Add the Google client ID and secret first (below), then connect.', false);
        }
        $state = Str::random(40);
        $request->session()->put(self::STATE, $state);

        return redirect()->away($drive->authUrl($state));
    }

    public function callback(Request $request, DriveArchive $drive): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        $expected = $request->session()->pull(self::STATE);
        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->back('Google Drive was not connected: the Google page was open too long or twice. Press Connect again.', false);
        }
        if ($request->filled('error')) {
            return $this->back($request->query('error') === 'access_denied'
                ? 'Google Drive was not connected: access was not allowed on the Google page.'
                : 'Google Drive was not connected ('.Str::limit((string) $request->query('error'), 80).').', false);
        }
        try {
            $drive->connect((string) $request->query('code'));
        } catch (\Throwable $e) {
            Log::warning('Google Drive could not be connected for dubbed videos.', ['error' => $e->getMessage()]);

            return $this->back('Google Drive was not connected: '.$e->getMessage(), false);
        }

        return $this->back('Google Drive connected: videos now go to the Listen Studio folder of '.(DriveArchive::account() ?: 'that account').'.', true);
    }

    private function back(string $message, bool $ok): RedirectResponse
    {
        $notification = Notification::make()->title($message);
        ($ok ? $notification->success() : $notification->danger()->persistent())->send();

        return redirect()->to(VideoDubbingSettings::getUrl());
    }
}
