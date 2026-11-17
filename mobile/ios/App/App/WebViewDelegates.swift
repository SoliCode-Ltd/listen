import UIKit
import WebKit
import Capacitor

/// Decides what the web view may show, and tells the shell what happens to a page.
///
/// - Pages of the site are shown in the app. Everything else (another site, a phone
///   number, an email address) opens outside it, as on any phone.
/// - The browser leg of sign-in is never shown in the app.
/// - A file the web view cannot show is saved through the phone instead.
/// - Only the site may use the microphone; the camera is never given.
///
/// Whatever is not decided here is passed on to Capacitor's own handler.
final class WebViewDelegates: NSObject, WKNavigationDelegate, WKUIDelegate {
    weak var shell: ShellViewController?
    private let inner: WebViewDelegationHandler
    private let urls: AppUrls

    init(inner: WebViewDelegationHandler, urls: AppUrls) {
        self.inner = inner
        self.urls = urls
    }

    // Everything this class does not implement itself is answered by Capacitor's handler.
    override func responds(to aSelector: Selector!) -> Bool {
        super.responds(to: aSelector) || inner.responds(to: aSelector)
    }

    override func forwardingTarget(for aSelector: Selector!) -> Any? {
        inner.responds(to: aSelector) ? inner : super.forwardingTarget(for: aSelector)
    }

    // MARK: - Where a tap may lead

    func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        guard let url = navigationAction.request.url else {
            decisionHandler(.cancel)
            return
        }
        let mainFrame = navigationAction.targetFrame?.isMainFrame ?? true
        let scheme = (url.scheme ?? "").lowercased()

        // A frame inside a page (a video, a check) loads what it likes; it cannot reach the app.
        if !mainFrame || scheme == "about" || scheme == "blob" || scheme == "data" {
            inner.webView(webView, decidePolicyFor: navigationAction, decisionHandler: decisionHandler)
            return
        }

        if urls.isAppUrl(url) {
            if urls.isSignInUrl(url.absoluteString) {
                // The browser leg of sign-in stays in the sign-in sheet.
                decisionHandler(.cancel)
                return
            }
            inner.webView(webView, decidePolicyFor: navigationAction, decisionHandler: decisionHandler)
            return
        }

        // Not the site: it opens outside the app, or not at all.
        decisionHandler(.cancel)
        WebViewDelegates.openOutside(url)
    }

    /// Opens a link outside the app when it is one a phone knows what to do with.
    static func openOutside(_ url: URL) {
        let scheme = (url.scheme ?? "").lowercased()
        guard ["https", "http", "mailto", "tel", "sms", "whatsapp"].contains(scheme) else { return }
        UIApplication.shared.open(url, options: [:], completionHandler: nil)
    }

    func webView(_ webView: WKWebView, decidePolicyFor navigationResponse: WKNavigationResponse, decisionHandler: @escaping (WKNavigationResponsePolicy) -> Void) {
        if navigationResponse.isForMainFrame,
           let response = navigationResponse.response as? HTTPURLResponse,
           let url = response.url, urls.isAppUrl(url) {
            let disposition = (response.value(forHTTPHeaderField: "Content-Disposition") ?? "").lowercased()
            if disposition.hasPrefix("attachment") || !navigationResponse.canShowMIMEType {
                // A file, not a page: the pages use ListenShell.saveAudio; this is the safety net.
                decisionHandler(.cancel)
                shell?.saveDownload(from: url, suggestedName: response.suggestedFilename, mimeType: response.mimeType)
                return
            }
        }
        decisionHandler(.allow)
    }

    // MARK: - What happens to a page

    func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
        inner.webView(webView, didStartProvisionalNavigation: navigation)
        shell?.pageStarted()
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        inner.webView(webView, didFinish: navigation)
        shell?.pageFinished()
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        inner.webView(webView, didFail: navigation, withError: error)
        report(error)
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        inner.webView(webView, didFailProvisionalNavigation: navigation, withError: error)
        report(error)
    }

    private func report(_ error: Error) {
        guard WebViewDelegates.isRealFailure(error) else { return }
        let failed = (error as NSError).userInfo[NSURLErrorFailingURLErrorKey] as? URL
        shell?.pageFailed(failed)
    }

    /// A page that was left for another one before it came, or a file that was saved
    /// instead of shown, did not fail.
    static func isRealFailure(_ error: Error) -> Bool {
        let error = error as NSError
        if error.domain == NSURLErrorDomain && error.code == NSURLErrorCancelled { return false }
        // "Frame load interrupted" (102) and "plug-in handled load" (204).
        if error.domain == "WebKitErrorDomain" && (error.code == 102 || error.code == 204) { return false }
        return true
    }

    // MARK: - New windows, microphone

    func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration, for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
        // A link that asks for a new window: the app has one window.
        guard let url = navigationAction.request.url else { return nil }
        if urls.isAppUrl(url) {
            if !urls.isSignInUrl(url.absoluteString) {
                webView.load(navigationAction.request)
            }
        } else {
            WebViewDelegates.openOutside(url)
        }
        return nil
    }

    func webView(_ webView: WKWebView,
                 requestMediaCapturePermissionFor origin: WKSecurityOrigin,
                 initiatedByFrame frame: WKFrameInfo,
                 type: WKMediaCaptureType,
                 decisionHandler: @escaping (WKPermissionDecision) -> Void) {
        // The phone has already asked the person (ListenShell.ensureMic); here the app says
        // which page may listen: the site's own, for the microphone only. Never the camera.
        let isSite = origin.protocol.lowercased() == "https" && origin.host.lowercased() == urls.host
        decisionHandler(isSite && frame.isMainFrame && type == .microphone ? .grant : .deny)
    }
}
