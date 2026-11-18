import UIKit
import WebKit
import Network

/// The one screen of the app: the web view showing the live site, and the native shell
/// around it (the opening screen, the bars' colours, pull to refresh, "can't connect",
/// links into the app). The counterpart of the Android app's `MainActivity`.
final class ShellViewController: UIViewController {
    /// The opening screen never stays longer than this, whatever the network does.
    private static let splashMax: TimeInterval = 6.0
    /// Nor shorter than this, so the logo is seen to move and does not just blink.
    private static let splashMin: TimeInterval = 0.7
    private static let splashFade: TimeInterval = 0.24
    private static let refreshSpinnerMax: TimeInterval = 12.0
    private static let themeKey = "site_theme"

    private let bridgeController = ListenBridgeViewController()
    private var wave: LaunchWaveView?
    private var waveShownAt = Date()
    private var offline: OfflineView?
    private let refresh = UIRefreshControl()
    private var pullToRefresh = false
    private let network = NWPathMonitor()
    private var online = true
    /// The page that could not be loaded, to be tried again.
    private var failedUrl: URL?
    private var dark = false

    var webView: WKWebView? {
        bridgeController.webView
    }

    // MARK: - Start-up

    override func viewDidLoad() {
        super.viewDidLoad()

        dark = ShellViewController.rememberedTheme() ?? (traitCollection.userInterfaceStyle == .dark)
        view.backgroundColor = ShellViewController.pageColor(dark: dark)

        // The web view sits inside the safe area: no page is ever under the notch, the
        // Dynamic Island or the home indicator, and what shows behind them is this screen's
        // background, in the page's colour.
        bridgeController.attach(self)
        addChild(bridgeController)
        let web: UIView = bridgeController.view
        web.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(web)
        NSLayoutConstraint.activate([
            web.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
            web.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor),
            web.leadingAnchor.constraint(equalTo: view.safeAreaLayoutGuide.leadingAnchor),
            web.trailingAnchor.constraint(equalTo: view.safeAreaLayoutGuide.trailingAnchor)
        ])
        bridgeController.didMove(toParent: self)
        bridgeController.attach(self)

        refresh.addTarget(self, action: #selector(refreshPulled), for: .valueChanged)
        applyTheme()
        showWave()
        ShellViewController.note("launched \(AppConfig.userAgentSuffix) \(AppConfig.urls.origin) lang=\(AppConfig.deviceLang) dark=\(dark)")

        // Opened by a link, a notification or a shortcut: that page instead of the first one.
        if let first = AppRouter.shared.takeWaiting() {
            load(first)
        }

        network.pathUpdateHandler = { [weak self] path in
            DispatchQueue.main.async { self?.networkChanged(online: path.status == .satisfied) }
        }
        network.start(queue: DispatchQueue.global(qos: .utility))
    }

    deinit {
        network.cancel()
    }

    func load(_ url: URL) {
        guard AppConfig.urls.isAppUrl(url) else { return }
        ShellViewController.note("load \(url.absoluteString)")
        _ = webView?.load(URLRequest(url: url))
    }

    // MARK: - Page events (from WebViewDelegates)

    func pageStarted() {
        // Everything a page switched on belongs to that page.
        setPullToRefresh(false)
        ShellViewController.note("started \(webView?.url?.absoluteString ?? "-")")
    }

    func pageFinished() {
        ShellViewController.note("finished \(webView?.url?.absoluteString ?? "-")")
        refresh.endRefreshing()
        failedUrl = nil
        hideOffline()
        hideWave()
    }

    func pageFailed(_ url: URL?, reason: String = "") {
        ShellViewController.note("failed \(url?.absoluteString ?? "-") \(reason)")
        refresh.endRefreshing()
        failedUrl = url.flatMap { AppConfig.urls.isAppUrl($0) ? $0 : nil } ?? failedUrl
        showOffline()
        hideWave()
    }

    // MARK: - Opening screen

    /// Covers the whole screen, bars included, with the moving logo on white.
    private func showWave() {
        let wave = LaunchWaveView(frame: view.bounds)
        wave.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        view.addSubview(wave)
        self.wave = wave
        waveShownAt = Date()
        setNeedsStatusBarAppearanceUpdate()
        DispatchQueue.main.asyncAfter(deadline: .now() + ShellViewController.splashMax) { [weak self] in
            self?.hideWave()
        }
    }

    /// Fades the opening screen away over the page (or over "can't connect").
    private func hideWave() {
        guard let wave = wave else { return }
        let shown = Date().timeIntervalSince(waveShownAt)
        if shown < ShellViewController.splashMin {
            DispatchQueue.main.asyncAfter(deadline: .now() + (ShellViewController.splashMin - shown)) { [weak self] in
                self?.hideWave()
            }
            return
        }
        self.wave = nil
        wave.dismiss(duration: ShellViewController.splashFade) { [weak self] in
            // The status bar takes the page's own colours again as the white leaves.
            self?.setNeedsStatusBarAppearanceUpdate()
        }
        setNeedsStatusBarAppearanceUpdate()
    }

    // MARK: - "Can't connect"

    private func showOffline() {
        if let offline = offline {
            offline.setTrying(false)
            return
        }
        let offline = OfflineView()
        offline.translatesAutoresizingMaskIntoConstraints = false
        offline.apply(dark: dark)
        offline.onRetry = { [weak self] in self?.retry() }
        if let wave = wave {
            view.insertSubview(offline, belowSubview: wave)
        } else {
            view.addSubview(offline)
        }
        NSLayoutConstraint.activate([
            offline.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
            offline.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor),
            offline.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            offline.trailingAnchor.constraint(equalTo: view.trailingAnchor)
        ])
        self.offline = offline
        UIAccessibility.post(notification: .screenChanged, argument: offline)
    }

    private func hideOffline() {
        offline?.removeFromSuperview()
        offline = nil
    }

    /// Loads the page that failed again (or the first page).
    private func retry() {
        guard let offline = offline else { return }
        offline.setTrying(true)
        let url = failedUrl ?? URL(string: AppConfig.urls.startUrl)
        if let url = url {
            load(url)
        }
        // If nothing comes of it, the person can press again.
        DispatchQueue.main.asyncAfter(deadline: .now() + 8) { [weak self] in
            self?.offline?.setTrying(false)
        }
    }

    private func networkChanged(online: Bool) {
        let wasOnline = self.online
        self.online = online
        if online && !wasOnline && offline != nil {
            // Give the connection a moment to become usable.
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.6) { [weak self] in
                self?.retry()
            }
        }
    }

    // MARK: - Theme and bars

    static func pageColor(dark: Bool) -> UIColor {
        // The site's own page colours (--bg): #0B0B0F and #FFFFFF.
        dark ? UIColor(red: 0x0B / 255.0, green: 0x0B / 255.0, blue: 0x0F / 255.0, alpha: 1) : .white
    }

    private static func rememberedTheme() -> Bool? {
        switch UserDefaults.standard.string(forKey: themeKey) {
        case "dark": return true
        case "light": return false
        default: return nil
        }
    }

    /// From the page's theme switch (ListenShell.setTheme). Remembered, so the next start has no flash.
    func setTheme(dark: Bool) {
        UserDefaults.standard.set(dark ? "dark" : "light", forKey: ShellViewController.themeKey)
        guard dark != self.dark else { return }
        self.dark = dark
        applyTheme()
    }

    private func applyTheme() {
        let color = ShellViewController.pageColor(dark: dark)
        view.backgroundColor = color
        webView?.backgroundColor = color
        webView?.scrollView.backgroundColor = color
        // Keyboards, alerts and the page's own "prefers dark" follow the page's choice.
        overrideUserInterfaceStyle = dark ? .dark : .light
        refresh.tintColor = dark ? .white : .gray
        offline?.apply(dark: dark)
        setNeedsStatusBarAppearanceUpdate()
    }

    override var preferredStatusBarStyle: UIStatusBarStyle {
        // The opening screen is white whatever the theme, so the bar's text is dark while it shows.
        if wave != nil { return .darkContent }
        return dark ? .lightContent : .darkContent
    }

    override var supportedInterfaceOrientations: UIInterfaceOrientationMask {
        .portrait
    }

    // MARK: - Pull to refresh

    /// Off unless the current page asked for it (ListenShell.setPullToRefresh).
    func setPullToRefresh(_ enabled: Bool) {
        guard enabled != pullToRefresh, let scrollView = webView?.scrollView else { return }
        pullToRefresh = enabled
        scrollView.bounces = enabled
        scrollView.alwaysBounceVertical = enabled
        scrollView.refreshControl = enabled ? refresh : nil
    }

    @objc private func refreshPulled() {
        webView?.reload()
        DispatchQueue.main.asyncAfter(deadline: .now() + ShellViewController.refreshSpinnerMax) { [weak self] in
            self?.refresh.endRefreshing()
        }
    }

    // MARK: - A record for the build machine

    /// Debug builds only: what happened to each page, written where the simulator run on the
    /// build machine can read it (ci/simulator-shots.sh). A released app writes nothing.
    static func note(_ line: String) {
        #if DEBUG
        guard let folder = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).first else { return }
        let file = folder.appendingPathComponent("listen-debug.log")
        let text = String(format: "%.1f ", Date().timeIntervalSince1970) + line + "\n"
        guard let data = text.data(using: .utf8) else { return }
        if let handle = try? FileHandle(forWritingTo: file) {
            handle.seekToEndOfFile()
            handle.write(data)
            handle.closeFile()
        } else {
            try? data.write(to: file)
        }
        #endif
    }

    // MARK: - Files

    /// A link the web view cannot show (a file download). Pages use ListenShell.saveAudio;
    /// this is the safety net for ordinary download links on the site.
    func saveDownload(from url: URL, suggestedName: String?, mimeType: String?) {
        let type = FileNames.isMediaType(mimeType) ? FileNames.cleanMimeType(mimeType) : "application/octet-stream"
        let request = AudioFiles.Request(url: url.absoluteString, base64: nil, fileName: FileNames.sanitize(suggestedName, mimeType: type), mimeType: type)
        AudioFiles.shared.prepare(request, webView: webView) { [weak self] result in
            guard let self = self, case .success(let file) = result else { return }
            AudioFiles.shared.presentSave(file, from: self) { _ in }
        }
    }
}
