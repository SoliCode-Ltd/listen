import UIKit
import AuthenticationServices

/// "Sign in with Apple": the phone's own sheet (Face ID or the passcode), which hands back
/// a token signed by Apple that says who the person is. The page passes it to the site,
/// and the site checks it before it believes any of it (AppleSignIn.php on the site).
///
/// Apple asks an app that offers another company's sign-in to offer this one beside it
/// (App Review guideline 4.8).
final class AppleSignIn: NSObject, ASAuthorizationControllerDelegate, ASAuthorizationControllerPresentationContextProviding {
    static let shared = AppleSignIn()

    struct Answer {
        let identityToken: String
        let authorizationCode: String?
        let givenName: String?
        let familyName: String?
    }

    struct Failure: Error {
        let code: String
        let message: String
    }

    private var completion: ((Result<Answer, Failure>) -> Void)?
    private weak var anchor: UIWindow?

    /// `nonce` is the site's one-time value: Apple signs it into the token, so the answer
    /// is good for this sign-in only.
    func start(nonce: String, in window: UIWindow?, completion: @escaping (Result<Answer, Failure>) -> Void) {
        guard let window = window, !nonce.isEmpty else {
            completion(.failure(Failure(code: "apple_failed", message: "The sign-in sheet could not be opened")))
            return
        }
        // One sign-in at a time: an earlier one that never answered is closed as cancelled.
        self.completion?(.failure(Failure(code: "cancelled", message: "cancelled")))
        self.completion = completion
        anchor = window

        let request = ASAuthorizationAppleIDProvider().createRequest()
        // The name and email are asked for once, to make the account; nothing else.
        request.requestedScopes = [.fullName, .email]
        request.nonce = nonce

        let controller = ASAuthorizationController(authorizationRequests: [request])
        controller.delegate = self
        controller.presentationContextProvider = self
        controller.performRequests()
    }

    func authorizationController(controller: ASAuthorizationController, didCompleteWithAuthorization authorization: ASAuthorization) {
        guard let credential = authorization.credential as? ASAuthorizationAppleIDCredential,
              let tokenData = credential.identityToken,
              let token = String(data: tokenData, encoding: .utf8), !token.isEmpty else {
            finish(.failure(Failure(code: "apple_failed", message: "Apple gave no token")))
            return
        }
        let code = credential.authorizationCode.flatMap { String(data: $0, encoding: .utf8) }
        finish(.success(Answer(
            identityToken: token,
            authorizationCode: code,
            givenName: credential.fullName?.givenName,
            familyName: credential.fullName?.familyName
        )))
    }

    func authorizationController(controller: ASAuthorizationController, didCompleteWithError error: Error) {
        finish(.failure(AppleSignIn.failure(from: error)))
    }

    /// Closing Apple's sheet is not a failure; the page shows nothing for it.
    static func failure(from error: Error) -> Failure {
        if let error = error as? ASAuthorizationError, error.code == .canceled {
            return Failure(code: "cancelled", message: "cancelled")
        }
        return Failure(code: "apple_failed", message: error.localizedDescription)
    }

    private func finish(_ result: Result<Answer, Failure>) {
        let completion = self.completion
        self.completion = nil
        completion?(result)
    }

    func presentationAnchor(for controller: ASAuthorizationController) -> ASPresentationAnchor {
        anchor ?? ASPresentationAnchor()
    }
}
