# Listen app: the page ↔ native bridge

How the site's pages (served from `https://listenai.studio`, plain JS, **no** `@capacitor/core`
bundle) talk to the Android app. Written against Capacitor **8.6.0** by reading
`@capacitor/android`'s `Bridge.java`, `JSInjector.java`, `JSExport.java`, `MessageHandler.java`,
`Plugin.java` and `assets/native-bridge.js`; the JavaScript half was also executed (the real
`native-bridge.js` in Chromium with a stand-in for the native side). The native half has not
run on a device yet.

Keep this file in step with `android/app/src/main/java/studio/listenai/app/ListenShellPlugin.java`.

---

## 1. The three questions

### (a) Is the bridge injected into the remote pages?

**Yes, automatically, on the configured site origin only.**

`Bridge.loadWebView()` builds one script — `window.Capacitor = { DEBUG, isLoggingEnabled, Plugins: {} }`,
then `native-bridge.js`, then generated code that defines `Capacitor.Plugins.<Name>` with one
function per native method for **every registered plugin**, then `Capacitor.PluginHeaders` — and
registers it with `WebViewCompat.addDocumentStartJavaScript(webView, script, { origin of server.url })`.

- The origin is exact: scheme + host of the build's site URL (`https://listenai.studio`).
  `www.` or any other subdomain gets **no** bridge. The app's own offline page (`https://localhost`) gets none either.
- It runs in every frame of that origin, but native only accepts calls from the **main frame**
  (`MessageHandler`: "Plugin execution is allowed in Main Frame only").
- Calls travel over a `WebMessageListener` object named `androidBridge`, which the WebView only
  exposes to the same allowed origins. A page on any other origin cannot reach the plugins.
- Very old WebViews without the document-start feature fall back to Capacitor fetching the HTML
  itself and inserting a `<script>` after `<head>` (GET requests for `text/html` only). Nothing to do on the site for that.

### (b) How does plain JS call a method and listen to events?

```js
// Call: every method takes ONE object and returns a Promise.
window.Capacitor.Plugins.ListenShell.getInfo().then(function (info) { /* ... */ });
// identical: window.Capacitor.nativePromise('ListenShell', 'getInfo', {})

// Listen: returns a handle SYNCHRONOUSLY (a plain object, not a Promise).
var handle = window.Capacitor.Plugins.ListenShell.addListener('mediaAction', function (e) { /* ... */ });
// identical: window.Capacitor.addListener('ListenShell', 'mediaAction', fn)

// Stop listening:
handle.remove();                                             // returns a Promise<void>
window.Capacitor.Plugins.ListenShell.removeAllListeners();   // everything on that plugin
```

Differences from the `@capacitor/core` npm wrapper, which the site does not use:
`addListener` here returns the handle directly (the npm wrapper returns a Promise of it — `await`
works on both), and there is no `Capacitor.registerPlugin`.

A rejected call rejects with an `Error` that also carries `code` (when native set one):
`err.message`, `err.code`.

**Listeners do not survive a page load.** On every `onPageStarted` native drops all listeners
(`Bridge.reset()`), so each page subscribes again when it loads — which is what a normal page does anyway.

One consequence: Capacitor drops listeners when a navigation *starts*, even one that ends up as a
file download and leaves the page in place. So avoid plain download links inside the app (use
`saveAudio`), or subscribe again after one.

**Some events wait for a listener.** These are kept by native until the first listener for them
is added, even across page loads: `registration`, `registrationError`, `pushNotificationReceived`,
`pushNotificationActionPerformed`, `appUrlOpen`. `mediaAction`, `backPressed` and `appStateChange` are not kept.

### (c) When is it available?

**Before any script of the page runs** (document start), so a script in `<head>` can use
`window.Capacitor` immediately; there is nothing to wait for (`deviceready` is not needed).

Detection:

- In the page: `window.Capacitor && Capacitor.isNativePlatform() && Capacitor.Plugins.ListenShell`.
- On the server: the `User-Agent` of every WebView request ends with `ListenApp/<versionName> (Android)`
  (set on the WebView before the first request). The browser tab used for sign-in is the person's
  real browser and does **not** carry it.

### The glue (plain JS, tested as described above)

```js
window.ListenNative = (function () {
  var cap = window.Capacitor;
  var ok = !!(cap && cap.isNativePlatform && cap.isNativePlatform() && cap.Plugins && cap.Plugins.ListenShell);
  return {
    available: ok,
    // call('ListenShell', 'saveAudio', {...}) -> Promise
    call: function (plugin, method, args) {
      var p = ok && cap.Plugins[plugin];
      if (!p || typeof p[method] !== 'function') return Promise.reject(new Error('native unavailable'));
      return p[method](args || {});
    },
    // on('ListenShell', 'mediaAction', fn) -> function that stops listening
    on: function (plugin, event, fn) {
      var p = ok && cap.Plugins[plugin];
      if (!p) return function () {};
      var handle = p.addListener(event, fn);
      return function () { handle.remove(); };
    }
  };
})();
```

### What else the injected script does to the page

Good to know when debugging: it defines `window.Capacitor`, `window.WEBVIEW_SERVER_URL`,
`window.cordova = {}`, `window.Ionic.WebView`, `navigator.app.exitApp`, and wraps
`document.addEventListener` (only to special-case the names `deviceready` and `backbutton`).
`fetch`, `XMLHttpRequest` and `document.cookie` are **not** touched (those patches belong to
`CapacitorHttp` / `CapacitorCookies`, which are off). In debug builds only, it sets `window.onerror`.

---

## 2. `ListenShell` (the app's own plugin)

All methods return a Promise. Arguments are one object. "→" is what the Promise resolves to.

| Method | Argument | → |
|---|---|---|
| `getInfo()` | – | `{ version: string, build: number, platform: 'android', pushAvailable: boolean, notificationsAllowed: boolean, micPermission: 'granted' \| 'denied' \| 'prompt' }` |
| `mediaUpdate(o)` | `{ playing: boolean, title: string, subtitle?: string, position: number, duration: number }` (seconds) | `{ active: boolean }` |
| `mediaStop()` | – | `undefined` |
| `saveAudio(o)` | `{ url?: string, base64?: string, fileName: string, mimeType: string }` | `{ uri: string, displayName: string }` |
| `shareAudio(o)` | `{ url?: string, base64?: string, fileName: string, mimeType: string, title?: string }` | `undefined` (the share sheet is open) |
| `ensureMic(o)` | `{ lang: 'ar' \| 'en' }` | `{ granted: boolean }` |
| `ensureNotifications(o)` | `{ lang: 'ar' \| 'en' }` | `{ granted: boolean }` |
| `signIn(o)` | `{ locale: 'ar' \| 'en' }` | `undefined` (the browser tab is open) |
| `setPullToRefresh(o)` | `{ enabled: boolean }` | `undefined` |
| `setBackHandler(o)` | `{ enabled: boolean }` | `undefined` |
| `minimize()` | – | `undefined` |
| `openExternal(o)` | `{ url: string }` | `undefined` |
| `setTheme(o)` | `{ dark: boolean }` | `undefined` |

| Event | Payload |
|---|---|
| `mediaAction` | `{ action: 'play' \| 'pause' \| 'stop' \| 'seek' \| 'forward' \| 'rewind', position?: number }` — `position` (seconds) only with `seek` |
| `backPressed` | `{}` |

### Notes per method

**`getInfo`** — `version` is the versionName, `build` the versionCode (a number).
`pushAvailable` is false when the app was built without `google-services.json`.
`micPermission`: `'denied'` means Android no longer shows its prompt (only the phone's settings can
turn it on; `ensureMic` offers that); `'prompt'` means asking is still possible.

**`mediaUpdate` / `mediaStop`** — the sound keeps playing in the page's `<audio>` element; native
only shows the lock-screen / notification controls, keeps the app alive with the screen off, and
forwards button presses.

- Call `mediaUpdate` on `play`, `pause`, `seeked`, when the title or duration becomes known, and
  optionally every few seconds. Calling it more often is harmless: updates that change nothing are ignored.
- `duration` 0 (or omitted) means unknown: no seek bar is shown.
- `{ active: false }` means nothing is shown: either the update said "paused" while nothing was
  playing, or Android refused to start the playback service because the app was in the background.
  Playback that begins while the app is visible always works.
- On `mediaAction` the page does the work and then reports the new state with `mediaUpdate`:
  `play` → `audio.play()`, `pause` → `audio.pause()`, `seek` → `audio.currentTime = e.position`,
  `forward` / `rewind` → ±10 seconds, `stop` → pause and forget the track (native has already
  removed its notification).
- Unplugging headphones sends `pause`.
- Native stops by itself: on `mediaStop`, after 5 minutes paused, when the page is left (any new
  page load), and when the app is swiped away. Call `mediaStop` when the track ends.
- The page does not need (and should not try) to handle audio focus: the WebView pauses the element
  on a phone call or when another app plays; the page sees the normal `pause` / `play` events.
- With the screen off the page is "hidden": browsers slow `setTimeout` / `setInterval` to about one
  tick per second and stop `requestAnimationFrame`. Drive progress from the audio element's own
  events (`timeupdate`, `ended`), not from animation frames.

**`saveAudio`** — saves into the phone's **Downloads/Listen** folder.

- Give exactly one of `url` or `base64` (bare base64 or a full `data:` URL).
- `url` must be on the site: a path (`/audio/12/download`) or a full `https://listenai.studio/...`
  URL. Native downloads it with the WebView's cookies and user agent. Redirects are followed only
  while they stay on the site over https; a redirect to another host fails with `redirect_not_allowed`.
- `mimeType` must be `audio/*` or `video/*`.
- `fileName` is cleaned (folders and unsafe characters removed, Arabic kept) and its extension is
  made to match `mimeType`; `displayName` is the name actually used (the phone may add " (1)").
- Android 9 and older ask for the storage permission the first time.
- Rejection codes (`err.code`): `bad_request`, `url_not_allowed`, `redirect_not_allowed`,
  `not_authorized` (401/403), `not_a_file` (the site answered with an HTML page, e.g. a sign-in page),
  `http_error`, `too_large` (over 300 MB), `empty`, `bad_data` (invalid base64), `permission_denied`, `io_error`.

**`shareAudio`** — same source rules and codes as `saveAudio`, plus `no_target`. The file goes to
the app's private cache and is handed to the chosen app read-only; copies older than a day are removed.

**`ensureMic`** — call it before `getUserMedia({ audio: true })`. Shows, as needed: an explanation
dialog (if the person refused before), Android's own prompt, or — if Android will not prompt any
more — a dialog offering to open the app's settings (in which case it resolves `{ granted: false }`;
ask again when the person comes back). Dialog texts are Arabic or English by `lang`.
`getUserMedia` also works without it (the WebView asks for the permission itself), but then there is
no explanation and no way out of a permanent refusal. Only the site's own origin may use the
microphone; camera requests are always refused.

**`ensureNotifications`** — the same pattern for notifications (Android 13+ prompt; on older
versions, or when notifications were switched off in the phone's settings, the settings dialog).
The app never asks on its own; call this at a moment that makes sense (e.g. "tell me when it's ready").

**`signIn`** — opens the phone's browser (an in-app browser tab) at
`<site>/app/signin/start?challenge=<base64url(sha256(verifier))>&locale=<ar|en>`.
See section 4 for the full exchange. Rejects with `no_browser` if the phone has no browser.

**`setPullToRefresh`** — off by default and **switched off again on every page load**; a page that
wants it turns it on when it loads. It only triggers when the document itself is scrolled to the
top (`window.scrollY === 0`). A page that scrolls inside an inner container should leave it off.

**`setBackHandler`** — when on, the back button/gesture sends `backPressed` and does nothing else;
the page decides (close a dialog, `history.back()`, `ListenShell.minimize()`, ...). Also **switched
off on every page load**. If it is on but the page has no `backPressed` listener, the default
applies, so a broken page cannot trap the person. Default: go back in history; on the first page,
send the app to the background (it is not closed).

**`openExternal`** — `https:`, `http:`, `mailto:` and `tel:` only (else `bad_request`). Opens the
phone's browser / mail / dialer. Use it for pages that must not open inside the app (pricing,
billing). Ordinary links to other hosts, `mailto:`, `tel:`, `https://wa.me/...` and `intent:` links
already leave the app by themselves, including `target="_blank"` and `window.open`.

**`setTheme`** — call on load and whenever the site's light/dark toggle changes. Sets the colour
behind the status and navigation bars (`#0B0B0F` / `#FFFFFF`), the icon contrast, and the window
background. Remembered, so the next start-up uses it straight away. Until the first call the app
follows the phone's light/dark setting.

---

## 3. Official plugins available to the page

Only these two are installed (plus Capacitor's built-ins). Names and payloads below are from the plugins' Android sources.

### `Capacitor.Plugins.PushNotifications` (`@capacitor/push-notifications` 8.1.3)

| Call | → |
|---|---|
| `register()` | `undefined`; the token then arrives as the `registration` event |
| `unregister()` | `undefined` |
| `checkPermissions()` / `requestPermissions()` | `{ receive: 'granted' \| 'denied' \| 'prompt' \| 'prompt-with-rationale' }` (prefer `ListenShell.ensureNotifications`) |

| Event | Payload |
|---|---|
| `registration` | `{ value: string }` — the FCM device token; also fires when the token changes |
| `registrationError` | `{ error: string }` |
| `pushNotificationReceived` | `{ id: string, data: { ... }, title?: string, body?: string, click_action?: string, link?: string }` — a push arrived while the app was open (it is also shown as a notification) |
| `pushNotificationActionPerformed` | `{ actionId: 'tap', notification: { id: string, data: { ... } } }` — a notification was tapped |

- In a build without Firebase, `register()` **rejects** (`err.code === 'UNAVAILABLE'`) and a
  `registrationError` event is sent; nothing crashes. Check `getInfo().pushAvailable` first.
- No token exists until the page calls `register()` (automatic token creation is off).
- Suggested order: `ensureNotifications` → if granted, add the `registration` listener → `register()`.
- **Tapping a notification:** if its data has `url` and that is an `https` URL on the site, the
  app loads it in the WebView by itself (cold or warm start). The page does not need to navigate
  on `pushNotificationActionPerformed`.
- What the server should send (FCM HTTP v1): a `notification` (`title`, `body`) plus
  `data: { url: "https://listenai.studio/..." }`. Channel `voice_ready` ("Your audio" / "صوتك"),
  the small icon and the brand colour are the app's defaults; nothing to set per message.

### `Capacitor.Plugins.App` (`@capacitor/app` 8.1.2)

| Event | Payload |
|---|---|
| `appUrlOpen` | `{ url: string }` — the app was opened through a link (App Link or `studio.listenai.app://...`). Native already loads App Links in the WebView and completes sign-in; this is informational. |
| `appStateChange` | `{ isActive: boolean }` — app came to the foreground / went to the background |
| `pause`, `resume` | no payload |

Methods that work: `getInfo()` → `{ name, id, build, version }`, `getState()` → `{ isActive }`,
`getLaunchUrl()` → `{ url } | undefined`, `minimizeApp()`.
The plugin's `backButton` event never fires in this app (its back handling is switched off in
favour of `ListenShell.setBackHandler` / `backPressed`).

---

## 4. What the server needs to provide

**Start page** — the app opens `<site>/app/start` (members → studio, guests → sign-in).

**Sign-in through the browser**

1. Page calls `ListenShell.signIn({ locale })`. Native creates a random 32-byte verifier
   (base64url, 43 characters), stores it, and opens the browser at
   `GET /app/signin/start?challenge=<base64url(sha256(verifier)), no padding>&locale=<ar|en>`.
2. The site signs the person in (in the browser), creates a short-lived one-time `code` bound to
   that `challenge`, and redirects the browser to `studio.listenai.app://signin?code=<code>`.
3. The app receives that link, closes the browser tab, and loads in the WebView
   `GET /app/signin/complete?code=<code>&verifier=<verifier>`.
   The site checks `base64url(sha256(verifier)) === challenge` for that code, signs the WebView
   session in, and redirects to the studio.
4. The verifier is single-use and expires after 15 minutes. A `signin` link that arrives when no
   sign-in is waiting is ignored. The challenge is exactly the S256 method of PKCE (RFC 7636).

`/app/signin/*` is never loaded in the WebView from an outside link: only `/app/start` is an App Link,
App Link path: if a browser hands such a link to the app, the app ignores it.

**App Links** — `https://<site>/.well-known/assetlinks.json` must list the package
`studio.listenai.app` and the SHA-256 fingerprint of the signing certificate(s) (Play's app-signing
key, and the upload key for builds installed directly). Paths that open in the app:
`/app/start`, `/(ar|en)/dashboard…`, `/(ar|en)/demo…`, `/(ar|en)/agents…`, `/(ar|en)/dub-my-video…`,
`/(ar|en)/email/verify/…`, `/(ar|en)/start`. Everything else stays in the browser.

**In-app behaviour worth knowing on the site side**

- Any navigation to another host opens the phone's browser (e.g. a redirect to a payment page).
  Plain `http://` links to the site itself also go to the browser.
- An HTTP error page (404, 419, 500, 503) from the site is shown as the site sent it. The bundled
  "Can't connect" page appears only when the page could not be loaded at all.
- A plain download link (a response with `Content-Disposition: attachment`) on the site is saved to
  Downloads/Listen as a fallback, with a small "saved" message. Prefer `saveAudio`: it reports the
  result to the page. `blob:` downloads cannot be saved natively; pass the data as `base64`.
- File inputs (`<input type="file" accept="audio/*,video/*">`) open the phone's file picker.
- Cookies persist between starts (first-party only; third-party cookies are refused).
