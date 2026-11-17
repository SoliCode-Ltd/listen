import UIKit
import AVFoundation
import UserNotifications
import Capacitor

/// "ListenShell": everything the site's pages can ask the app to do.
/// The contract (methods, payloads, events) is written up in mobile/BRIDGE.md and is the
/// Android app's; keep this file in step with it and with `ListenShellPlugin.java`.
///
/// What differs on an iPhone, by design:
/// - The lock-screen player is the system's own for a web page's audio (the page tells it
///   the title and position through the Media Session API in listen-app.js), so
///   `mediaUpdate` and `mediaStop` have nothing to draw and `mediaAction` is not sent.
/// - There is no back button: `setBackHandler` and `minimize` do nothing.
/// - "Save" lets the person choose where the file goes.
@objc(ListenShellPlugin)
public class ListenShellPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "ListenShellPlugin"
    public let jsName = "ListenShell"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "getInfo", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "mediaUpdate", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "mediaStop", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "saveAudio", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "shareAudio", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "ensureMic", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "ensureNotifications", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "signIn", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "signInWithApple", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "setPullToRefresh", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "setBackHandler", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "minimize", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "openExternal", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "setTheme", returnType: CAPPluginReturnPromise)
    ]

    private var shell: ShellViewController? {
        (bridge?.viewController as? ListenBridgeViewController)?.shell
    }

    // MARK: - 1. getInfo

    @objc func getInfo(_ call: CAPPluginCall) {
        UNUserNotificationCenter.current().getNotificationSettings { settings in
            call.resolve([
                "version": AppConfig.version,
                "build": AppConfig.build,
                "platform": "ios",
                "pushAvailable": AppConfig.pushAvailable,
                "notificationsAllowed": ListenShellPlugin.allows(settings),
                "micPermission": ListenShellPlugin.micState()
            ])
        }
    }

    private static func allows(_ settings: UNNotificationSettings) -> Bool {
        switch settings.authorizationStatus {
        case .authorized, .provisional, .ephemeral: return true
        default: return false
        }
    }

    /// 'granted', 'denied' (only the phone's Settings can change it) or 'prompt'.
    private static func micState() -> String {
        switch AVCaptureDevice.authorizationStatus(for: .audio) {
        case .authorized: return "granted"
        case .notDetermined: return "prompt"
        default: return "denied"
        }
    }

    // MARK: - 2. The lock-screen player

    @objc func mediaUpdate(_ call: CAPPluginCall) {
        // The system shows the player for the page's own audio; nothing to draw here.
        call.resolve(["active": true])
    }

    @objc func mediaStop(_ call: CAPPluginCall) {
        call.resolve()
    }

    // MARK: - 3. saveAudio, 4. shareAudio

    @objc func saveAudio(_ call: CAPPluginCall) {
        prepareFile(call) { file, shell in
            AudioFiles.shared.presentSave(file, from: shell) { result in
                switch result {
                case .success(let saved):
                    call.resolve(["uri": saved.absoluteString, "displayName": saved.lastPathComponent])
                case .failure(let failure):
                    call.reject(failure.message, failure.code)
                }
            }
        }
    }

    @objc func shareAudio(_ call: CAPPluginCall) {
        prepareFile(call) { file, shell in
            AudioFiles.shared.presentShare(file, from: shell) {
                call.resolve()
            }
        }
    }

    private func prepareFile(_ call: CAPPluginCall, then present: @escaping (URL, ShellViewController) -> Void) {
        let checked = AudioFiles.request(
            url: call.getString("url"),
            base64: call.getString("base64"),
            fileName: call.getString("fileName"),
            mimeType: call.getString("mimeType"),
            urls: AppConfig.urls
        )
        guard case .success(let request) = checked else {
            if case .failure(let failure) = checked { call.reject(failure.message, failure.code) }
            return
        }
        DispatchQueue.main.async {
            guard let shell = self.shell else {
                call.reject("The app is not ready", "io_error")
                return
            }
            AudioFiles.shared.prepare(request, webView: shell.webView) { result in
                switch result {
                case .success(let file):
                    present(file, shell)
                case .failure(let failure):
                    call.reject(failure.message, failure.code)
                }
            }
        }
    }

    // MARK: - 5. ensureMic

    @objc func ensureMic(_ call: CAPPluginCall) {
        let lang = AppUrls.normalizeLocale(call.getString("lang"))
        switch AVCaptureDevice.authorizationStatus(for: .audio) {
        case .authorized:
            call.resolve(["granted": true])
        case .notDetermined:
            // The phone's own question, with the reason from Info.plist.
            AVCaptureDevice.requestAccess(for: .audio) { granted in
                call.resolve(["granted": granted])
            }
        default:
            // Refused before: the phone does not ask twice. Only its Settings can turn it on.
            offerSettings(titleKey: "mic_settings_title", messageKey: "mic_settings_message", lang: lang) {
                call.resolve(["granted": false])
            }
        }
    }

    // MARK: - 6. ensureNotifications

    @objc func ensureNotifications(_ call: CAPPluginCall) {
        let lang = AppUrls.normalizeLocale(call.getString("lang"))
        let center = UNUserNotificationCenter.current()
        center.getNotificationSettings { settings in
            if ListenShellPlugin.allows(settings) {
                call.resolve(["granted": true])
            } else if settings.authorizationStatus == .notDetermined {
                center.requestAuthorization(options: [.alert, .sound, .badge]) { granted, _ in
                    call.resolve(["granted": granted])
                }
            } else {
                self.offerSettings(titleKey: "notif_settings_title", messageKey: "notif_settings_message", lang: lang) {
                    call.resolve(["granted": false])
                }
            }
        }
    }

    /// "It is turned off; open Settings?" in the page's language. `done` runs either way.
    private func offerSettings(titleKey: String, messageKey: String, lang: String, done: @escaping () -> Void) {
        DispatchQueue.main.async {
            guard let presenter = self.bridge?.viewController else {
                done()
                return
            }
            let alert = UIAlertController(
                title: AppConfig.text(titleKey, lang: lang),
                message: AppConfig.text(messageKey, lang: lang),
                preferredStyle: .alert
            )
            alert.addAction(UIAlertAction(title: AppConfig.text("action_not_now", lang: lang), style: .cancel) { _ in done() })
            alert.addAction(UIAlertAction(title: AppConfig.text("action_open_settings", lang: lang), style: .default) { _ in
                if let settings = URL(string: UIApplication.openSettingsURLString) {
                    UIApplication.shared.open(settings, options: [:], completionHandler: nil)
                }
                done()
            })
            var top = presenter
            while let next = top.presentedViewController, !next.isBeingDismissed {
                top = next
            }
            top.present(alert, animated: true)
        }
    }

    // MARK: - 7. signIn

    @objc func signIn(_ call: CAPPluginCall) {
        let locale = call.getString("locale")
        DispatchQueue.main.async {
            if SignIn.shared.start(locale: locale, in: self.bridge?.viewController?.view.window) {
                call.resolve()
            } else {
                call.reject("The sign-in sheet could not be opened", "no_browser")
            }
        }
    }

    /// iPhone only: "Sign in with Apple". Resolves with Apple's signed token for the page to
    /// pass to the site; rejects with the code "cancelled" when the sheet is closed.
    @objc func signInWithApple(_ call: CAPPluginCall) {
        let nonce = call.getString("nonce") ?? ""
        DispatchQueue.main.async {
            AppleSignIn.shared.start(nonce: nonce, in: self.bridge?.viewController?.view.window) { result in
                switch result {
                case .success(let answer):
                    var data: [String: Any] = ["identityToken": answer.identityToken]
                    if let code = answer.authorizationCode { data["authorizationCode"] = code }
                    if let name = answer.givenName { data["givenName"] = name }
                    if let name = answer.familyName { data["familyName"] = name }
                    call.resolve(data)
                case .failure(let failure):
                    call.reject(failure.message, failure.code)
                }
            }
        }
    }

    // MARK: - 8. to 12. The shell around the page

    @objc func setPullToRefresh(_ call: CAPPluginCall) {
        let enabled = call.getBool("enabled") ?? false
        DispatchQueue.main.async {
            self.shell?.setPullToRefresh(enabled)
            call.resolve()
        }
    }

    @objc func setBackHandler(_ call: CAPPluginCall) {
        call.resolve()
    }

    @objc func minimize(_ call: CAPPluginCall) {
        call.resolve()
    }

    @objc func openExternal(_ call: CAPPluginCall) {
        guard let address = call.getString("url"), let url = URL(string: address),
              ["https", "http", "mailto", "tel"].contains((url.scheme ?? "").lowercased()) else {
            call.reject("Only https, http, mailto and tel links can be opened", "bad_request")
            return
        }
        DispatchQueue.main.async {
            UIApplication.shared.open(url, options: [:]) { opened in
                if opened {
                    call.resolve()
                } else {
                    call.reject("Nothing on this phone can open the link", "no_browser")
                }
            }
        }
    }

    @objc func setTheme(_ call: CAPPluginCall) {
        let dark = call.getBool("dark") ?? false
        DispatchQueue.main.async {
            self.shell?.setTheme(dark: dark)
            call.resolve()
        }
    }
}
