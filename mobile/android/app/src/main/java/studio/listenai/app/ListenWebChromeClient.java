package studio.listenai.app;

import android.net.Uri;
import android.webkit.PermissionRequest;
import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeWebChromeClient;

/**
 * Capacitor's chrome client (file chooser, dialogs, getUserMedia) with one restriction:
 * only the site itself may use the microphone, and nothing else (camera, protected media)
 * is ever granted.
 */
class ListenWebChromeClient extends BridgeWebChromeClient {

    private final AppUrls urls;

    ListenWebChromeClient(Bridge bridge, AppUrls urls) {
        super(bridge);
        this.urls = urls;
    }

    @Override
    public void onPermissionRequest(PermissionRequest request) {
        Uri origin = request.getOrigin();
        String[] resources = request.getResources();
        boolean fromSite = origin != null && urls.isAppUrl(origin.toString());
        boolean microphoneOnly = resources != null && resources.length == 1 && PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(resources[0]);
        if (!fromSite || !microphoneOnly) {
            request.deny();
            return;
        }
        // Asks for RECORD_AUDIO if needed, then grants or denies the page's request.
        super.onPermissionRequest(request);
    }
}
