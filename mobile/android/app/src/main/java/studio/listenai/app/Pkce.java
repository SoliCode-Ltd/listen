package studio.listenai.app;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.security.SecureRandom;

/**
 * The secret that ties a browser sign-in to this app (the PKCE idea from OAuth):
 * the app keeps a random "verifier", the browser only ever sees its SHA-256 "challenge".
 * A sign-in code caught by anything else is useless without the verifier.
 */
public final class Pkce {

    private static final char[] ALPHABET =
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_".toCharArray();

    private Pkce() {}

    /** 32 random bytes as base64url without padding (43 characters). */
    public static String newVerifier() {
        byte[] bytes = new byte[32];
        new SecureRandom().nextBytes(bytes);
        return base64Url(bytes);
    }

    /** base64url(sha256(verifier)), no padding. */
    public static String challenge(String verifier) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            return base64Url(digest.digest(verifier.getBytes(StandardCharsets.US_ASCII)));
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException(e);
        }
    }

    /** RFC 4648 section 5, without "=" padding. (java.util.Base64 needs Android 8.) */
    static String base64Url(byte[] data) {
        StringBuilder out = new StringBuilder((data.length * 4 + 2) / 3);
        for (int i = 0; i < data.length; i += 3) {
            int b0 = data[i] & 0xff;
            int b1 = i + 1 < data.length ? data[i + 1] & 0xff : 0;
            int b2 = i + 2 < data.length ? data[i + 2] & 0xff : 0;
            out.append(ALPHABET[b0 >> 2]);
            out.append(ALPHABET[((b0 & 0x03) << 4) | (b1 >> 4)]);
            if (i + 1 < data.length) out.append(ALPHABET[((b1 & 0x0f) << 2) | (b2 >> 6)]);
            if (i + 2 < data.length) out.append(ALPHABET[b2 & 0x3f]);
        }
        return out.toString();
    }
}
