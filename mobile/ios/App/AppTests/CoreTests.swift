import XCTest
#if canImport(App)
@testable import App
#else
@testable import ListenCore
#endif

/// The app's rules that need no phone: which addresses are the site's, safe file names,
/// the sign-in secret and the opening logo's motion. The same cases as the Android app's
/// unit tests, so both apps answer the same.
final class AppUrlsTests: XCTestCase {
    private let urls = AppUrls(origin: "https://listenai.studio")!

    func testAcceptsHttpsOnTheConfiguredHostOnly() {
        XCTAssertTrue(urls.isAppUrl("https://listenai.studio"))
        XCTAssertTrue(urls.isAppUrl("https://listenai.studio/"))
        XCTAssertTrue(urls.isAppUrl("https://listenai.studio/ar/dashboard?tab=1#x"))
        XCTAssertTrue(urls.isAppUrl("HTTPS://LISTENAI.STUDIO/en/demo"))
        XCTAssertTrue(urls.isAppUrl("https://listenai.studio:443/app/start"))
        XCTAssertTrue(urls.isAppUrl(URL(string: "https://listenai.studio/en/agents")))
    }

    func testRefusesEverythingElse() {
        XCTAssertFalse(urls.isAppUrl(nil as String?))
        XCTAssertFalse(urls.isAppUrl(""))
        XCTAssertFalse(urls.isAppUrl("http://listenai.studio/ar/dashboard")) // not https
        XCTAssertFalse(urls.isAppUrl("https://www.listenai.studio/")) // other host
        XCTAssertFalse(urls.isAppUrl("https://staging.listenai.studio/"))
        XCTAssertFalse(urls.isAppUrl("https://listenai.studio.evil.example/"))
        XCTAssertFalse(urls.isAppUrl("https://evil.example/listenai.studio"))
        XCTAssertFalse(urls.isAppUrl("https://listenai.studio@evil.example/")) // user-info trick
        XCTAssertFalse(urls.isAppUrl("https://user:pw@listenai.studio/"))
        XCTAssertFalse(urls.isAppUrl("https://evil.example\\@listenai.studio/")) // backslash trick
        XCTAssertFalse(urls.isAppUrl("https://listenai.studio:8443/")) // other port
        XCTAssertFalse(urls.isAppUrl("https://listenai.studio/a b")) // raw space
        XCTAssertFalse(urls.isAppUrl("javascript:alert(1)"))
        XCTAssertFalse(urls.isAppUrl("file:///data/data/x"))
        XCTAssertFalse(urls.isAppUrl("studio.listenai.app://signin?code=1"))
        XCTAssertFalse(urls.isAppUrl("capacitor://localhost/offline.html"))
        XCTAssertFalse(urls.isAppUrl("//listenai.studio/x"))
    }

    func testResolvesPathsAgainstTheSite() {
        XCTAssertEqual("https://listenai.studio/audio/5/download", urls.resolve("/audio/5/download"))
        XCTAssertEqual("https://listenai.studio/x?y=1", urls.resolve(" https://listenai.studio/x?y=1 "))
        XCTAssertNil(urls.resolve("//evil.example/x")) // protocol-relative
        XCTAssertNil(urls.resolve("https://evil.example/x"))
        XCTAssertNil(urls.resolve("audio/5"))
        XCTAssertNil(urls.resolve(""))
        XCTAssertNil(urls.resolve(nil))
    }

    func testExtractsPathAndQuery() {
        XCTAssertEqual("/", urls.pathAndQuery("https://listenai.studio"))
        XCTAssertEqual("/ar/dashboard?tab=voices#top", urls.pathAndQuery("https://listenai.studio/ar/dashboard?tab=voices#top"))
        XCTAssertEqual("/app/signin/complete?code=a%2Bb&verifier=v", urls.pathAndQuery("https://listenai.studio/app/signin/complete?code=a%2Bb&verifier=v"))
    }

    func testBuildsTheFixedAppRoutes() {
        XCTAssertEqual("https://listenai.studio/app/start", urls.startUrl)
        // The sheet is told that the iPhone app opened it.
        XCTAssertEqual("https://listenai.studio/app/signin/start?challenge=abc-_1&locale=ar&platform=ios", urls.signInStartUrl(challenge: "abc-_1", locale: "ar"))
        XCTAssertEqual("https://listenai.studio/app/signin/start?challenge=c&locale=en&platform=ios", urls.signInStartUrl(challenge: "c", locale: "fr"))
        // The code comes from outside the app: it must not be able to add parameters.
        XCTAssertEqual(
            "https://listenai.studio/app/signin/complete?code=a%26verifier%3Dx%23&verifier=v1",
            urls.signInCompleteUrl(code: "a&verifier=x#", verifier: "v1")
        )
    }

    func testKnowsTheBrowserLegOfSignIn() {
        XCTAssertTrue(urls.isSignInUrl("https://listenai.studio/app/signin/start?challenge=x"))
        XCTAssertTrue(urls.isSignInUrl("https://listenai.studio/app/signin/complete?code=x"))
        XCTAssertFalse(urls.isSignInUrl("https://listenai.studio/app/start"))
        XCTAssertFalse(urls.isSignInUrl("https://listenai.studio/ar/login"))
    }

    func testKnowsTheRetryAddressOfTheNoConnectionPage() {
        XCTAssertTrue(AppUrls.isRetryUrl(URL(string: "capacitor://localhost/__retry")))
        XCTAssertFalse(AppUrls.isRetryUrl(URL(string: "capacitor://localhost/offline.html")))
        XCTAssertFalse(AppUrls.isRetryUrl(nil))
    }

    func testWorksForAStagingHost() {
        let staging = AppUrls(origin: "https://staging.example.test/")!
        XCTAssertEqual("staging.example.test", staging.host)
        XCTAssertEqual("https://staging.example.test/app/start", staging.startUrl)
        XCTAssertTrue(staging.isAppUrl("https://staging.example.test/en/dashboard"))
        XCTAssertFalse(staging.isAppUrl("https://listenai.studio/en/dashboard"))
    }

    func testRefusesASiteThatIsNotPlainHttps() {
        XCTAssertNil(AppUrls(origin: "http://listenai.studio"))
        XCTAssertNil(AppUrls(origin: "https://listenai.studio/somewhere"))
        XCTAssertNil(AppUrls(origin: "https://user@listenai.studio"))
        XCTAssertNil(AppUrls(origin: ""))
    }

    func testNormalisesLocales() {
        XCTAssertEqual("ar", AppUrls.normalizeLocale("ar"))
        XCTAssertEqual("ar", AppUrls.normalizeLocale("AR-sa"))
        XCTAssertEqual("en", AppUrls.normalizeLocale("en"))
        XCTAssertEqual("en", AppUrls.normalizeLocale(nil))
        XCTAssertEqual("en", AppUrls.normalizeLocale("de"))
    }
}

final class FileNamesTests: XCTestCase {
    func testKeepsAGoodName() {
        XCTAssertEqual("my-voice_01.mp3", FileNames.sanitize("my-voice_01.mp3", mimeType: "audio/mpeg"))
        XCTAssertEqual("Take (2).wav", FileNames.sanitize("Take (2).wav", mimeType: "audio/wav"))
    }

    func testKeepsArabicLetters() {
        XCTAssertEqual("صوتي الجديد.mp3", FileNames.sanitize("صوتي الجديد.mp3", mimeType: "audio/mpeg"))
        XCTAssertEqual("مرحبا.mp3", FileNames.sanitize("مرحبا", mimeType: "audio/mpeg"))
        XCTAssertEqual("إعلان ١٢.wav", FileNames.sanitize("  إعلان   ١٢  ", mimeType: "audio/x-wav"))
    }

    func testRemovesFolders() {
        XCTAssertEqual("passwd.mp3", FileNames.sanitize("../../etc/passwd", mimeType: "audio/mpeg"))
        XCTAssertEqual("b.mp3", FileNames.sanitize("C:\\a\\b.mp3", mimeType: "audio/mpeg"))
        XCTAssertEqual("c.mp3", FileNames.sanitize("/a/b/c.mp3", mimeType: "audio/mpeg"))
        XCTAssertEqual("listen-audio.mp3", FileNames.sanitize("a/b/", mimeType: "audio/mpeg"))
    }

    func testRemovesCharactersFileSystemsRefuse() {
        XCTAssertEqual("a_b_c_d_.mp3", FileNames.sanitize("a:b*c?d|.mp3", mimeType: "audio/mpeg"))
        XCTAssertEqual("a b.mp3", FileNames.sanitize("a\tb\u{0000}\n.mp3", mimeType: "audio/mpeg"))
        XCTAssertEqual("_x_.mp3", FileNames.sanitize("<x>.mp3", mimeType: "audio/mpeg"))
    }

    func testRemovesInvisibleDirectionMarks() {
        // A right-to-left override can make "gpm.exe" look like "exe.mp3" on screen.
        XCTAssertEqual("evil3pm.exe.mp3", FileNames.sanitize("evil\u{202E}3pm.exe", mimeType: "audio/mpeg"))
        XCTAssertEqual("ab.mp3", FileNames.sanitize("a\u{200B}b\u{200F}.mp3", mimeType: "audio/mpeg"))
    }

    func testNeverMakesAHiddenOrEmptyName() {
        XCTAssertEqual("listen-audio.mp3", FileNames.sanitize(nil, mimeType: "audio/mpeg"))
        XCTAssertEqual("listen-audio.mp3", FileNames.sanitize("", mimeType: "audio/mpeg"))
        XCTAssertEqual("listen-audio.mp3", FileNames.sanitize("...", mimeType: "audio/mpeg"))
        XCTAssertEqual("listen-audio.mp3", FileNames.sanitize(".mp3", mimeType: "audio/mpeg"))
        XCTAssertEqual("htaccess.mp3", FileNames.sanitize(".htaccess", mimeType: "audio/mpeg"))
    }

    func testMakesTheExtensionMatchTheType() {
        XCTAssertEqual("voice.mp3", FileNames.sanitize("voice", mimeType: "audio/mpeg"))
        XCTAssertEqual("voice.mp3", FileNames.sanitize("voice.MP3", mimeType: "audio/mpeg"))
        XCTAssertEqual("voice.wav.mp3", FileNames.sanitize("voice.wav", mimeType: "audio/mpeg"))
        XCTAssertEqual("voice.exe.mp3", FileNames.sanitize("voice.exe", mimeType: "audio/mpeg"))
        XCTAssertEqual("voice.m4a", FileNames.sanitize("voice.m4a", mimeType: "audio/mp4"))
        XCTAssertEqual("voice.m4a", FileNames.sanitize("voice", mimeType: "audio/mp4; codecs=mp4a.40.2"))
        XCTAssertEqual("clip.mp4", FileNames.sanitize("clip.mp4", mimeType: "video/mp4"))
        XCTAssertEqual("clip.webm", FileNames.sanitize("clip", mimeType: "VIDEO/WEBM"))
        XCTAssertEqual("v1.2 final.mp3", FileNames.sanitize("v1.2 final", mimeType: "audio/mpeg"))
    }

    func testHandlesTypesItDoesNotKnow() {
        XCTAssertEqual("notes.srt", FileNames.sanitize("notes.srt", mimeType: "application/x-subrip"))
        XCTAssertEqual("notes.bin", FileNames.sanitize("notes", mimeType: "application/octet-stream"))
    }

    func testLimitsTheLength() {
        let longName = String(repeating: "ص", count: 300)
        let result = FileNames.sanitize(longName + ".mp3", mimeType: "audio/mpeg")
        XCTAssertEqual(80 + ".mp3".count, result.unicodeScalars.count)
        XCTAssertTrue(result.hasSuffix(".mp3"))
        // 80 Arabic letters are 160 bytes in UTF-8: well under the 255-byte limit of phone storage.
        XCTAssertLessThan(result.utf8.count, 255)
    }

    func testDoesNotCutAnEmojiInHalf() {
        let name = String(repeating: "\u{1F3A4}", count: 100) // microphone emoji
        let result = FileNames.sanitize(name, mimeType: "audio/mpeg")
        let base = String(result.dropLast(4))
        XCTAssertEqual(80, base.unicodeScalars.count)
        XCTAssertEqual(String(repeating: "\u{1F3A4}", count: 80), base)
    }

    func testRecognisesMediaTypes() {
        XCTAssertTrue(FileNames.isMediaType("audio/mpeg"))
        XCTAssertTrue(FileNames.isMediaType("Audio/WAV"))
        XCTAssertTrue(FileNames.isMediaType("video/mp4; codecs=avc1"))
        XCTAssertFalse(FileNames.isMediaType("text/html"))
        XCTAssertFalse(FileNames.isMediaType("application/vnd.android.package-archive"))
        XCTAssertFalse(FileNames.isMediaType("audio/"))
        XCTAssertFalse(FileNames.isMediaType(nil))
        XCTAssertEqual("mp3", FileNames.extensionFor("audio/mpeg"))
        XCTAssertEqual("wav", FileNames.extensionFor("audio/x-wav"))
        XCTAssertNil(FileNames.extensionFor("text/plain"))
    }
}

/// The sign-in secret: verifier and its SHA-256 challenge, both base64url without padding.
final class PkceTests: XCTestCase {
    #if canImport(CryptoKit)
    func testChallengeMatchesThePublishedExample() {
        // The worked example from RFC 7636, appendix B.
        XCTAssertEqual("E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM", Pkce.challenge("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"))
    }
    #endif

    func testVerifierIs32RandomBytesInBase64Url() {
        let verifier = Pkce.newVerifier()
        XCTAssertEqual(43, verifier.count) // 32 bytes, no padding
        XCTAssertNotNil(verifier.range(of: "^[A-Za-z0-9_-]{43}$", options: .regularExpression))
        XCTAssertNotEqual(verifier, Pkce.newVerifier())
    }

    func testBase64UrlMatchesTheStandardTestVectors() {
        // RFC 4648 section 10, minus the "=" padding.
        let encode = { (text: String) in Pkce.base64Url(Data(text.utf8)) }
        XCTAssertEqual("", encode(""))
        XCTAssertEqual("Zg", encode("f"))
        XCTAssertEqual("Zm8", encode("fo"))
        XCTAssertEqual("Zm9v", encode("foo"))
        XCTAssertEqual("Zm9vYg", encode("foob"))
        XCTAssertEqual("Zm9vYmE", encode("fooba"))
        XCTAssertEqual("Zm9vYmFy", encode("foobar"))
        // "-" and "_" instead of "+" and "/".
        XCTAssertEqual("-_-_", Pkce.base64Url(Data([0xfb, 0xff, 0xbf])))
    }
}

final class LaunchWaveTests: XCTestCase {
    func testTheFirstFrameIsTheStillLogoOfTheLaunchScreen() {
        for bar in 0..<7 {
            XCTAssertEqual(1, LaunchWave.scale(bar: bar, elapsedMs: 0), accuracy: 0.0001, "bar \(bar)")
        }
    }

    func testABarNeverGrowsPastItsStillHeightNorShrinksToNothing() {
        for bar in 0..<7 {
            for ms in stride(from: 0.0, to: 6000.0, by: 7.0) {
                let scale = LaunchWave.scale(bar: bar, elapsedMs: ms)
                XCTAssertTrue(scale <= 1.0001 && scale >= 0.5, "bar \(bar) at \(ms) is \(scale)")
            }
        }
    }

    func testTheSwellIsTheSameOnBothSidesOfTheCentre() {
        for ms in stride(from: 0.0, to: 3000.0, by: 37.0) {
            XCTAssertEqual(LaunchWave.scale(bar: 0, elapsedMs: ms), LaunchWave.scale(bar: 6, elapsedMs: ms), accuracy: 0.0001)
            XCTAssertEqual(LaunchWave.scale(bar: 2, elapsedMs: ms), LaunchWave.scale(bar: 4, elapsedMs: ms), accuracy: 0.0001)
        }
    }

    func testTheLogoMovesOnceItHasEasedIn() {
        var low = 1.0
        var high = 0.0
        for ms in stride(from: 500.0, to: 2000.0, by: 5.0) {
            let scale = LaunchWave.scale(bar: 3, elapsedMs: ms)
            low = min(low, scale)
            high = max(high, scale)
        }
        XCTAssertGreaterThan(high - low, 0.4, "the centre bar swells and sinks")
    }
}
