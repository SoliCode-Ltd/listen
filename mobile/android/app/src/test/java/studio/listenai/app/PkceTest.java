package studio.listenai.app;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotEquals;
import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import org.junit.Test;

/** The sign-in secret: verifier and its SHA-256 challenge, both base64url without padding. */
public class PkceTest {

    @Test
    public void challengeMatchesThePublishedExample() {
        // The worked example from RFC 7636, appendix B.
        assertEquals("E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM", Pkce.challenge("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"));
    }

    @Test
    public void verifierIs32RandomBytesInBase64Url() {
        String verifier = Pkce.newVerifier();
        assertEquals(43, verifier.length()); // 32 bytes, no padding
        assertTrue(verifier.matches("[A-Za-z0-9_-]{43}"));
        assertNotEquals(verifier, Pkce.newVerifier());
    }

    @Test
    public void base64UrlMatchesTheStandardTestVectors() {
        // RFC 4648 section 10, minus the "=" padding.
        assertEquals("", encode(""));
        assertEquals("Zg", encode("f"));
        assertEquals("Zm8", encode("fo"));
        assertEquals("Zm9v", encode("foo"));
        assertEquals("Zm9vYg", encode("foob"));
        assertEquals("Zm9vYmE", encode("fooba"));
        assertEquals("Zm9vYmFy", encode("foobar"));
        // "-" and "_" instead of "+" and "/".
        assertEquals("-_-_", Pkce.base64Url(new byte[] { (byte) 0xfb, (byte) 0xff, (byte) 0xbf }));
    }

    @Test
    public void agreesWithTheJdkEncoder() {
        byte[] bytes = new byte[32];
        for (int i = 0; i < bytes.length; i++) bytes[i] = (byte) (i * 37 + 11);
        assertEquals(java.util.Base64.getUrlEncoder().withoutPadding().encodeToString(bytes), Pkce.base64Url(bytes));
    }

    private static String encode(String text) {
        return Pkce.base64Url(text.getBytes(StandardCharsets.US_ASCII));
    }
}
