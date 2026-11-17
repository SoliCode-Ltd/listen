import UIKit
import Capacitor

class SceneDelegate: UIResponder, UIWindowSceneDelegate {
    var window: UIWindow?

    /// The shortcuts on the app's icon (press and hold): Info.plist lists them; each opens a
    /// place the site chooses in the member's language (AppShellController::start).
    static let shortcuts: [String: String] = [
        "studio.listenai.app.studio": AppUrls.startPath,
        "studio.listenai.app.voices": AppUrls.startPath + "?to=voices",
        "studio.listenai.app.agents": AppUrls.startPath + "?to=agents"
    ]

    func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions) {
        guard let windowScene = scene as? UIWindowScene else { return }

        // Opened by a link to the site or by a shortcut: that page is the first one.
        if let link = connectionOptions.userActivities.first(where: { $0.activityType == NSUserActivityTypeBrowsingWeb })?.webpageURL {
            AppRouter.shared.open(link.absoluteString)
        } else if let shortcut = connectionOptions.shortcutItem {
            openShortcut(shortcut)
        }

        let shell = ShellViewController()
        AppRouter.shared.attach(shell)
        let window = UIWindow(windowScene: windowScene)
        window.rootViewController = shell
        window.makeKeyAndVisible()
        self.window = window

        SceneDelegateProxy.shared.scene(scene, willConnectTo: session, options: connectionOptions)
    }

    func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
        SceneDelegateProxy.shared.scene(scene, openURLContexts: URLContexts)
    }

    /// A link to the site, tapped anywhere on the phone (the site's
    /// apple-app-site-association file says which links come here).
    func scene(_ scene: UIScene, continue userActivity: NSUserActivity) {
        if userActivity.activityType == NSUserActivityTypeBrowsingWeb, let link = userActivity.webpageURL {
            AppRouter.shared.open(link.absoluteString)
        }
        SceneDelegateProxy.shared.scene(scene, continue: userActivity)
    }

    func windowScene(_ windowScene: UIWindowScene, performActionFor shortcutItem: UIApplicationShortcutItem, completionHandler: @escaping (Bool) -> Void) {
        completionHandler(openShortcut(shortcutItem))
    }

    @discardableResult
    private func openShortcut(_ shortcut: UIApplicationShortcutItem) -> Bool {
        guard let path = SceneDelegate.shortcuts[shortcut.type] else { return false }
        return AppRouter.shared.open(AppConfig.urls.origin + path)
    }
}
