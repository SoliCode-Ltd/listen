package studio.listenai.app;

import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.media.MediaScannerConnection;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;
import android.webkit.CookieManager;
import androidx.annotation.RequiresApi;
import java.io.ByteArrayInputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import javax.net.ssl.HttpsURLConnection;

/**
 * Gets a generated file (from the site, or handed over by the page as base64) onto the phone:
 * into the public Downloads/Listen folder, or into the app's cache for sharing.
 *
 * Downloads go only to the configured site over https, with the WebView's cookies and
 * user agent, so they are authorised exactly like the page that asked for them.
 */
final class AudioFiles {

    /** The sub-folder of Downloads that saved files go to. */
    static final String FOLDER = "Listen";
    private static final String SHARED_DIR = "shared";
    private static final long MAX_BYTES = 300L * 1024 * 1024;
    private static final int MAX_REDIRECTS = 5;
    private static final long SHARED_MAX_AGE_MS = 24 * 60 * 60 * 1000L;

    /** A failure the page can react to: {@code code} is stable, the message is for logs. */
    static final class SaveException extends Exception {
        final String code;

        SaveException(String code, String message) {
            super(message);
            this.code = code;
        }
    }

    static final class Saved {
        final Uri uri;
        final String displayName;

        Saved(Uri uri, String displayName) {
            this.uri = uri;
            this.displayName = displayName;
        }
    }

    /** Where the bytes come from. Exactly one of url / base64 is set. */
    static final class Source {
        final String url;
        final String base64;

        Source(String url, String base64) {
            this.url = url;
            this.base64 = base64;
        }
    }

    private AudioFiles() {}

    // ------------------------------------------------------------------ public entry points

    /** Saves into Downloads/Listen. Android 9 and older need the storage permission first. */
    static Saved saveToDownloads(Context context, AppUrls urls, Source source, String fileName, String mimeType)
        throws SaveException {
        try (InputStream in = open(urls, source)) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                return saveWithMediaStore(context, in, fileName, mimeType);
            }
            return saveLegacy(context, in, fileName, mimeType);
        } catch (IOException e) {
            throw new SaveException("io_error", String.valueOf(e.getMessage()));
        }
    }

    /** Writes into the app's cache/shared folder (the only folder the FileProvider exposes). */
    static File saveToShareCache(Context context, AppUrls urls, Source source, String fileName) throws SaveException {
        File dir = new File(context.getCacheDir(), SHARED_DIR);
        if (!dir.isDirectory() && !dir.mkdirs()) {
            throw new SaveException("io_error", "Cannot create the share folder");
        }
        cleanShareCache(dir);
        // Each share gets its own folder so the visible file name stays exactly as asked.
        File holder = new File(dir, Long.toString(System.currentTimeMillis(), 36));
        if (!holder.mkdirs()) {
            throw new SaveException("io_error", "Cannot create the share folder");
        }
        File target = new File(holder, fileName);
        try (InputStream in = open(urls, source); OutputStream out = new FileOutputStream(target)) {
            copy(in, out);
        } catch (IOException e) {
            deleteRecursively(holder);
            throw new SaveException("io_error", String.valueOf(e.getMessage()));
        } catch (SaveException e) {
            deleteRecursively(holder);
            throw e;
        }
        return target;
    }

    // ------------------------------------------------------------------ sources

    private static InputStream open(AppUrls urls, Source source) throws SaveException, IOException {
        if (source.url != null) {
            return openUrl(urls, source.url);
        }
        String data = source.base64;
        // Accept a full data: URL as well as bare base64.
        int comma = data.startsWith("data:") ? data.indexOf(',') : -1;
        if (comma >= 0) data = data.substring(comma + 1);
        try {
            return new ByteArrayInputStream(Base64.decode(data, Base64.DEFAULT));
        } catch (IllegalArgumentException e) {
            throw new SaveException("bad_data", "base64 is not valid");
        }
    }

    /**
     * GET with the WebView's cookies and user agent. Redirects are followed by hand so that
     * every hop is checked: https, on the configured host. Anything else stops the download.
     */
    private static InputStream openUrl(AppUrls urls, String startUrl) throws SaveException, IOException {
        String current = urls.resolve(startUrl);
        if (current == null) {
            throw new SaveException("url_not_allowed", "Only https URLs on " + urls.host() + " can be downloaded");
        }
        for (int hop = 0; hop <= MAX_REDIRECTS; hop++) {
            HttpsURLConnection connection = (HttpsURLConnection) new URL(current).openConnection();
            connection.setInstanceFollowRedirects(false);
            connection.setConnectTimeout(20_000);
            connection.setReadTimeout(60_000);
            connection.setRequestProperty("Accept", "*/*");
            String userAgent = ListenRuntime.userAgent;
            if (userAgent != null) connection.setRequestProperty("User-Agent", userAgent);
            String cookies = CookieManager.getInstance().getCookie(current);
            if (cookies != null && !cookies.isEmpty()) connection.setRequestProperty("Cookie", cookies);

            int status = connection.getResponseCode();
            if (status >= 300 && status < 400) {
                String location = connection.getHeaderField("Location");
                connection.disconnect();
                if (location == null) throw new SaveException("http_error", "Redirect without a target");
                String next = urls.resolve(new URL(new URL(current), location).toString());
                if (next == null) {
                    throw new SaveException("redirect_not_allowed", "The download was redirected away from " + urls.host());
                }
                current = next;
                continue;
            }
            if (status == HttpURLConnection.HTTP_UNAUTHORIZED || status == HttpURLConnection.HTTP_FORBIDDEN) {
                connection.disconnect();
                throw new SaveException("not_authorized", "HTTP " + status);
            }
            if (status != HttpURLConnection.HTTP_OK) {
                connection.disconnect();
                throw new SaveException("http_error", "HTTP " + status);
            }
            String type = FileNames.cleanMimeType(connection.getContentType());
            if (type.equals("text/html")) {
                // A sign-in or error page, not the file.
                connection.disconnect();
                throw new SaveException("not_a_file", "The site answered with a web page instead of the file");
            }
            long length = connection.getContentLengthLong();
            if (length > MAX_BYTES) {
                connection.disconnect();
                throw new SaveException("too_large", "The file is larger than the app can save");
            }
            return connection.getInputStream();
        }
        throw new SaveException("http_error", "Too many redirects");
    }

    // ------------------------------------------------------------------ destinations

    @RequiresApi(Build.VERSION_CODES.Q)
    private static Saved saveWithMediaStore(Context context, InputStream in, String fileName, String mimeType)
        throws SaveException, IOException {
        ContentResolver resolver = context.getContentResolver();
        ContentValues values = new ContentValues();
        values.put(MediaStore.MediaColumns.DISPLAY_NAME, fileName);
        values.put(MediaStore.MediaColumns.MIME_TYPE, mimeType);
        values.put(MediaStore.MediaColumns.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/" + FOLDER);
        values.put(MediaStore.MediaColumns.IS_PENDING, 1);

        Uri collection = MediaStore.Downloads.getContentUri(MediaStore.VOLUME_EXTERNAL_PRIMARY);
        Uri item = resolver.insert(collection, values);
        if (item == null) throw new SaveException("io_error", "The phone refused to create the file");
        try {
            try (OutputStream out = resolver.openOutputStream(item)) {
                if (out == null) throw new IOException("Cannot open the new file");
                copy(in, out);
            }
            ContentValues done = new ContentValues();
            done.put(MediaStore.MediaColumns.IS_PENDING, 0);
            resolver.update(item, done, null, null);
        } catch (IOException | SaveException | RuntimeException e) {
            resolver.delete(item, null, null);
            throw e;
        }

        // The system may have renamed it ("name (1).mp3") if the name was taken.
        String finalName = fileName;
        try (Cursor cursor = resolver.query(item, new String[] { MediaStore.MediaColumns.DISPLAY_NAME }, null, null, null)) {
            if (cursor != null && cursor.moveToFirst() && cursor.getString(0) != null) finalName = cursor.getString(0);
        } catch (RuntimeException ignored) {
            // Keep the requested name.
        }
        return new Saved(item, finalName);
    }

    /** Android 7–9: a plain file in the public Downloads folder (needs WRITE_EXTERNAL_STORAGE). */
    private static Saved saveLegacy(Context context, InputStream in, String fileName, String mimeType)
        throws SaveException, IOException {
        File dir = new File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS), FOLDER);
        if (!dir.isDirectory() && !dir.mkdirs()) {
            throw new SaveException("io_error", "Cannot create the Downloads/" + FOLDER + " folder");
        }
        File target = uniqueFile(dir, fileName);
        try (OutputStream out = new FileOutputStream(target)) {
            copy(in, out);
        } catch (IOException | SaveException e) {
            //noinspection ResultOfMethodCallIgnored
            target.delete();
            throw e;
        }
        MediaScannerConnection.scanFile(context, new String[] { target.getAbsolutePath() }, new String[] { mimeType }, null);
        return new Saved(Uri.fromFile(target), target.getName());
    }

    private static File uniqueFile(File dir, String fileName) {
        File candidate = new File(dir, fileName);
        if (!candidate.exists()) return candidate;
        int dot = fileName.lastIndexOf('.');
        String base = dot > 0 ? fileName.substring(0, dot) : fileName;
        String extension = dot > 0 ? fileName.substring(dot) : "";
        for (int i = 1; i < 1000; i++) {
            candidate = new File(dir, base + " (" + i + ")" + extension);
            if (!candidate.exists()) return candidate;
        }
        return new File(dir, base + " (" + System.currentTimeMillis() + ")" + extension);
    }

    // ------------------------------------------------------------------ helpers

    private static void copy(InputStream in, OutputStream out) throws IOException, SaveException {
        byte[] buffer = new byte[64 * 1024];
        long total = 0;
        int read;
        while ((read = in.read(buffer)) != -1) {
            total += read;
            if (total > MAX_BYTES) throw new SaveException("too_large", "The file is larger than the app can save");
            out.write(buffer, 0, read);
        }
        if (total == 0) throw new SaveException("empty", "The file is empty");
        out.flush();
    }

    /** Removes shared copies older than a day (the receiving app has long finished reading them). */
    static void cleanShareCache(File dir) {
        File[] entries = dir.listFiles();
        if (entries == null) return;
        long cutoff = System.currentTimeMillis() - SHARED_MAX_AGE_MS;
        for (File entry : entries) {
            if (entry.lastModified() < cutoff) deleteRecursively(entry);
        }
    }

    private static void deleteRecursively(File file) {
        File[] children = file.listFiles();
        if (children != null) {
            for (File child : children) deleteRecursively(child);
        }
        //noinspection ResultOfMethodCallIgnored
        file.delete();
    }
}
