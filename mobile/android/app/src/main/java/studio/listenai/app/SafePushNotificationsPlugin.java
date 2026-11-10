package studio.listenai.app;

import android.Manifest;
import android.content.Context;
import com.capacitorjs.plugins.pushnotifications.PushNotificationsPlugin;
import com.getcapacitor.JSObject;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.google.firebase.FirebaseApp;

/**
 * The official push plugin, made safe for builds without google-services.json.
 *
 * Without that file Firebase is not configured, and the stock register() would throw and
 * take the whole app down. Here it reports "unavailable" instead: the call is rejected with
 * code UNAVAILABLE and a "registrationError" event is sent. Everything else is unchanged.
 *
 * Registered under the same name ("PushNotifications") in place of the stock class.
 */
@CapacitorPlugin(
    name = "PushNotifications",
    permissions = @Permission(strings = { Manifest.permission.POST_NOTIFICATIONS }, alias = "receive")
)
public class SafePushNotificationsPlugin extends PushNotificationsPlugin {

    static final String UNAVAILABLE_MESSAGE = "Push notifications are not available in this build";

    /** True when the build contains a Firebase configuration (google-services.json was present). */
    static boolean isPushConfigured(Context context) {
        try {
            return !FirebaseApp.getApps(context).isEmpty();
        } catch (RuntimeException e) {
            return false;
        }
    }

    @Override
    @PluginMethod
    public void register(PluginCall call) {
        if (!isPushConfigured(getContext())) {
            JSObject data = new JSObject();
            data.put("error", UNAVAILABLE_MESSAGE);
            notifyListeners("registrationError", data, true);
            call.unavailable(UNAVAILABLE_MESSAGE);
            return;
        }
        try {
            super.register(call);
        } catch (RuntimeException e) {
            call.reject(String.valueOf(e.getMessage()));
        }
    }

    @Override
    @PluginMethod
    public void unregister(PluginCall call) {
        if (!isPushConfigured(getContext())) {
            call.resolve();
            return;
        }
        try {
            super.unregister(call);
        } catch (RuntimeException e) {
            call.reject(String.valueOf(e.getMessage()));
        }
    }
}
