<?php

namespace App\Services\Push;

use App\Models\AppDevice;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\TextItem;
use App\Models\VideoDub;
use App\Support\GenerationSource;
use Illuminate\Support\Facades\Log;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * "Your voice is ready", on the member's phone: for work that finishes after
 * the page's reply (a long text, a video dub, a cloned voice), sent to every
 * phone the member is signed in on with the Listen app, in that phone's
 * language. A member without the app costs one small query; nothing is sent
 * when messaging is not set up (FcmSender).
 *
 * Nothing here may fail the work it announces: every entry point swallows
 * its own errors.
 */
class AppPush
{
    public function __construct(private FcmSender $sender) {}

    /** A studio voice that took long enough for the member to have looked away. */
    public function voiceReady(AudioGeneration $generation): void
    {
        $this->quietly(function () use ($generation): void {
            if ($generation->status !== 'done' || ! $generation->created_at) {
                return;
            }
            if ($generation->created_at->diffInSeconds(now()) < (int) config('services.fcm.voice_min_seconds', 20)) {
                return;
            }
            // The extension, the API and AI apps have their own ways of saying it is ready.
            $source = $generation->text_item_id ? TextItem::query()->whereKey($generation->text_item_id)->value('source_type') : null;
            if ($source !== null && ! GenerationSource::isStudio($source)) {
                return;
            }

            $this->notify((int) $generation->user_id, 'voice_ready', 'dashboard', 'voice-'.$generation->id);
        });
    }

    public function dubReady(VideoDub $dub): void
    {
        $this->quietly(fn () => $this->notify((int) $dub->user_id, 'dub_ready', 'dub.page', 'dub-'.$dub->id));
    }

    public function dubFailed(VideoDub $dub): void
    {
        $this->quietly(fn () => $this->notify((int) $dub->user_id, 'dub_failed', 'dub.page', 'dub-'.$dub->id));
    }

    public function clonedVoiceReady(ClonedVoice $voice): void
    {
        $this->quietly(fn () => $this->notify((int) $voice->user_id, 'clone_ready', 'dashboard', 'clone-'.$voice->id, '#voiceCloningSection'));
    }

    /**
     * @param  string  $message  the key under lang app.push
     * @param  string  $route  the page a tap opens
     */
    private function notify(int $userId, string $message, string $route, string $tag, string $fragment = ''): void
    {
        if ($userId <= 0 || ! $this->sender->configured()) {
            return;
        }

        $devices = AppDevice::query()->where('user_id', $userId)->latest('last_seen_at')->limit(AppDevice::PER_MEMBER)->get();
        foreach ($devices as $device) {
            $locale = $device->locale === 'en' ? 'en' : 'ar';
            $url = LaravelLocalization::getLocalizedURL($locale, route($route, [], true), [], true);

            $result = $this->sender->send(
                (string) $device->token,
                (string) trans("app.push.{$message}_title", [], $locale),
                (string) trans("app.push.{$message}_body", [], $locale),
                ['url' => $url.$fragment, 'kind' => $message, 'tag' => $tag],
            );

            if ($result === FcmSender::GONE) {
                $device->delete();
            }
        }
    }

    private function quietly(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::warning('App notification skipped.', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        }
    }
}
