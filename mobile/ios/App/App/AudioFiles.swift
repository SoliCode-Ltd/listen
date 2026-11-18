import UIKit
import WebKit

/// Getting a sound or video file ready on the phone and handing it over: "Save" lets the
/// person choose where it goes (the Files sheet), "Share" opens the share sheet.
///
/// The file comes either from the site (downloaded here with the member's own session, so
/// only a signed-in member gets their file) or from the page itself as base64.
/// The counterpart of the Android app's `AudioFiles`; the error codes are the same
/// (mobile/BRIDGE.md).
final class AudioFiles: NSObject {
    static let shared = AudioFiles()

    /// Larger files are refused.
    static let maxBytes: Int64 = 300 * 1024 * 1024

    struct Request {
        let url: String?
        let base64: String?
        let fileName: String
        let mimeType: String
    }

    struct Failure: Error {
        let code: String
        let message: String
    }

    private var downloads: [Int: Download] = [:]
    private var pickers: [ObjectIdentifier: SavePicker] = [:]
    private lazy var session: URLSession = {
        let configuration = URLSessionConfiguration.ephemeral
        // The member's cookies are passed by hand for the site only; nothing is kept here.
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        configuration.urlCache = nil
        configuration.timeoutIntervalForRequest = 60
        return URLSession(configuration: configuration, delegate: self, delegateQueue: .main)
    }()

    // MARK: - Checking what a page asked for

    /// The arguments saveAudio and shareAudio have in common, checked once.
    static func request(url: String?, base64: String?, fileName: String?, mimeType: String?, urls: AppUrls) -> Result<Request, Failure> {
        let type = FileNames.cleanMimeType(mimeType)
        let hasUrl = !(url ?? "").isEmpty
        let hasData = !(base64 ?? "").isEmpty
        if hasUrl == hasData {
            return .failure(Failure(code: "bad_request", message: "Pass exactly one of url or base64"))
        }
        guard FileNames.isMediaType(type) else {
            return .failure(Failure(code: "bad_request", message: "mimeType must be an audio/* or video/* type"))
        }
        var resolved: String?
        if hasUrl {
            resolved = urls.resolve(url)
            if resolved == nil {
                return .failure(Failure(code: "url_not_allowed", message: "Only https URLs on \(urls.host) can be downloaded"))
            }
        }
        return .success(Request(url: resolved, base64: hasUrl ? nil : base64, fileName: FileNames.sanitize(fileName, mimeType: type), mimeType: type))
    }

    // MARK: - Getting the file ready

    private static var folder: URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("ListenFiles", isDirectory: true)
    }

    /// Files made ready in an earlier run are no longer needed.
    static func clearTemporaryFiles() {
        try? FileManager.default.removeItem(at: folder)
    }

    private static func place(for fileName: String) throws -> URL {
        let directory = folder.appendingPathComponent(UUID().uuidString, isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory.appendingPathComponent(fileName)
    }

    /// Writes the file into the app's temporary folder under its clean name; `completion`
    /// is called on the main thread.
    func prepare(_ request: Request, webView: WKWebView?, completion: @escaping (Result<URL, Failure>) -> Void) {
        if let base64 = request.base64 {
            DispatchQueue.global(qos: .userInitiated).async {
                let result = AudioFiles.write(base64: base64, fileName: request.fileName)
                DispatchQueue.main.async { completion(result) }
            }
            return
        }
        guard let address = request.url, let url = URL(string: address), let webView = webView else {
            completion(.failure(Failure(code: "bad_request", message: "Nothing to download")))
            return
        }
        // The member's session lives in the web view's cookies, and the site knows the app by
        // its user agent: the download carries both, so it is the same member asking.
        webView.configuration.websiteDataStore.httpCookieStore.getAllCookies { cookies in
            webView.evaluateJavaScript("navigator.userAgent") { value, _ in
                let userAgent = (value as? String) ?? AppConfig.userAgentSuffix
                self.download(url, fileName: request.fileName, cookies: cookies, userAgent: userAgent, completion: completion)
            }
        }
    }

    private static func write(base64: String, fileName: String) -> Result<URL, Failure> {
        // Bare base64 or a full "data:" URL.
        var text = base64
        if text.hasPrefix("data:"), let comma = text.firstIndex(of: ",") {
            text = String(text[text.index(after: comma)...])
        }
        guard let data = Data(base64Encoded: text, options: .ignoreUnknownCharacters) else {
            return .failure(Failure(code: "bad_data", message: "The data is not valid base64"))
        }
        if data.isEmpty {
            return .failure(Failure(code: "empty", message: "The file is empty"))
        }
        if Int64(data.count) > maxBytes {
            return .failure(Failure(code: "too_large", message: "The file is too large"))
        }
        do {
            let file = try place(for: fileName)
            try data.write(to: file, options: .atomic)
            return .success(file)
        } catch {
            return .failure(Failure(code: "io_error", message: error.localizedDescription))
        }
    }

    // MARK: - Downloading from the site

    private final class Download {
        let fileName: String
        let cookieHeader: String?
        let userAgent: String
        let completion: (Result<URL, Failure>) -> Void
        var failure: Failure?
        var saved: URL?

        init(fileName: String, cookieHeader: String?, userAgent: String, completion: @escaping (Result<URL, Failure>) -> Void) {
            self.fileName = fileName
            self.cookieHeader = cookieHeader
            self.userAgent = userAgent
            self.completion = completion
        }
    }

    private static func cookieHeader(for url: URL, from cookies: [HTTPCookie]) -> String? {
        guard let host = url.host?.lowercased() else { return nil }
        let now = Date()
        let matching = cookies.filter { cookie in
            let domain = cookie.domain.lowercased()
            let bare = domain.hasPrefix(".") ? String(domain.dropFirst()) : domain
            let onHost = host == bare || (domain.hasPrefix(".") && host.hasSuffix("." + bare))
            let onPath = url.path.hasPrefix(cookie.path) || cookie.path == "/"
            let alive = cookie.expiresDate.map { $0 > now } ?? true
            return onHost && onPath && alive
        }
        return HTTPCookie.requestHeaderFields(with: matching)["Cookie"]
    }

    private func signed(_ request: URLRequest, for download: Download) -> URLRequest {
        var request = request
        request.setValue(download.userAgent, forHTTPHeaderField: "User-Agent")
        request.setValue(download.cookieHeader, forHTTPHeaderField: "Cookie")
        return request
    }

    private func download(_ url: URL, fileName: String, cookies: [HTTPCookie], userAgent: String, completion: @escaping (Result<URL, Failure>) -> Void) {
        let download = Download(fileName: fileName, cookieHeader: AudioFiles.cookieHeader(for: url, from: cookies), userAgent: userAgent, completion: completion)
        let task = session.downloadTask(with: signed(URLRequest(url: url), for: download))
        downloads[task.taskIdentifier] = download
        task.resume()
    }
}

extension AudioFiles: URLSessionDownloadDelegate {
    /// Redirects are followed only while they stay on the site over https.
    func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse,
                    newRequest request: URLRequest, completionHandler: @escaping (URLRequest?) -> Void) {
        guard let download = downloads[task.taskIdentifier], AppConfig.urls.isAppUrl(request.url) else {
            downloads[task.taskIdentifier]?.failure = Failure(code: "redirect_not_allowed", message: "The file is not on the site")
            completionHandler(nil)
            task.cancel()
            return
        }
        completionHandler(signed(request, for: download))
    }

    func urlSession(_ session: URLSession, downloadTask: URLSessionDownloadTask, didWriteData bytesWritten: Int64,
                    totalBytesWritten: Int64, totalBytesExpectedToWrite: Int64) {
        if totalBytesWritten > AudioFiles.maxBytes || totalBytesExpectedToWrite > AudioFiles.maxBytes {
            downloads[downloadTask.taskIdentifier]?.failure = Failure(code: "too_large", message: "The file is too large")
            downloadTask.cancel()
        }
    }

    func urlSession(_ session: URLSession, downloadTask: URLSessionDownloadTask, didFinishDownloadingTo location: URL) {
        guard let download = downloads[downloadTask.taskIdentifier], download.failure == nil else { return }
        guard let response = downloadTask.response as? HTTPURLResponse else {
            download.failure = Failure(code: "http_error", message: "No answer from the site")
            return
        }
        if response.statusCode == 401 || response.statusCode == 403 {
            download.failure = Failure(code: "not_authorized", message: "Sign in again to get this file")
            return
        }
        guard (200...299).contains(response.statusCode) else {
            download.failure = Failure(code: "http_error", message: "The site answered \(response.statusCode)")
            return
        }
        // A sign-in page instead of the file.
        if (response.mimeType ?? "").lowercased().hasPrefix("text/html") {
            download.failure = Failure(code: "not_a_file", message: "The site answered with a page, not a file")
            return
        }
        do {
            let size = (try FileManager.default.attributesOfItem(atPath: location.path)[.size] as? NSNumber)?.int64Value ?? 0
            if size == 0 {
                download.failure = Failure(code: "empty", message: "The file is empty")
                return
            }
            // The temporary file is gone when this method returns: move it now.
            let file = try AudioFiles.place(for: download.fileName)
            try FileManager.default.moveItem(at: location, to: file)
            download.saved = file
        } catch {
            download.failure = Failure(code: "io_error", message: error.localizedDescription)
        }
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        guard let download = downloads.removeValue(forKey: task.taskIdentifier) else { return }
        if let failure = download.failure {
            download.completion(.failure(failure))
        } else if let saved = download.saved {
            download.completion(.success(saved))
        } else {
            download.completion(.failure(Failure(code: "http_error", message: error?.localizedDescription ?? "The file could not be downloaded")))
        }
    }
}

// MARK: - Handing the file over

extension AudioFiles {
    /// "Save": the phone's own sheet, where the person chooses the place. `completion` gets
    /// where the copy went, or the code "cancelled" when the sheet was closed.
    func presentSave(_ file: URL, from presenter: UIViewController, completion: @escaping (Result<URL, Failure>) -> Void) {
        let picker = UIDocumentPickerViewController(forExporting: [file], asCopy: true)
        let delegate = SavePicker { [weak self, weak picker] result in
            if let picker = picker { self?.pickers[ObjectIdentifier(picker)] = nil }
            completion(result)
        }
        picker.delegate = delegate
        pickers[ObjectIdentifier(picker)] = delegate
        AudioFiles.top(of: presenter).present(picker, animated: true) { [weak picker] in
            // Pulled down with a finger: the phone may tell nobody else that the sheet closed.
            picker?.presentationController?.delegate = delegate
        }
    }

    /// "Share": the share sheet with the file. The receiving app gets this one file only.
    func presentShare(_ file: URL, from presenter: UIViewController, completion: @escaping () -> Void) {
        let sheet = UIActivityViewController(activityItems: [file], applicationActivities: nil)
        let top = AudioFiles.top(of: presenter)
        // On a wide screen the sheet hangs from a point: the middle of the bottom edge.
        sheet.popoverPresentationController?.sourceView = top.view
        sheet.popoverPresentationController?.sourceRect = CGRect(x: top.view.bounds.midX, y: top.view.bounds.maxY - 1, width: 1, height: 1)
        top.present(sheet, animated: true, completion: completion)
    }

    private static func top(of controller: UIViewController) -> UIViewController {
        var top = controller
        while let next = top.presentedViewController, !next.isBeingDismissed {
            top = next
        }
        return top
    }
}

private final class SavePicker: NSObject, UIDocumentPickerDelegate, UIAdaptivePresentationControllerDelegate {
    private var done: ((Result<URL, AudioFiles.Failure>) -> Void)?

    init(done: @escaping (Result<URL, AudioFiles.Failure>) -> Void) {
        self.done = done
    }

    /// The answer is given once, whichever way the sheet ends.
    private func finish(_ result: Result<URL, AudioFiles.Failure>) {
        let done = self.done
        self.done = nil
        done?(result)
    }

    func documentPicker(_ controller: UIDocumentPickerViewController, didPickDocumentsAt urls: [URL]) {
        if let saved = urls.first {
            finish(.success(saved))
        } else {
            finish(.failure(AudioFiles.Failure(code: "cancelled", message: "cancelled")))
        }
    }

    func documentPickerWasCancelled(_ controller: UIDocumentPickerViewController) {
        finish(.failure(AudioFiles.Failure(code: "cancelled", message: "cancelled")))
    }

    func presentationControllerDidDismiss(_ presentationController: UIPresentationController) {
        finish(.failure(AudioFiles.Failure(code: "cancelled", message: "cancelled")))
    }
}
