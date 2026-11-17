import UIKit
import WebKit
import Capacitor

/// Capacitor's screen (the web view and the bridge to the page), set up for Listen:
/// the site's address and the app's name come from the build, the app's own plugin is
/// registered, and navigation goes through `WebViewDelegates`.
final class ListenBridgeViewController: CAPBridgeViewController {
    weak var shell: ShellViewController?
    private var delegates: WebViewDelegates?
    private var messages: MainFrameMessages?

    override func instanceDescriptor() -> InstanceDescriptor {
        let descriptor = super.instanceDescriptor()
        descriptor.serverURL = AppConfig.urls.origin
        // Capacitor only starts when this path exists inside the app as a file
        // (mobile/www/app/start, an empty placeholder); what is shown is the site's page.
        descriptor.appStartPath = String(AppUrls.startPath.dropFirst())
        // "No connection" is the app's own screen (OfflineView), not a page in the history.
        descriptor.errorPath = nil
        descriptor.appendedUserAgentString = AppConfig.userAgentSuffix
        descriptor.allowedNavigationHostnames = []
        descriptor.allowLinkPreviews = false
        // The shell keeps the web view clear of the notch and the home indicator itself.
        descriptor.contentInsetAdjustmentBehavior = .never
        // Notifications are handled by the app delegate.
        descriptor.handleApplicationNotifications = false
        return descriptor
    }

    override func webViewConfiguration(for instanceConfiguration: InstanceConfiguration) -> WKWebViewConfiguration {
        let configuration = super.webViewConfiguration(for: instanceConfiguration)
        configuration.allowsInlineMediaPlayback = true
        configuration.allowsPictureInPictureMediaPlayback = false
        configuration.dataDetectorTypes = []
        return configuration
    }

    override func capacitorDidLoad() {
        bridge?.registerPluginInstance(ListenShellPlugin())

        guard let webView = webView else { return }
        // There is no back button on an iPhone: a swipe from the edge goes back a page.
        webView.allowsBackForwardNavigationGestures = true
        #if DEBUG
        if #available(iOS 16.4, *) {
            webView.isInspectable = true
        }
        #endif

        guard let capacitor = webView.navigationDelegate as? WebViewDelegationHandler else { return }

        // Only the site's own page, in the main frame, may talk to the app.
        let filter = MainFrameMessages(inner: capacitor, urls: AppConfig.urls)
        let controller = capacitor.contentController
        controller.removeScriptMessageHandler(forName: MainFrameMessages.handlerName)
        controller.add(filter, name: MainFrameMessages.handlerName)
        messages = filter

        let delegates = WebViewDelegates(inner: capacitor, urls: AppConfig.urls)
        delegates.shell = shell
        webView.navigationDelegate = delegates
        webView.uiDelegate = delegates
        self.delegates = delegates
    }

    func attach(_ shell: ShellViewController) {
        self.shell = shell
        delegates?.shell = shell
    }
}

/// Capacitor accepts calls from any frame of any page in the web view. Listen's pages are
/// the only ones shown, but a frame from elsewhere inside one of them (a video, a check
/// that the visitor is a person) must not reach the app: its messages are dropped here.
final class MainFrameMessages: NSObject, WKScriptMessageHandler {
    static let handlerName = "bridge"

    private weak var inner: WebViewDelegationHandler?
    private let urls: AppUrls

    init(inner: WebViewDelegationHandler, urls: AppUrls) {
        self.inner = inner
        self.urls = urls
    }

    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard message.frameInfo.isMainFrame else { return }
        let origin = message.frameInfo.securityOrigin
        guard origin.protocol.lowercased() == "https", origin.host.lowercased() == urls.host, origin.port == 0 || origin.port == 443 else { return }
        inner?.userContentController(userContentController, didReceive: message)
    }
}
