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
            if urls.isSignInUrl(url.absoluteString) && !WebViewDelegates.takeAllowance(for: url) {
                // The browser leg of sign-in stays in the sign-in sheet. The one exception is
                // the page that ends a sign-in, when the app itself asks for it (allowOnce).
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

    // MARK: - The end of a sign-in

    /// The one address of the sign-in leg the app itself loads: the page that ends a sign-in,
    /// with the code the sign-in sheet handed back. It is let through once, and only within a
    /// minute, so no page can send the web view there with a code of its own.
    private static var allowance: (address: String, until: Date)?

    static func allowOnce(_ url: URL, now: Date = Date()) {
        allowance = (url.absoluteString, now.addingTimeInterval(60))
    }

    static func takeAllowance(for url: URL, now: Date = Date()) -> Bool {
        guard let allowed = allowance else { return false }
        if now > allowed.until {
            allowance = nil
            return false
        }
        guard allowed.address == url.absoluteString else { return false }
        allowance = nil
        return true
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
        let error = error as NSError
        guard WebViewDelegates.isRealFailure(error) else {
            ShellViewController.note("left early \(error.domain) \(error.code)")
            return
        }
        let failed = error.userInfo[NSURLErrorFailingURLErrorKey] as? URL
        shell?.pageFailed(failed, reason: "\(error.domain) \(error.code) \(error.localizedDescription)")
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

    /// The page's process was ended by the phone (memory). Capacitor reloads the page; when no
    /// page had come yet there is nothing to reload, so the first page is asked for again.
    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
        ShellViewController.note("page process ended \(webView.url?.absoluteString ?? "-")")
        if webView.url == nil, let start = URL(string: urls.startUrl) {
            webView.load(URLRequest(url: start))
        } else {
            inner.webViewWebContentProcessDidTerminate(webView)
        }
    }

    // MARK: - A page's own questions (alert, confirm, prompt)

    // The site's pages ask "Delete this voice?" with the browser's own question. It is shown
    // in the page's language, over whatever is on the screen, and it always answers: a
    // question that is never answered would end the page's process.

    func webView(_ webView: WKWebView, runJavaScriptAlertPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping () -> Void) {
        guard frame.isMainFrame, let presenter = presenter() else {
            completionHandler()
            return
        }
        let lang = WebViewDelegates.language(of: webView.url)
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: AppConfig.text("action_ok", lang: lang), style: .default) { _ in completionHandler() })
        presenter.present(alert, animated: true)
    }

    func webView(_ webView: WKWebView, runJavaScriptConfirmPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (Bool) -> Void) {
        guard frame.isMainFrame, let presenter = presenter() else {
            completionHandler(false)
            return
        }
        let lang = WebViewDelegates.language(of: webView.url)
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: AppConfig.text("action_cancel", lang: lang), style: .cancel) { _ in completionHandler(false) })
        alert.addAction(UIAlertAction(title: AppConfig.text("action_ok", lang: lang), style: .default) { _ in completionHandler(true) })
        presenter.present(alert, animated: true)
    }

    func webView(_ webView: WKWebView, runJavaScriptTextInputPanelWithPrompt prompt: String, defaultText: String?, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (String?) -> Void) {
        // Capacitor answers some of these itself (its cookie calls), so only the site's own
        // page may ask: a frame inside a page gets no answer.
        let origin = frame.securityOrigin
        let isSite = origin.protocol.lowercased() == "https" && origin.host.lowercased() == urls.host
        guard frame.isMainFrame, isSite, presenter() != nil else {
            completionHandler(nil)
            return
        }
        inner.webView(webView, runJavaScriptTextInputPanelWithPrompt: prompt, defaultText: defaultText, initiatedByFrame: frame, completionHandler: completionHandler)
    }

    /// What is on top of the screen and can show a question, or nil when nothing can.
    private func presenter() -> UIViewController? {
        guard var top: UIViewController = shell, top.viewIfLoaded?.window != nil else { return nil }
        while let next = top.presentedViewController, !next.isBeingDismissed {
            top = next
        }
        return top
    }

    /// The language of a page of the site, from its address (/ar/..., /en/...); the phone's otherwise.
    static func language(of url: URL?) -> String {
        let first = (url?.pathComponents ?? []).first(where: { $0 != "/" })?.lowercased() ?? ""
        return ["ar", "en"].contains(first) ? first : AppConfig.deviceLang
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
