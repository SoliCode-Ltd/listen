package studio.listenai.app;

import android.app.Notification;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.ServiceInfo;
import android.media.AudioManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.PowerManager;
import android.os.SystemClock;
import android.support.v4.media.MediaMetadataCompat;
import android.support.v4.media.session.MediaSessionCompat;
import android.support.v4.media.session.PlaybackStateCompat;
import androidx.annotation.Nullable;
import androidx.core.app.NotificationCompat;
import androidx.core.app.ServiceCompat;
import androidx.core.content.ContextCompat;

/**
 * The native side of playback. The sound itself is played by the page (an audio element in
 * the WebView); this service only:
 *   - shows the now-playing notification and lock-screen controls (a MediaSession),
 *   - receives play / pause / seek / skip from the notification, the lock screen, headsets
 *     and Bluetooth, and forwards them to the page as "mediaAction" events,
 *   - keeps the process in the foreground and the CPU awake WHILE playing, so the WebView
 *     keeps playing with the screen off,
 *   - tells the page to pause when headphones are unplugged.
 *
 * It does NOT request audio focus: the WebView does that itself for the audio element.
 * A second request from this app would take focus away from the WebView and pause it.
 */
public class MediaPlaybackService extends Service {

    /** Receives the transport actions (implemented by the ListenShell plugin). */
    interface ActionSink {
        /** @param positionSeconds only meaningful for "seek"; negative otherwise. */
        void onMediaAction(String action, double positionSeconds);
    }

    static final String ACTION_UPDATE = "studio.listenai.app.media.UPDATE";
    private static final String ACTION_BUTTON = "studio.listenai.app.media.BUTTON";
    private static final String EXTRA_BUTTON = "button";
    private static final String EXTRA_PLAYING = "playing";
    private static final String EXTRA_TITLE = "title";
    private static final String EXTRA_SUBTITLE = "subtitle";
    private static final String EXTRA_POSITION = "position";
    private static final String EXTRA_DURATION = "duration";

    private static final int NOTIFICATION_ID = 4101;
    /** Paused this long: the notification and the service go away. */
    private static final long PAUSED_TIMEOUT_MS = 5 * 60 * 1000L;
    /** Upper bound for holding the CPU awake without hearing from the page. */
    private static final long WAKE_LOCK_UNKNOWN_MS = 30 * 60 * 1000L;
    private static final long WAKE_LOCK_MAX_MS = 3 * 60 * 60 * 1000L;
    private static final long WAKE_LOCK_MARGIN_MS = 60 * 1000L;

    @Nullable
    private static volatile MediaPlaybackService instance;
    @Nullable
    private static volatile ActionSink sink;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable pausedTimeout = this::shutDown;

    private MediaSessionCompat session;
    private PowerManager.WakeLock wakeLock;
    private boolean noisyRegistered;
    private boolean buttonsRegistered;
    private boolean foreground;

    // What is currently shown, to avoid rebuilding the notification on every position update.
    private boolean playing;
    private String title = "";
    private String subtitle = "";
    private long durationMs = -1;
    private long lastPositionMs;
    private long lastPositionAt;
    private boolean hasState;

    // ------------------------------------------------------------------ called by the plugin

    static void setSink(@Nullable ActionSink newSink) {
        sink = newSink;
    }

    static boolean isRunning() {
        return instance != null;
    }

    /**
     * Shows / updates the playback state.
     * @return false when nothing is shown (paused with nothing running, or the system refused
     *         to start a foreground service because the app is in the background).
     */
    static boolean update(Context context, boolean playing, String title, String subtitle, double positionSec, double durationSec) {
        MediaPlaybackService running = instance;
        long positionMs = toMillis(positionSec);
        long durationMs = durationSec > 0 && !Double.isInfinite(durationSec) && !Double.isNaN(durationSec) ? toMillis(durationSec) : -1;
        if (running != null) {
            running.handler.post(() -> running.apply(playing, title, subtitle, positionMs, durationMs));
            return true;
        }
        if (!playing) return false; // Nothing to show for "paused" when nothing was playing.

        Intent intent = new Intent(context, MediaPlaybackService.class)
            .setAction(ACTION_UPDATE)
            .putExtra(EXTRA_PLAYING, true)
            .putExtra(EXTRA_TITLE, title)
            .putExtra(EXTRA_SUBTITLE, subtitle)
            .putExtra(EXTRA_POSITION, positionMs)
            .putExtra(EXTRA_DURATION, durationMs);
        try {
            ContextCompat.startForegroundService(context, intent);
            return true;
        } catch (RuntimeException e) {
            // Android 12+: ForegroundServiceStartNotAllowedException when playback starts while
            // the app is in the background. Playback itself is not affected; there are just no controls.
            return false;
        }
    }

    static void stop(Context context) {
        MediaPlaybackService running = instance;
        if (running != null) {
            running.handler.post(running::shutDown);
        }
    }

    private static long toMillis(double seconds) {
        if (Double.isNaN(seconds) || Double.isInfinite(seconds) || seconds < 0) return 0;
        return Math.round(seconds * 1000);
    }

    // ------------------------------------------------------------------ service lifecycle

    @Override
    public void onCreate() {
        super.onCreate();
        instance = this;

        session = new MediaSessionCompat(this, "ListenPlayback");
        // Buttons reach the session only while it is active; nothing restarts the app from a headset button.
        session.setMediaButtonReceiver(null);
        session.setCallback(new SessionCallback(), handler);
        Intent open = getPackageManager().getLaunchIntentForPackage(getPackageName());
        if (open != null) {
            session.setSessionActivity(PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT));
        }

        PowerManager power = (PowerManager) getSystemService(POWER_SERVICE);
        wakeLock = power.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "Listen:playback");
        wakeLock.setReferenceCounted(false);

        ContextCompat.registerReceiver(this, buttonReceiver, new IntentFilter(ACTION_BUTTON), ContextCompat.RECEIVER_NOT_EXPORTED);
        buttonsRegistered = true;
    }

    @Override
    public int onStartCommand(@Nullable Intent intent, int flags, int startId) {
        if (intent != null && ACTION_UPDATE.equals(intent.getAction())) {
            apply(
                intent.getBooleanExtra(EXTRA_PLAYING, false),
                intent.getStringExtra(EXTRA_TITLE),
                intent.getStringExtra(EXTRA_SUBTITLE),
                intent.getLongExtra(EXTRA_POSITION, 0),
                intent.getLongExtra(EXTRA_DURATION, -1)
            );
        } else if (!hasState) {
            // Started without anything to show (should not happen): leave cleanly, but first satisfy
            // the "must call startForeground" contract of startForegroundService.
            apply(false, "", "", 0, -1);
            shutDown();
        }
        // If the system kills the process there is no page playing any more: do not come back.
        return START_NOT_STICKY;
    }

    @Nullable
    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    /** The person swiped the app away from recents: playback is gone with the WebView. */
    @Override
    public void onTaskRemoved(Intent rootIntent) {
        shutDown();
        super.onTaskRemoved(rootIntent);
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        releaseWakeLock();
        unregisterNoisy();
        if (buttonsRegistered) {
            unregisterReceiver(buttonReceiver);
            buttonsRegistered = false;
        }
        if (session != null) {
            session.setActive(false);
            session.release();
        }
        if (instance == this) instance = null;
        super.onDestroy();
    }

    // ------------------------------------------------------------------ state

    private void apply(boolean nowPlaying, @Nullable String newTitle, @Nullable String newSubtitle, long positionMs, long newDurationMs) {
        String safeTitle = newTitle == null || newTitle.trim().isEmpty() ? getString(R.string.app_name) : newTitle.trim();
        String safeSubtitle = newSubtitle == null ? "" : newSubtitle.trim();

        boolean metadataChanged = !hasState || !safeTitle.equals(title) || !safeSubtitle.equals(subtitle) || newDurationMs != durationMs;
        boolean playingChanged = !hasState || nowPlaying != playing;
        // Where the system already thinks we are, given the last report and the time since.
        long expected = lastPositionMs + (playing ? SystemClock.elapsedRealtime() - lastPositionAt : 0);
        boolean positionJumped = Math.abs(expected - positionMs) > 1500;

        playing = nowPlaying;
        title = safeTitle;
        subtitle = safeSubtitle;
        durationMs = newDurationMs;
        hasState = true;

        if (metadataChanged) {
            MediaMetadataCompat.Builder metadata = new MediaMetadataCompat.Builder()
                .putString(MediaMetadataCompat.METADATA_KEY_TITLE, title)
                .putString(MediaMetadataCompat.METADATA_KEY_ARTIST, subtitle.isEmpty() ? getString(R.string.app_name) : subtitle);
            if (durationMs > 0) metadata.putLong(MediaMetadataCompat.METADATA_KEY_DURATION, durationMs);
            session.setMetadata(metadata.build());
        }
        if (playingChanged || positionJumped || metadataChanged) {
            lastPositionMs = positionMs;
            lastPositionAt = SystemClock.elapsedRealtime();
            long actions = PlaybackStateCompat.ACTION_PLAY | PlaybackStateCompat.ACTION_PAUSE | PlaybackStateCompat.ACTION_PLAY_PAUSE
                | PlaybackStateCompat.ACTION_STOP | PlaybackStateCompat.ACTION_REWIND | PlaybackStateCompat.ACTION_FAST_FORWARD;
            if (durationMs > 0) actions |= PlaybackStateCompat.ACTION_SEEK_TO;
            session.setPlaybackState(
                new PlaybackStateCompat.Builder()
                    .setActions(actions)
                    .setState(
                        playing ? PlaybackStateCompat.STATE_PLAYING : PlaybackStateCompat.STATE_PAUSED,
                        positionMs,
                        playing ? 1f : 0f,
                        lastPositionAt
                    )
                    // Android 13+ builds the controls from the session: these two add the 10-second buttons there.
                    .addCustomAction(BUTTON_REWIND, getString(R.string.media_rewind), R.drawable.ic_media_rewind)
                    .addCustomAction(BUTTON_FORWARD, getString(R.string.media_forward), R.drawable.ic_media_forward)
                    .build()
            );
        }
        if (!session.isActive()) session.setActive(true);

        if (playing) {
            handler.removeCallbacks(pausedTimeout);
            acquireWakeLock(positionMs);
            registerNoisy();
        } else {
            // Paused: let the CPU sleep, but stay a foreground service for a few minutes so that
            // "play" from the lock screen can resume (Android 12+ would refuse to re-enter the
            // foreground from the background). After the timeout everything goes away.
            releaseWakeLock();
            unregisterNoisy();
            if (playingChanged) {
                handler.removeCallbacks(pausedTimeout);
                handler.postDelayed(pausedTimeout, PAUSED_TIMEOUT_MS);
            }
        }
        if (!foreground || playingChanged || metadataChanged) {
            goForeground(buildNotification());
        }
    }

    private void goForeground(Notification notification) {
        try {
            ServiceCompat.startForeground(
                this,
                NOTIFICATION_ID,
                notification,
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q ? ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK : 0
            );
            foreground = true;
        } catch (RuntimeException e) {
            // Not allowed right now (app in the background on Android 12+). Keep the session and a plain notification.
            foreground = false;
            notifySafely(notification);
        }
    }

    private void notifySafely(Notification notification) {
        try {
            NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
            manager.notify(NOTIFICATION_ID, notification);
        } catch (RuntimeException ignored) {
            // Notifications are a convenience here; never crash playback over them.
        }
    }

    private void shutDown() {
        // From here on a new "playing" update starts a fresh service instead of reviving this one.
        if (instance == this) instance = null;
        handler.removeCallbacks(pausedTimeout);
        releaseWakeLock();
        unregisterNoisy();
        if (session != null) session.setActive(false);
        ServiceCompat.stopForeground(this, ServiceCompat.STOP_FOREGROUND_REMOVE);
        foreground = false;
        NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        manager.cancel(NOTIFICATION_ID);
        stopSelf();
    }

    // ------------------------------------------------------------------ wake lock

    /** Held only while playing; always with a timeout so a page that goes silent cannot drain the battery. */
    private void acquireWakeLock(long positionMs) {
        long timeout = durationMs > 0 ? Math.max(durationMs - positionMs, 0) + WAKE_LOCK_MARGIN_MS : WAKE_LOCK_UNKNOWN_MS;
        wakeLock.acquire(Math.min(timeout, WAKE_LOCK_MAX_MS));
    }

    private void releaseWakeLock() {
        if (wakeLock != null && wakeLock.isHeld()) wakeLock.release();
    }

    // ------------------------------------------------------------------ notification

    private static final String BUTTON_PLAY = "play";
    private static final String BUTTON_PAUSE = "pause";
    private static final String BUTTON_REWIND = "rewind";
    private static final String BUTTON_FORWARD = "forward";
    private static final String BUTTON_STOP = "stop";

    private Notification buildNotification() {
        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, ListenApplication.CHANNEL_PLAYBACK)
            .setSmallIcon(R.drawable.ic_stat_listen)
            .setColor(ContextCompat.getColor(this, R.color.brand))
            .setContentTitle(title)
            .setContentText(subtitle.isEmpty() ? null : subtitle)
            .setContentIntent(session.getController().getSessionActivity())
            .setDeleteIntent(buttonIntent(BUTTON_STOP, 4))
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setCategory(NotificationCompat.CATEGORY_TRANSPORT)
            .setOnlyAlertOnce(true)
            .setShowWhen(false)
            .setOngoing(playing)
            .setSilent(true);

        builder.addAction(R.drawable.ic_media_rewind, getString(R.string.media_rewind), buttonIntent(BUTTON_REWIND, 1));
        if (playing) {
            builder.addAction(R.drawable.ic_media_pause, getString(R.string.media_pause), buttonIntent(BUTTON_PAUSE, 2));
        } else {
            builder.addAction(R.drawable.ic_media_play, getString(R.string.media_play), buttonIntent(BUTTON_PLAY, 2));
        }
        builder.addAction(R.drawable.ic_media_forward, getString(R.string.media_forward), buttonIntent(BUTTON_FORWARD, 3));

        builder.setStyle(
            new androidx.media.app.NotificationCompat.MediaStyle()
                .setMediaSession(session.getSessionToken())
                .setShowActionsInCompactView(0, 1, 2)
        );
        return builder.build();
    }

    /** A button press comes back to this service only (explicit package, receiver not exported). */
    private PendingIntent buttonIntent(String button, int requestCode) {
        Intent intent = new Intent(ACTION_BUTTON).setPackage(getPackageName()).putExtra(EXTRA_BUTTON, button);
        return PendingIntent.getBroadcast(this, requestCode, intent, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
    }

    private final BroadcastReceiver buttonReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            String button = intent.getStringExtra(EXTRA_BUTTON);
            if (button == null) return;
            switch (button) {
                case BUTTON_PLAY -> send("play", -1);
                case BUTTON_PAUSE -> send("pause", -1);
                case BUTTON_REWIND -> send("rewind", -1);
                case BUTTON_FORWARD -> send("forward", -1);
                case BUTTON_STOP -> {
                    send("stop", -1);
                    shutDown();
                }
                default -> {}
            }
        }
    };

    // ------------------------------------------------------------------ headphones unplugged

    private final BroadcastReceiver noisyReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (AudioManager.ACTION_AUDIO_BECOMING_NOISY.equals(intent.getAction())) {
                send("pause", -1);
            }
        }
    };

    private void registerNoisy() {
        if (noisyRegistered) return;
        // A protected system broadcast; "not exported" still receives system broadcasts.
        ContextCompat.registerReceiver(
            this,
            noisyReceiver,
            new IntentFilter(AudioManager.ACTION_AUDIO_BECOMING_NOISY),
            ContextCompat.RECEIVER_NOT_EXPORTED
        );
        noisyRegistered = true;
    }

    private void unregisterNoisy() {
        if (!noisyRegistered) return;
        unregisterReceiver(noisyReceiver);
        noisyRegistered = false;
    }

    // ------------------------------------------------------------------ lock screen / headset / Bluetooth

    private final class SessionCallback extends MediaSessionCompat.Callback {

        @Override
        public void onPlay() {
            send("play", -1);
        }

        @Override
        public void onPause() {
            send("pause", -1);
        }

        @Override
        public void onStop() {
            send("stop", -1);
            shutDown();
        }

        @Override
        public void onSeekTo(long positionMs) {
            send("seek", Math.max(positionMs, 0) / 1000.0);
        }

        @Override
        public void onFastForward() {
            send("forward", -1);
        }

        @Override
        public void onRewind() {
            send("rewind", -1);
        }

        // Car and headset "next / previous" buttons: there is one track, so they skip 10 seconds.
        @Override
        public void onSkipToNext() {
            send("forward", -1);
        }

        @Override
        public void onSkipToPrevious() {
            send("rewind", -1);
        }

        @Override
        public void onCustomAction(String action, Bundle extras) {
            if (BUTTON_REWIND.equals(action)) send("rewind", -1);
            else if (BUTTON_FORWARD.equals(action)) send("forward", -1);
        }
    }

    private static void send(String action, double positionSeconds) {
        ActionSink current = sink;
        if (current != null) current.onMediaAction(action, positionSeconds);
    }
}
