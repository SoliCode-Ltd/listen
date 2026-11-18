#if canImport(UIKit)
import XCTest
import AuthenticationServices
@testable import App

/// What the app promises Apple and the site, checked on every build: the settings a
/// review looks at, the app's own words in both languages, and the small rules of the
/// native side that need no network.
final class AppSettingsTests: XCTestCase {
    private var info: [String: Any] { Bundle.main.infoDictionary ?? [:] }

    func testTheAppSaysWhoItIsToTheSite() {
        // The site knows the iPhone app by this (App\Support\InApp\InApp::platform).
        XCTAssertNotNil(AppConfig.userAgentSuffix.range(of: "^ListenApp/\\d[\\w.\\-]* \\(iOS\\)$", options: .regularExpression), AppConfig.userAgentSuffix)
        XCTAssertEqual("https", URL(string: AppConfig.urls.origin)?.scheme)
        XCTAssertGreaterThanOrEqual(AppConfig.build, 1)
    }

    func testOnlyTheMicrophoneAndCameraAreEverAskedFor() {
        let asked = info.keys.filter { $0.hasPrefix("NS") && $0.hasSuffix("UsageDescription") }.sorted()
        XCTAssertEqual(["NSCameraUsageDescription", "NSMicrophoneUsageDescription"], asked)
    }

    func testEachPermissionSaysWhyInBothLanguages() {
        for lang in ["en", "ar"] {
            let strings = AppSettingsTests.strings("InfoPlist", lang)
            for key in ["NSMicrophoneUsageDescription", "NSCameraUsageDescription", "CFBundleDisplayName", "SHORTCUT_STUDIO", "SHORTCUT_VOICES", "SHORTCUT_AGENTS"] {
                XCTAssertGreaterThan((strings[key] ?? "").count, 3, "\(key) (\(lang))")
            }
            XCTAssertGreaterThan((strings["NSMicrophoneUsageDescription"] ?? "").count, 40, "a full sentence (\(lang))")
        }
    }

    func testTheAppsOwnWordsExistInBothLanguages() {
        let english = AppSettingsTests.strings("Localizable", "en")
        let arabic = AppSettingsTests.strings("Localizable", "ar")
        XCTAssertFalse(english.isEmpty)
        XCTAssertEqual(Set(english.keys), Set(arabic.keys))
        for (key, value) in arabic {
            XCTAssertFalse(value.isEmpty, key)
            XCTAssertNotEqual(value, english[key], "\(key) is not translated")
        }
        XCTAssertEqual("Try again", AppConfig.text("offline_retry", lang: "en"))
        XCTAssertEqual("إعادة المحاولة", AppConfig.text("offline_retry", lang: "ar"))
        // A language the app does not have falls back to English.
        XCTAssertEqual("Try again", AppConfig.text("offline_retry", lang: "fr"))
    }

    func testNoOtherPlatformOrStoreIsNamedInTheAppsWords() {
        // Apple's guideline 2.3.10, and the site's own rule about naming other companies.
        let banned = ["android", "google", "play store", "apple", "iphone", "أندرويد", "جوجل", "آبل", "أبل", "آيفون"]
        for lang in ["en", "ar"] {
            for table in ["Localizable", "InfoPlist"] {
                for (key, value) in AppSettingsTests.strings(table, lang) {
                    for word in banned {
                        XCTAssertFalse(value.lowercased().contains(word), "\(table).\(key) (\(lang)) names \(word)")
                    }
                }
            }
        }
    }

    func testTheSettingsAReviewLooksAt() {
        // Only the phone's own HTTPS encryption: no export paperwork.
        XCTAssertEqual(false, info["ITSAppUsesNonExemptEncryption"] as? Bool)
        // One background mode, for sound that keeps playing.
        XCTAssertEqual(["audio"], info["UIBackgroundModes"] as? [String])
        XCTAssertEqual(["UIInterfaceOrientationPortrait"], info["UISupportedInterfaceOrientations"] as? [String])
        // iPhone only.
        XCTAssertEqual([1], info["UIDeviceFamily"] as? [Int])
        // Nothing in the app asks to follow people across apps.
        XCTAssertNil(info["NSUserTrackingUsageDescription"])
    }

    func testThePrivacyManifestIsInTheAppAndSaysNoTracking() throws {
        let url = try XCTUnwrap(Bundle.main.url(forResource: "PrivacyInfo", withExtension: "xcprivacy"))
        let manifest = try XCTUnwrap(NSDictionary(contentsOf: url) as? [String: Any])
        XCTAssertEqual(false, manifest["NSPrivacyTracking"] as? Bool)
        XCTAssertEqual([], manifest["NSPrivacyTrackingDomains"] as? [String])
        let apis = try XCTUnwrap(manifest["NSPrivacyAccessedAPITypes"] as? [[String: Any]])
        XCTAssertEqual(["NSPrivacyAccessedAPICategoryUserDefaults"], apis.compactMap { $0["NSPrivacyAccessedAPIType"] as? String })
        XCTAssertEqual([["CA92.1"]], apis.compactMap { $0["NSPrivacyAccessedAPITypeReasons"] as? [String] })
        let collected = try XCTUnwrap(manifest["NSPrivacyCollectedDataTypes"] as? [[String: Any]])
        XCTAssertFalse(collected.isEmpty)
        for type in collected {
            XCTAssertEqual(false, type["NSPrivacyCollectedDataTypeTracking"] as? Bool)
        }
    }

    func testEveryShortcutOnTheIconLeadsSomewhereOnTheSite() throws {
        let items = try XCTUnwrap(info["UIApplicationShortcutItems"] as? [[String: Any]])
        let types = items.compactMap { $0["UIApplicationShortcutItemType"] as? String }
        XCTAssertEqual(Set(SceneDelegate.shortcuts.keys), Set(types))
        for path in SceneDelegate.shortcuts.values {
            XCTAssertTrue(AppConfig.urls.isAppUrl(AppConfig.urls.origin + path), path)
        }
    }

    func testTheBundledPlaceholderCapacitorNeedsIsThere() {
        // Capacitor refuses to start when its start path is not a file inside the app.
        let start = Bundle.main.bundleURL.appendingPathComponent("public").appendingPathComponent(String(AppUrls.startPath.dropFirst()))
        XCTAssertTrue(FileManager.default.fileExists(atPath: start.path), start.path)
    }

    private static func strings(_ table: String, _ lang: String) -> [String: String] {
        guard let path = Bundle.main.path(forResource: table, ofType: "strings", inDirectory: nil, forLocalization: lang),
              let dictionary = NSDictionary(contentsOfFile: path) as? [String: String] else {
            return [:]
        }
        return dictionary
    }
}

final class NativeRulesTests: XCTestCase {
    private let urls = AppUrls(origin: "https://listenai.studio")!

    func testTheSignInCodeIsTakenOnlyFromTheAppsOwnReturnLink() {
        let code = String(repeating: "aB3", count: 21) + "x" // 64 characters
        XCTAssertEqual(code, SignIn.code(from: URL(string: "studio.listenai.app://signin?code=\(code)")!))
        XCTAssertNil(SignIn.code(from: URL(string: "studio.listenai.app://signin")!))
        XCTAssertNil(SignIn.code(from: URL(string: "studio.listenai.app://other?code=\(code)")!))
        XCTAssertNil(SignIn.code(from: URL(string: "https://listenai.studio/signin?code=\(code)")!))
        XCTAssertNil(SignIn.code(from: URL(string: "studio.listenai.app://signin?code=a%26verifier%3Dx")!))
        XCTAssertNil(SignIn.code(from: URL(string: "studio.listenai.app://signin?code=short")!))
    }

    func testAFileRequestIsCheckedBeforeAnythingIsDownloaded() {
        func code(_ url: String?, _ base64: String?, _ type: String?) -> String {
            switch AudioFiles.request(url: url, base64: base64, fileName: "voice", mimeType: type, urls: urls) {
            case .success: return "ok"
            case .failure(let failure): return failure.code
            }
        }
        XCTAssertEqual("ok", code("/audio/5/download", nil, "audio/mpeg"))
        XCTAssertEqual("ok", code(nil, "AAAA", "audio/wav"))
        XCTAssertEqual("bad_request", code(nil, nil, "audio/mpeg"))
        XCTAssertEqual("bad_request", code("/audio/5/download", "AAAA", "audio/mpeg"))
        XCTAssertEqual("bad_request", code("/audio/5/download", nil, "text/html"))
        XCTAssertEqual("url_not_allowed", code("https://evil.example/x.mp3", nil, "audio/mpeg"))
        XCTAssertEqual("url_not_allowed", code("//evil.example/x.mp3", nil, "audio/mpeg"))

        if case .success(let request) = AudioFiles.request(url: "/audio/5/download", base64: nil, fileName: "../../صوتي", mimeType: "audio/mpeg", urls: urls) {
            XCTAssertEqual("https://listenai.studio/audio/5/download", request.url)
            XCTAssertEqual("صوتي.mp3", request.fileName)
        } else {
            XCTFail("a good request was refused")
        }
    }

    func testClosingApplesSheetIsNotAFailure() {
        XCTAssertEqual("cancelled", AppleSignIn.failure(from: ASAuthorizationError(.canceled)).code)
        XCTAssertEqual("apple_failed", AppleSignIn.failure(from: ASAuthorizationError(.failed)).code)
        XCTAssertEqual("apple_failed", AppleSignIn.failure(from: NSError(domain: NSURLErrorDomain, code: NSURLErrorTimedOut)).code)
    }

    func testThePageCanAskForEverythingTheBridgePromises() {
        // mobile/BRIDGE.md: the same names as the Android app's, plus Apple's sign-in.
        let names = Set(ListenShellPlugin().pluginMethods.compactMap { $0.name as String? })
        XCTAssertEqual([
            "getInfo", "mediaUpdate", "mediaStop", "saveAudio", "shareAudio", "ensureMic", "ensureNotifications",
            "signIn", "signInWithApple", "setPullToRefresh", "setBackHandler", "minimize", "openExternal", "setTheme"
        ], names)
    }

    func testTheEndOfASignInIsLetThroughOnceAndOnlyForTheAppsOwnAddress() throws {
        let end = try XCTUnwrap(URL(string: "https://listenai.studio/app/signin/complete?code=abc&verifier=def"))
        let other = try XCTUnwrap(URL(string: "https://listenai.studio/app/signin/complete?code=evil&verifier=def"))
        let now = Date()
        // Nothing is let through unasked.
        XCTAssertFalse(WebViewDelegates.takeAllowance(for: end, now: now))
        WebViewDelegates.allowOnce(end, now: now)
        // Not another code, and the right one only once.
        XCTAssertFalse(WebViewDelegates.takeAllowance(for: other, now: now))
        XCTAssertTrue(WebViewDelegates.takeAllowance(for: end, now: now))
        XCTAssertFalse(WebViewDelegates.takeAllowance(for: end, now: now))
        // And not after a minute.
        WebViewDelegates.allowOnce(end, now: now)
        XCTAssertFalse(WebViewDelegates.takeAllowance(for: end, now: now.addingTimeInterval(61)))
        XCTAssertFalse(WebViewDelegates.takeAllowance(for: end, now: now))
    }

    func testAPagesQuestionIsAskedInThePagesLanguage() {
        XCTAssertEqual("ar", WebViewDelegates.language(of: URL(string: "https://listenai.studio/ar/dashboard")))
        XCTAssertEqual("en", WebViewDelegates.language(of: URL(string: "https://listenai.studio/en/agents/x")))
        XCTAssertEqual(AppConfig.deviceLang, WebViewDelegates.language(of: URL(string: "https://listenai.studio/app/start")))
        XCTAssertEqual(AppConfig.deviceLang, WebViewDelegates.language(of: nil))
        XCTAssertEqual("إلغاء", AppConfig.text("action_cancel", lang: "ar"))
        XCTAssertEqual("OK", AppConfig.text("action_ok", lang: "en"))
    }

    func testLeavingAPageEarlyIsNotAFailure() {
        XCTAssertFalse(WebViewDelegates.isRealFailure(NSError(domain: NSURLErrorDomain, code: NSURLErrorCancelled)))
        XCTAssertFalse(WebViewDelegates.isRealFailure(NSError(domain: "WebKitErrorDomain", code: 102)))
        XCTAssertTrue(WebViewDelegates.isRealFailure(NSError(domain: NSURLErrorDomain, code: NSURLErrorNotConnectedToInternet)))
        XCTAssertTrue(WebViewDelegates.isRealFailure(NSError(domain: NSURLErrorDomain, code: NSURLErrorTimedOut)))
    }

    func testOnlyPagesOfTheSiteAreEverShown() {
        XCTAssertFalse(AppRouter.shared.open("https://evil.example/ar/dashboard"))
        XCTAssertFalse(AppRouter.shared.open("https://listenai.studio.evil.example/"))
        XCTAssertFalse(AppRouter.shared.open("javascript:alert(1)"))
        XCTAssertFalse(AppRouter.shared.open(nil))
        // The browser leg of sign-in is never shown in the app, even when a link asks for it.
        XCTAssertFalse(AppRouter.shared.open(AppConfig.urls.origin + "/app/signin/start?challenge=x"))
    }

    func testThePagesColoursAreTheSitesOwn() {
        var white: CGFloat = 0
        ShellViewController.pageColor(dark: false).getWhite(&white, alpha: nil)
        XCTAssertEqual(1, white, accuracy: 0.001)
        var red: CGFloat = 0, green: CGFloat = 0, blue: CGFloat = 0
        ShellViewController.pageColor(dark: true).getRed(&red, green: &green, blue: &blue, alpha: nil)
        XCTAssertEqual(0x0B / 255.0, red, accuracy: 0.002)
        XCTAssertEqual(0x0B / 255.0, green, accuracy: 0.002)
        XCTAssertEqual(0x0F / 255.0, blue, accuracy: 0.002)
    }
}
#endif
