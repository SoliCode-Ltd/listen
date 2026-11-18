import Foundation
#if canImport(CryptoKit)
import CryptoKit
#endif

/// The secret that ties a browser sign-in to this app (the PKCE idea from OAuth):
/// the app keeps a random "verifier", the browser only ever sees its SHA-256 "challenge".
/// A sign-in code caught by anything else is useless without the verifier.
enum Pkce {
    /// 32 random bytes as base64url without padding (43 characters).
    static func newVerifier() -> String {
        var generator = SystemRandomNumberGenerator()
        let bytes = (0..<32).map { _ in UInt8.random(in: UInt8.min...UInt8.max, using: &generator) }
        return base64Url(Data(bytes))
    }

    #if canImport(CryptoKit)
    /// base64url(sha256(verifier)), no padding.
    static func challenge(_ verifier: String) -> String {
        base64Url(Data(SHA256.hash(data: Data(verifier.utf8))))
    }
    #endif

    /// RFC 4648 section 5, without "=" padding.
    static func base64Url(_ data: Data) -> String {
        data.base64EncodedString()
            .replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_")
            .replacingOccurrences(of: "=", with: "")
    }
}
