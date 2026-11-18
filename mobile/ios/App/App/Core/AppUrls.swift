import Foundation

/// Everything the app knows about the site's addresses: which URLs belong to the site
/// (and so may be shown in the app's own web view, receive its cookies, or be downloaded
/// natively) and how the fixed app routes are built.
///
/// Foundation only, on purpose: the unit tests run it without a phone. It says the same
/// as the Android app's `AppUrls.java`; keep the two in step.
struct AppUrls {
    static let startPath = "/app/start"
    /// The "no connection" page asks for this address when "Try again" is pressed.
    static let retryPath = "/__retry"
    private static let signInPrefix = "/app/signin/"

    /// The site, e.g. `https://listenai.studio` (https, no path, no trailing slash).
    let origin: String
    let host: String

    /// Nil when the address is not `https://host`.
    init?(origin: String) {
        var trimmed = origin.trimmingCharacters(in: .whitespacesAndNewlines)
        while trimmed.hasSuffix("/") { trimmed.removeLast() }
        guard let parts = AppUrls.parse(trimmed),
              parts.scheme?.lowercased() == "https",
              let host = parts.host, !host.isEmpty,
              parts.path.isEmpty, parts.query == nil, parts.fragment == nil,
              parts.user == nil, parts.password == nil else {
            return nil
        }
        self.origin = trimmed
        self.host = host.lowercased()
    }

    var startUrl: String { origin + AppUrls.startPath }

    /// True only for https URLs on exactly the configured host (default port, no user info).
    func isAppUrl(_ url: String?) -> Bool {
        guard let parts = AppUrls.parse(url) else { return false }
        guard parts.scheme?.lowercased() == "https" else { return false }
        guard let urlHost = parts.host?.lowercased(), urlHost == host else { return false }
        if parts.user != nil || parts.password != nil { return false }
        return parts.port == nil || parts.port == 443
    }

    func isAppUrl(_ url: URL?) -> Bool {
        isAppUrl(url?.absoluteString)
    }

    /// Turns what a page passed in (a full URL, or a path such as `/audio/5/download`)
    /// into a full site URL. Nil when the result is not on the site.
    func resolve(_ urlOrPath: String?) -> String? {
        guard var value = urlOrPath?.trimmingCharacters(in: .whitespacesAndNewlines), !value.isEmpty else { return nil }
        if value.hasPrefix("/") && !value.hasPrefix("//") {
            value = origin + value
        }
        return isAppUrl(value) ? value : nil
    }

    /// The part after the origin: path, query and fragment. Always starts with "/".
    func pathAndQuery(_ appUrl: String) -> String {
        guard let parts = AppUrls.parse(appUrl) else { return "/" }
        var out = parts.percentEncodedPath.isEmpty ? "/" : parts.percentEncodedPath
        if let query = parts.percentEncodedQuery { out += "?" + query }
        if let fragment = parts.percentEncodedFragment { out += "#" + fragment }
        return out
    }

    /// The browser leg of sign-in must never be shown inside the web view.
    func isSignInUrl(_ url: String?) -> Bool {
        guard let parts = AppUrls.parse(url) else { return false }
        return parts.percentEncodedPath.hasPrefix(AppUrls.signInPrefix)
    }

    /// "Try again" on the "no connection" page (served from the app itself, not the site).
    static func isRetryUrl(_ url: URL?) -> Bool {
        url?.path == retryPath
    }

    /// The sign-in sheet's first address. `platform=ios` tells the site that the iPhone
    /// app opened it, so the iPhone rules hold in the sheet too.
    func signInStartUrl(challenge: String, locale: String?) -> String {
        origin + AppUrls.signInPrefix + "start?challenge=" + AppUrls.encode(challenge)
            + "&locale=" + AppUrls.normalizeLocale(locale) + "&platform=ios"
    }

    func signInCompleteUrl(code: String, verifier: String) -> String {
        origin + AppUrls.signInPrefix + "complete?code=" + AppUrls.encode(code) + "&verifier=" + AppUrls.encode(verifier)
    }

    /// The site has two languages; anything else falls back to English.
    static func normalizeLocale(_ locale: String?) -> String {
        (locale ?? "").lowercased().hasPrefix("ar") ? "ar" : "en"
    }

    private static func parse(_ url: String?) -> URLComponents? {
        guard let url = url, !url.isEmpty else { return nil }
        // Characters that browsers and URL parsers disagree about are refused outright.
        for scalar in url.unicodeScalars where scalar == "\\" || scalar.value <= 0x20 || scalar.value == 0x7f {
            return nil
        }
        return URLComponents(string: url)
    }

    /// Percent-encodes everything but letters, digits and "-._~", so a value from outside
    /// the app can never add a parameter of its own.
    private static func encode(_ value: String) -> String {
        var allowed = CharacterSet()
        allowed.insert(charactersIn: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~")
        return value.addingPercentEncoding(withAllowedCharacters: allowed) ?? ""
    }
}
