import Foundation

/// Where everything that asks the app to show a page meets: a link to the site that the
/// phone hands to the app, a tapped notification, a shortcut on the app's icon, the end of
/// a sign-in. Only pages of the site are ever shown, and never the browser leg of sign-in.
///
/// A request that comes before the screen exists (the app was opened by it) is kept and
/// shown as the first page.
final class AppRouter {
    static let shared = AppRouter()

    private weak var shell: ShellViewController?
    private var waiting: URL?

    func attach(_ shell: ShellViewController) {
        self.shell = shell
    }

    /// The first page asked for before the screen existed, once.
    func takeWaiting() -> URL? {
        defer { waiting = nil }
        return waiting
    }

    @discardableResult
    func open(_ address: String?) -> Bool {
        let urls = AppConfig.urls
        guard let address = address, urls.isAppUrl(address), !urls.isSignInUrl(address), let url = URL(string: address) else {
            return false
        }
        show(url)
        return true
    }

    /// The end of a sign-in: the one address of the sign-in leg that belongs in the app.
    func completeSignIn(code: String, verifier: String) {
        if let url = URL(string: AppConfig.urls.signInCompleteUrl(code: code, verifier: verifier)) {
            show(url)
        }
    }

    private func show(_ url: URL) {
        DispatchQueue.main.async {
            if let shell = self.shell, shell.isViewLoaded {
                shell.load(url)
            } else {
                self.waiting = url
            }
        }
    }
}
