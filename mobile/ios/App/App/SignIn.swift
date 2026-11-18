import UIKit
import AuthenticationServices

/// "Continue with Google" (and any sign-in that must happen outside a web view): the
/// phone's own sign-in sheet opens over the app, the member signs in there, and the sheet
/// hands the app a one-time code that only this app can use, because it is tied to a
/// secret the app made (Pkce). The app never leaves the screen, as Apple asks.
///
/// The exchange is the Android app's (mobile/BRIDGE.md, section 4).
final class SignIn: NSObject, ASWebAuthenticationPresentationContextProviding {
    static let shared = SignIn()

    private var session: ASWebAuthenticationSession?
    private weak var anchor: UIWindow?

    /// Opens the sheet. False when it could not be opened.
    func start(locale: String?, in window: UIWindow?) -> Bool {
        let urls = AppConfig.urls
        let verifier = Pkce.newVerifier()
        guard let window = window,
              let start = URL(string: urls.signInStartUrl(challenge: Pkce.challenge(verifier), locale: locale)) else {
            return false
        }
        session?.cancel()
        anchor = window

        var opened: ASWebAuthenticationSession?
        let session = ASWebAuthenticationSession(url: start, callbackURLScheme: AppConfig.callbackScheme) { [weak self] callback, _ in
            // Only this sheet's own end forgets it: an older sheet that was closed to make
            // room for this one must not take this one away.
            if let self = self, self.session === opened { self.session = nil }
            opened = nil
            // Closed without signing in: nothing to do. The verifier is forgotten with this closure.
            guard let callback = callback, let code = SignIn.code(from: callback) else { return }
            AppRouter.shared.completeSignIn(code: code, verifier: verifier)
        }
        session.presentationContextProvider = self
        // The phone's browser may already be signed in to Google: let the sheet use that.
        session.prefersEphemeralWebBrowserSession = false
        opened = session
        self.session = session
        return session.start()
    }

    /// The code from `studio.listenai.app://signin?code=...`, or nil for any other link.
    static func code(from callback: URL) -> String? {
        guard callback.scheme?.lowercased() == AppConfig.callbackScheme,
              callback.host?.lowercased() == "signin",
              let items = URLComponents(url: callback, resolvingAgainstBaseURL: false)?.queryItems,
              let code = items.first(where: { $0.name == "code" })?.value,
              code.range(of: "^[A-Za-z0-9]{16,128}$", options: .regularExpression) != nil else {
            return nil
        }
        return code
    }

    func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        anchor ?? ASPresentationAnchor()
    }
}
