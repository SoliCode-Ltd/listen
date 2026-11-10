package studio.listenai.app;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

/** File names that pages hand to saveAudio / shareAudio. */
public class FileNamesTest {

    @Test
    public void keepsAGoodName() {
        assertEquals("my-voice_01.mp3", FileNames.sanitize("my-voice_01.mp3", "audio/mpeg"));
        assertEquals("Take (2).wav", FileNames.sanitize("Take (2).wav", "audio/wav"));
    }

    @Test
    public void keepsArabicLetters() {
        assertEquals("صوتي الجديد.mp3", FileNames.sanitize("صوتي الجديد.mp3", "audio/mpeg"));
        assertEquals("مرحبا.mp3", FileNames.sanitize("مرحبا", "audio/mpeg"));
        assertEquals("إعلان ١٢.wav", FileNames.sanitize("  إعلان   ١٢  ", "audio/x-wav"));
    }

    @Test
    public void removesFolders() {
        assertEquals("passwd.mp3", FileNames.sanitize("../../etc/passwd", "audio/mpeg"));
        assertEquals("b.mp3", FileNames.sanitize("C:\\a\\b.mp3", "audio/mpeg"));
        assertEquals("c.mp3", FileNames.sanitize("/a/b/c.mp3", "audio/mpeg"));
        assertEquals("listen-audio.mp3", FileNames.sanitize("a/b/", "audio/mpeg"));
    }

    @Test
    public void removesCharactersFileSystemsRefuse() {
        assertEquals("a_b_c_d_.mp3", FileNames.sanitize("a:b*c?d|.mp3", "audio/mpeg"));
        assertEquals("a b.mp3", FileNames.sanitize("a\tb\u0000\n.mp3", "audio/mpeg"));
        assertEquals("_x_.mp3", FileNames.sanitize("<x>.mp3", "audio/mpeg"));
    }

    @Test
    public void removesInvisibleDirectionMarks() {
        // A right-to-left override can make "gpm.exe" look like "exe.mp3" on screen.
        assertEquals("evil3pm.exe.mp3", FileNames.sanitize("evil\u202E3pm.exe", "audio/mpeg"));
        assertEquals("ab.mp3", FileNames.sanitize("a\u200Bb\u200F.mp3", "audio/mpeg"));
    }

    @Test
    public void neverMakesAHiddenOrEmptyName() {
        assertEquals("listen-audio.mp3", FileNames.sanitize(null, "audio/mpeg"));
        assertEquals("listen-audio.mp3", FileNames.sanitize("", "audio/mpeg"));
        assertEquals("listen-audio.mp3", FileNames.sanitize("...", "audio/mpeg"));
        assertEquals("listen-audio.mp3", FileNames.sanitize(".mp3", "audio/mpeg"));
        assertEquals("htaccess.mp3", FileNames.sanitize(".htaccess", "audio/mpeg"));
    }

    @Test
    public void makesTheExtensionMatchTheType() {
        assertEquals("voice.mp3", FileNames.sanitize("voice", "audio/mpeg"));
        assertEquals("voice.mp3", FileNames.sanitize("voice.MP3", "audio/mpeg"));
        assertEquals("voice.wav.mp3", FileNames.sanitize("voice.wav", "audio/mpeg"));
        assertEquals("voice.exe.mp3", FileNames.sanitize("voice.exe", "audio/mpeg"));
        assertEquals("voice.m4a", FileNames.sanitize("voice.m4a", "audio/mp4"));
        assertEquals("voice.m4a", FileNames.sanitize("voice", "audio/mp4; codecs=mp4a.40.2"));
        assertEquals("clip.mp4", FileNames.sanitize("clip.mp4", "video/mp4"));
        assertEquals("clip.webm", FileNames.sanitize("clip", "VIDEO/WEBM"));
        assertEquals("v1.2 final.mp3", FileNames.sanitize("v1.2 final", "audio/mpeg"));
    }

    @Test
    public void handlesTypesItDoesNotKnow() {
        assertEquals("notes.srt", FileNames.sanitize("notes.srt", "application/x-subrip"));
        assertEquals("notes.bin", FileNames.sanitize("notes", "application/octet-stream"));
    }

    @Test
    public void limitsTheLength() {
        StringBuilder longName = new StringBuilder();
        for (int i = 0; i < 300; i++) longName.append('ص');
        String result = FileNames.sanitize(longName + ".mp3", "audio/mpeg");
        assertEquals(80 + ".mp3".length(), result.length());
        assertTrue(result.endsWith(".mp3"));
        // 80 Arabic letters are 160 bytes in UTF-8: well under the 255-byte limit of phone storage.
        assertTrue(result.getBytes(java.nio.charset.StandardCharsets.UTF_8).length < 255);
    }

    @Test
    public void doesNotCutAnEmojiInHalf() {
        StringBuilder name = new StringBuilder();
        for (int i = 0; i < 100; i++) name.append("\uD83C\uDFA4"); // microphone emoji, two chars each
        String result = FileNames.sanitize(name.toString(), "audio/mpeg");
        String base = result.substring(0, result.length() - 4);
        assertEquals(80, base.codePointCount(0, base.length()));
        assertFalse(Character.isHighSurrogate(base.charAt(base.length() - 1)));
    }

    @Test
    public void recognisesMediaTypes() {
        assertTrue(FileNames.isMediaType("audio/mpeg"));
        assertTrue(FileNames.isMediaType("Audio/WAV"));
        assertTrue(FileNames.isMediaType("video/mp4; codecs=avc1"));
        assertFalse(FileNames.isMediaType("text/html"));
        assertFalse(FileNames.isMediaType("application/vnd.android.package-archive"));
        assertFalse(FileNames.isMediaType("audio/"));
        assertFalse(FileNames.isMediaType(null));
        assertEquals("mp3", FileNames.extensionFor("audio/mpeg"));
        assertEquals("wav", FileNames.extensionFor("audio/x-wav"));
        assertNull(FileNames.extensionFor("text/plain"));
    }
}
