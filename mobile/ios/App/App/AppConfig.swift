import Foundation

/// What the build decides: which site the app opens and what it calls itself.
/// The values come from Info.plist (filled from the build settings in project.yml),
/// so one code base builds the live app and a staging one.
enum AppConfig {
    static let defaultSite = "https://listenai.studio"

    /// The scheme the sign-in sheet hands its one-time code back on (the site's
    /// AppShellController::APP_SCHEME; the same as the Android app's).
    static let callbackScheme = "studio.listenai.app"

    static let urls: AppUrls = {
        let configured = (Bundle.main.object(forInfoDictionaryKey: "ListenAppURL") as? String) ?? ""
        return AppUrls(origin: configured) ?? AppUrls(origin: defaultSite)!
    }()

    static var version: String {
        (Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String) ?? "1.0.0"
    }

    static var build: Int {
        Int((Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String) ?? "") ?? 1
    }

    /// Added to the web view's user agent: how the site knows it is inside the iPhone app
    /// (App\Support\InApp\InApp on the site). No browser sends it.
    static var userAgentSuffix: String {
        "ListenApp/\(version) (iOS)"
    }

    /// False in a build made without the notifications entitlement.
    static var pushAvailable: Bool {
        (Bundle.main.object(forInfoDictionaryKey: "ListenPushEnabled") as? Bool) ?? true
    }

    /// The app's own words in the language of the page that asks ("ar" or "en"), whatever
    /// the phone's language is: a member reading the site in Arabic is asked in Arabic.
    static func text(_ key: String, lang: String) -> String {
        let code = AppUrls.normalizeLocale(lang)
        if let path = Bundle.main.path(forResource: code, ofType: "lproj"), let bundle = Bundle(path: path) {
            return bundle.localizedString(forKey: key, value: nil, table: nil)
        }
        return NSLocalizedString(key, comment: "")
    }

    /// The phone's language as the site knows languages: Arabic or English.
    static var deviceLang: String {
        AppUrls.normalizeLocale(Bundle.main.preferredLocalizations.first ?? Locale.preferredLanguages.first)
    }
}
