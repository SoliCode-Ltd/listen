package studio.listenai.app;

import android.app.Application;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.os.Build;

/**
 * Creates the notification channels as soon as the process starts, so a push that arrives
 * while the app is closed already finds its channel.
 */
public class ListenApplication extends Application {

    /** Push: "your audio is ready". Also named in the manifest as the default push channel. */
    public static final String CHANNEL_VOICE_READY = "voice_ready";
    /** The silent now-playing notification. */
    public static final String CHANNEL_PLAYBACK = "playback";

    @Override
    public void onCreate() {
        super.onCreate();
        createNotificationChannels();
    }

    private void createNotificationChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null) return;

        // Creating a channel again only refreshes its name/description (e.g. after a language change).
        NotificationChannel ready = new NotificationChannel(
            CHANNEL_VOICE_READY,
            getString(R.string.notification_channel_voice_ready_name),
            NotificationManager.IMPORTANCE_DEFAULT
        );
        ready.setDescription(getString(R.string.notification_channel_voice_ready_description));
        manager.createNotificationChannel(ready);

        NotificationChannel playback = new NotificationChannel(
            CHANNEL_PLAYBACK,
            getString(R.string.notification_channel_playback_name),
            NotificationManager.IMPORTANCE_LOW
        );
        playback.setDescription(getString(R.string.notification_channel_playback_description));
        playback.setShowBadge(false);
        manager.createNotificationChannel(playback);
    }
}
