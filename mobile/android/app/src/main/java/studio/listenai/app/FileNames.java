package studio.listenai.app;

import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

/**
 * Makes a file name coming from a page safe to store on the phone, and makes sure its
 * extension matches the kind of file. Arabic (and any other) letters are kept.
 *
 * Pure Java on purpose so it can be unit-tested on the JVM.
 */
public final class FileNames {

    static final String FALLBACK_BASE = "listen-audio";
    private static final int MAX_BASE_LENGTH = 80;

    /** For each type: the extensions that are acceptable, the first one being the default. */
    private static final Map<String, List<String>> EXTENSIONS = new HashMap<>();

    static {
        put("audio/mpeg", "mp3");
        put("audio/mp3", "mp3");
        put("audio/wav", "wav");
        put("audio/x-wav", "wav");
        put("audio/wave", "wav");
        put("audio/vnd.wave", "wav");
        put("audio/ogg", "ogg", "oga", "opus");
        put("audio/opus", "opus");
        put("audio/mp4", "m4a", "mp4");
        put("audio/x-m4a", "m4a");
        put("audio/aac", "aac", "m4a");
        put("audio/flac", "flac");
        put("audio/x-flac", "flac");
        put("audio/webm", "webm", "weba");
        put("video/mp4", "mp4", "m4v");
        put("video/webm", "webm");
        put("video/quicktime", "mov");
    }

    private FileNames() {}

    private static void put(String mime, String... extensions) {
        EXTENSIONS.put(mime, Arrays.asList(extensions));
    }

    /** "audio/mpeg; codecs=..." becomes "audio/mpeg". Never null. */
    public static String cleanMimeType(String mimeType) {
        if (mimeType == null) return "";
        String value = mimeType;
        int semicolon = value.indexOf(';');
        if (semicolon >= 0) value = value.substring(0, semicolon);
        return value.trim().toLowerCase(Locale.ROOT);
    }

    /** Only sound and video files may be saved or shared through the bridge. */
    public static boolean isMediaType(String mimeType) {
        String clean = cleanMimeType(mimeType);
        return clean.matches("(audio|video)/[a-z0-9][a-z0-9.+-]*");
    }

    /** The default extension for a type, or null when the type is not one we know. */
    public static String extensionFor(String mimeType) {
        List<String> known = EXTENSIONS.get(cleanMimeType(mimeType));
        return known == null ? null : known.get(0);
    }

    /**
     * Returns a safe file name with the right extension.
     * Removes folders ("../x/y.mp3" becomes "y.mp3"), characters that file systems refuse,
     * invisible direction/control characters, and leading dots; keeps letters of any language.
     */
    public static String sanitize(String requestedName, String mimeType) {
        String name = requestedName == null ? "" : requestedName;

        // Keep only the last path segment.
        int slash = Math.max(name.lastIndexOf('/'), name.lastIndexOf('\\'));
        if (slash >= 0) name = name.substring(slash + 1);

        StringBuilder cleaned = new StringBuilder(name.length());
        for (int i = 0; i < name.length(); ) {
            int cp = name.codePointAt(i);
            i += Character.charCount(cp);
            if (Character.isWhitespace(cp) || Character.isSpaceChar(cp)) {
                cleaned.append(' '); // tabs, line breaks and exotic spaces become one plain space
            } else if (isForbidden(cp)) {
                continue;
            } else if ("<>:\"|?*".indexOf(cp) >= 0) {
                cleaned.append('_');
            } else {
                cleaned.appendCodePoint(cp);
            }
        }
        name = cleaned.toString().replaceAll(" {2,}", " ").trim();
        // Just an extension (".mp3") is not a name.
        if (name.matches("\\.[A-Za-z0-9]{1,5}")) name = "";
        // No hidden files, no names made only of dots.
        name = name.replaceAll("^[. ]+", "").replaceAll("[. ]+$", "");

        String base = name;
        String extension = "";
        int dot = name.lastIndexOf('.');
        if (dot > 0 && dot < name.length() - 1) {
            String candidate = name.substring(dot + 1).toLowerCase(Locale.ROOT);
            if (candidate.matches("[a-z0-9]{1,5}")) {
                base = name.substring(0, dot);
                extension = candidate;
            }
        }
        base = base.replaceAll("[. ]+$", "").trim();
        if (base.isEmpty()) base = FALLBACK_BASE;
        base = truncate(base, MAX_BASE_LENGTH);

        List<String> accepted = EXTENSIONS.get(cleanMimeType(mimeType));
        if (accepted != null) {
            if (!accepted.contains(extension)) {
                // "voice.txt" declared as audio/mpeg becomes "voice.txt.mp3": nothing is silently relabelled.
                if (!extension.isEmpty()) base = truncate(base + "." + extension, MAX_BASE_LENGTH);
                extension = accepted.get(0);
            }
        } else if (extension.isEmpty()) {
            extension = "bin";
        }
        return base + "." + extension;
    }

    private static boolean isForbidden(int cp) {
        if (cp < 0x20 || cp == 0x7f) return true; // control characters
        int type = Character.getType(cp);
        // Invisible formatting characters (zero-width, bidirectional overrides), unassigned, private use, surrogates.
        return type == Character.FORMAT || type == Character.CONTROL || type == Character.UNASSIGNED
            || type == Character.PRIVATE_USE || type == Character.SURROGATE
            || type == Character.LINE_SEPARATOR || type == Character.PARAGRAPH_SEPARATOR;
    }

    /** Cuts by whole characters (never through a surrogate pair). */
    private static String truncate(String value, int maxCodePoints) {
        if (value.codePointCount(0, value.length()) <= maxCodePoints) return value;
        return value.substring(0, value.offsetByCodePoints(0, maxCodePoints)).trim();
    }
}
