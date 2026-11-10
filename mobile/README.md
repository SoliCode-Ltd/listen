# The Listen Android app

This folder holds the Android app for Listen (إسمع). It is a small native app that opens the
live site, https://listenai.studio, and adds what a phone app should have: notifications,
playing audio with the screen off, saving and sharing audio, the microphone, sign-in that
lasts, and links that open in the app.

Three things to know first:

1. **Nothing is paid inside the app.** Google Play only allows its own billing (it keeps
   15–30%) for plans paid inside an app. So paying always happens on the website, as today,
   and Google gets no cut.
2. **How the app treats plans is a setting you can change at any time**, in the server's
   `.env`, with no new app version:

   | `ANDROID_APP_PLANS=` | What a member sees in the app |
   |---|---|
   | `hidden` (the default) | No price, plan, pack or upgrade anywhere in the app, and no link to one. A member sees a "My account" page with their plan, credits and the day credits renew. |
   | `web` | Prices and plans, as on the website. Choosing a plan opens the phone's browser, already signed in, on the billing page with the plan and the monthly or yearly choice they made. A visitor who is not signed in signs in (or up) and lands on the same page. |

   **Please read this before publishing.** Google Play's payments policy does not let an app
   lead people to pay somewhere other than Google's billing, in most countries (Egypt and
   the Gulf included). `web` does exactly that, so Google may reject the app or remove it
   later. `hidden` is the setting that follows the policy. The United States and the
   European Economic Area have exceptions, through programs you enrol in from the Play
   Console. A sensible way to run it: submit and get approved with `hidden` (nothing to
   set: it is the default), and decide about `web` knowing the risk; to try it, add
   `ANDROID_APP_PLANS=web` to `.env`, and remove the line again if Google ever objects. After changing the setting, rebuild the config cache ("Server deploy"
   → `settings`).
3. **The app is the site.** A change to the site shows in the app the same minute, with no
   app update. A new app version is needed only when something in this folder changes.

Nothing here costs money except the Google Play developer account (25 US dollars, once).
Notifications use Firebase Cloud Messaging, which is free.

---

## 1. What you need to do, in order

Each step says exactly what to enter. Steps 1 to 3 can be done while the pull request is
still open.

### Step 1. Open the Google Play developer account (25 US dollars, once)

1. Go to https://play.google.com/console/signup and sign in with the Google account that
   should own the app (use a company account you will keep, not a personal one you may lose).
2. Account type: **Organization** if SoliCode Ltd is the publisher (Google will ask for the
   company's D-U-N-S number and verify the company; allow a few days), otherwise
   **Yourself**. At the time of writing, a new personal account must run a closed test with
   12 testers for 14 days before it may publish; an organization account does not have
   that rule. Google shows the current rule when you choose.
3. Developer name (shown to everyone on the store): `Listen` or `SoliCode Ltd`.
4. Contact email and phone: ones you read; Google verifies both.
5. Pay the 25 dollars and finish identity verification.

### Step 2. Make the upload key (once, and keep it safe)

The upload key proves that an app file comes from you. On any computer with Java:

```
keytool -genkeypair -v -keystore listen-upload.jks -alias listen-upload \
  -keyalg RSA -keysize 4096 -validity 10000
```

It asks for a password (choose a strong one and store it in your password manager) and
your name and company. Then:

- Keep `listen-upload.jks` and its password somewhere safe, in two places. **Never put the
  file in this repository** (the `.gitignore` here refuses `*.jks`).
- Print its fingerprint; you need it in step 6:

```
keytool -list -v -keystore listen-upload.jks -alias listen-upload | grep SHA256
```

- Add these **repository secrets** on GitHub (Settings → Secrets and variables → Actions):

| Secret | Value |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | the file as one line of text: `base64 -w0 listen-upload.jks` |
| `ANDROID_KEYSTORE_PASSWORD` | the password you chose |
| `ANDROID_KEY_ALIAS` | `listen-upload` |
| `ANDROID_KEY_PASSWORD` | only if you gave the key its own password |

Google keeps the real signing key itself ("Play App Signing", switched on by default when
you create the app). If you ever lose the upload key, Google can replace it; nobody can
replace Google's key, which is why it stays with Google.

### Step 3. Firebase, for notifications (free)

1. Open https://console.firebase.google.com. If the site already has a Firebase project
   (it is used for phone sign-in), use that one; otherwise create a project named `Listen`
   (Google Analytics: **off**).
2. Add app → Android. Package name: `studio.listenai.app`. Nickname: `Listen Android`.
   Skip the SHA-1 field.
3. Download `google-services.json`. Do not commit it. Add it as the repository secret
   `GOOGLE_SERVICES_JSON_BASE64` (`base64 -w0 google-services.json`).
4. Project settings → Service accounts → **Generate new private key**. This downloads a
   JSON file that lets the site send notifications. Treat it like a password.
5. On each server, put that JSON in `.env` as one line, base64-encoded
   (`base64 -w0 the-file.json`):

```
FCM_CREDENTIALS_JSON=<the one long line>
```

   (Or upload the file to the server outside the public folder and set
   `FCM_CREDENTIALS_PATH` to its full path.) Until this is set, the site simply sends no
   notifications; everything else works.

### Step 4. Merge and deploy the site changes

The pull request changes the site so that it knows the app. After you say "yes merge it":

1. "Server deploy" workflow → `inspect`, then `deploy`, staging first, then live.
2. Mode `migrate` on each server: there is one new table, `app_devices` (the phones that
   get notifications). Until it is run, the app still works; only registering a phone for
   notifications fails.
3. Nothing changes for people using the site in a browser.

### Step 5. Build the app file

On GitHub: Actions → **Android build** → Run workflow, on `main`.

- `version_name`: what people see, for example `1.0.0`.
- `version_code`: a whole number that must go up with every upload to Google Play
  (1, then 2, then 3 …).
- `app_url`: leave empty (it means https://listenai.studio).

When it finishes, download the artifacts from the run's page:

- **the release bundle** (`.aab`): this is what you upload to Google Play;
- **the debug APK** (`.apk`): install this one on your own phone to try the app first
  (it is marked "debug" and cannot be uploaded to the store).

Section 5 explains building on your own computer instead.

### Step 6. Create the app in the Play Console

1. Create app. Name: `Listen` (see section 2 for the Arabic listing). Default language:
   Arabic (`ar`). App or game: **App**. Free or paid: **Free**.
2. Testing → **Internal testing** → Create release → upload the `.aab` → add your own email
   as a tester → install from the link Google gives you.
3. Setup → App integrity → App signing: copy the **SHA-256 certificate fingerprint** of the
   *App signing key*.
4. On the live server's `.env`, set both fingerprints, comma separated (Google's from the
   line above, and your upload key's from step 2), then rebuild the config cache (the
   "Server deploy" workflow's `settings` mode does that):

```
ANDROID_APP_CERT_SHA256=AA:BB:…:FF,11:22:…:99
```

   This fills https://listenai.studio/.well-known/assetlinks.json, which is how Android
   knows that links to the site may open in the app. Until it is set, links keep opening
   in the browser; nothing breaks.
5. Fill in the store listing (section 2), Data safety (section 3), Content rating
   (section 4) and the rest of "App content" (section 4).
6. When the internal test looks right on a real phone (section 7 is the checklist),
   promote the release to Production and send it for review.

---

## 2. The store listing (Arabic and English)

No other company or product is named, and no price.

| | Arabic (`ar`) | English (`en-US`) |
|---|---|---|
| App name (30 max) | `إسمع: استوديو الصوت العربي` | `Listen: Arabic AI Voice Studio` |
| Short description (80 max) | `حوّل النص إلى صوت طبيعي بالعربية والإنجليزية، باللهجة التي تختارها.` | `Turn text into natural Arabic and English speech, in the dialect you choose.` |

**Full description, Arabic**

```
إسمع هو استوديو الصوت العربي بالذكاء الاصطناعي. اكتب نصك، اختر اللهجة والصوت والمشاعر، واحصل على تعليق صوتي طبيعي في ثوانٍ.

ماذا يمكنك أن تفعل:
• تحويل النص إلى صوت بالعربية الفصحى واللهجات العربية، وبالإنجليزية بلكناتها
• التحكم في الأداء: المشاعر، الأسلوب، السرعة، والوقفات
• حوارات بأكثر من صوت
• استنساخ صوتك من تسجيل قصير (في الباقات التي تشمله)
• تصميم أي صوت بوصفه في جملة (في الباقات التي تشمله)
• وكلاء صوتيون يردّون على عملائك بلهجتهم

في التطبيق:
• استمع والشاشة مغلقة، وتحكّم من شاشة القفل
• إشعار عندما يجهز صوتك الطويل
• احفظ الملف على هاتفك أو شاركه مباشرة مع أي تطبيق
• سجّل صوتك بالميكروفون
• عربي وإنجليزي، وضع فاتح وداكن

سجّل الدخول بحسابك في إسمع، أو أنشئ حساباً من التطبيق وجرّب مجاناً.

الخصوصية: https://listenai.studio/ar/privacy-policy
```

**Full description, English**

```
Listen is the Arabic AI voice studio. Write your text, choose the dialect, the voice and the emotion, and get a natural voice-over in seconds.

What you can do:
• Turn text into speech in Modern Standard Arabic and the Arabic dialects, and in English with its accents
• Direct the performance: emotion, style, pace and pauses
• Dialogues with more than one voice
• Clone your own voice from a short recording (on plans that include it)
• Design any voice by describing it in a sentence (on plans that include it)
• Voice agents that answer your customers in their dialect

In the app:
• Keep listening with the screen off, with controls on the lock screen
• A notification when a long voice is ready
• Save the file to your phone or share it straight to any app
• Record your voice with the microphone
• Arabic and English, light and dark

Sign in with your Listen account, or create one in the app and try it free.

Privacy: https://listenai.studio/en/privacy-policy
```

**Other listing fields**

- Category: **Productivity**. Tags: text to speech, voice.
- Contact email: the support address the site already shows. Website: `https://listenai.studio`.
- Graphics: they are ready in `mobile/store/`. Upload them as they are:
  - `icon-512.png`: the app icon (512×512).
  - `feature-ar-1024x500.png` and `feature-en-1024x500.png`: the feature graphic for each language.
  - `phone-ar-1…8.png` and `phone-en-1…8.png`: eight phone screenshots (1080×1920) for each
    language, in this order: the studio, Arabic dialects, English accents, emotions, voice
    cloning, voice design, voice agents, voices in more languages. (Video dubbing is not
    public on the live site, so it is not in the images or the listing text; add it when it is.) They are made from the real
    pages and show no price. Screen 8 shows the French, Spanish, German and other voices of
    the samples page: leave it out if members cannot generate in those languages yet.

---

## 3. Data safety form

These answers describe what the app does as built. Read them against the privacy policy
before you submit; you are the one declaring them.

- Does the app collect or share user data? **Yes.**
- Is all data encrypted in transit? **Yes** (HTTPS only).
- Can people ask for their data to be deleted? **Yes.** Deletion URL:
  `https://listenai.studio/en/contact-us?topic=delete`
  (in the app: menu → My account → Delete my account).

Data types to tick. For every one: **Collected: yes. Shared: no** (companies that only
process data for Listen, such as the speech engine, are "service providers", which Google
does not count as sharing). "Processed ephemerally": no.

| Data type | Why | Required? |
|---|---|---|
| Personal info → Name | Account management | Required |
| Personal info → Email address | Account management, messages about the account | Required |
| Personal info → Phone number (only if phone sign-in is on) | Account management | Optional |
| Location → Approximate location (the country, from the network address) | Fraud prevention and security, analytics | Required |
| Audio → Voice or sound recordings (voice cloning samples, calls with an agent) | App functionality | Optional |
| Photos and videos → Videos (a video sent for dubbing; tick this only once dubbing is public) | App functionality | Optional |
| App activity → Other user-generated content (the texts turned into speech) | App functionality | Required |
| Device or other IDs (the notification token of this phone) | App functionality | Optional |

Not collected by the app: financial information (nothing is bought in the app), contacts,
calendar, health, messages, files outside the ones the person picks, browsing history,
precise location, advertising ID. The app contains no advertising and no analytics kit;
inside the app the site also leaves out its own analytics and advertising pixel.

---

## 4. Content rating and the rest of "App content"

**Content rating questionnaire** (email: yours; category: **Utility, Productivity,
Communication or Other**):

- Violence, blood, fear: **No**. Sexual content, nudity: **No**. Profanity or crude humour:
  **No**. Drugs, alcohol, tobacco: **No**. Gambling: **No**.
- Can users interact or exchange content with each other? **No** (each member only sees
  their own work).
- Does the app share the user's current location with other users? **No**.
- Does the app allow purchases of digital goods? **No** (nothing is paid in the app).
- Is it a web browser or a search engine? **No**.

**Other "App content" declarations**

- Privacy policy: `https://listenai.studio/en/privacy-policy`
  (Arabic: `https://listenai.studio/ar/privacy-policy`).
- Ads: **No, the app does not contain ads.**
- App access: **All or some functionality is restricted** → add instructions and a test
  account. Create an ordinary member account for Google's reviewers on a paid plan (so they
  can open the studio), and give its email and password here. Without it the review fails.
- Target audience: **18 and over**.
- News app: No. Health: none. Financial features: none. Government: No.
- Advertising ID: **No** (the permission is removed from the app).
- AI-generated content: the app makes speech with AI. Google asks such apps for a way to
  report content. Answer that reports go through the in-app "Help and contact" page
  (`https://listenai.studio/en/contact-us`), and that the service blocks abusive wording.
- Data safety: section 3.

---

## 5. Building it yourself (optional)

You only need this to change the app. GitHub builds it for you otherwise.

Needs: Node 22, Java 21, and the Android SDK (Android Studio installs it).

```
cd mobile
npm ci                      # Capacitor and its two plugins
npx cap sync android        # copies the offline page, lists the plugins
cd android
./gradlew lint testDebugUnitTest   # checks
./gradlew assembleDebug            # app/build/outputs/apk/debug/app-debug.apk
./gradlew bundleRelease            # app/build/outputs/bundle/release/app-release.aab
```

Settings, as environment variables (or `-PNAME=value` on the gradle command):

| Name | Meaning | Default |
|---|---|---|
| `LISTEN_APP_URL` | the site the app opens | `https://listenai.studio` |
| `LISTEN_VERSION_NAME` | version people see | `1.0.0` |
| `LISTEN_VERSION_CODE` | number for Google Play, must go up | `1` |
| `LISTEN_APP_ID_SUFFIX` | e.g. `.staging`, to install a test app beside the real one | empty |
| `LISTEN_KEYSTORE_FILE`, `LISTEN_KEYSTORE_PASSWORD`, `LISTEN_KEY_ALIAS`, `LISTEN_KEY_PASSWORD` | the upload key; without them the bundle is left unsigned | none |

For notifications in a local build, put `google-services.json` in `android/app/` (it is
ignored by git). Without it the app builds and runs, with notifications off.

**A staging app:** run the workflow with `app_url` = the staging address. Remember that
staging uses the live payment keys: the app shows no purchase page anyway, but do not run
payment tests there, and ask before generating audio (the speech quota is shared).

---

## 6. How it is put together

| Part | Where |
|---|---|
| The native app (Capacitor 8, one plugin of our own, `ListenShell`) | `mobile/android/` |
| How pages talk to the native side | `mobile/BRIDGE.md` |
| The page shown when there is no connection (Arabic and English) | `mobile/www/offline.html` |
| The script the site loads only inside the app (save/share sheet, lock-screen player, microphone and notification questions, back button) | `public/js/listen-app.js` |
| "Is this request from the app?" and the list of purchase pages | `app/Support/InApp/InApp.php` |
| What the app says instead of "upgrade" lines | `lang/ar/in_app.php`, `lang/en/in_app.php` |
| Start page, sign-in hand-over, phone registration, link file | `app/Http/Controllers/AppShellController.php` |
| Notifications | `app/Services/Push/` |
| Tests | `tests/Feature/InApp/`, `mobile/android/app/src/test/`, `mobile/checks/` |

How the site knows it is in the app: the app adds `ListenApp/<version>` to its user agent.
No browser sends that, so no browser visitor can end up without prices by accident.

Sign-in with Google: Google refuses to sign people in inside an app's own web view, so the
button opens the phone's browser, the member signs in there, and the browser hands the app
a one-time code that only this app can use (it is tied to a secret the app made). Email
and password sign-in happens inside the app directly.

Which links open in the app: the studio, the demo, agents, dubbing and email confirmation.
The home page, pricing and billing links keep opening in the browser on purpose,
so a link in an advert or an invoice email still reaches the page that sells.

**Moving between pages.** Every page takes the server most of a second. The app hides that
wait: the next page starts loading when a finger touches its link, a thin line shows at once,
the pages cross-fade with the header held still, and going back shows the earlier page
instantly. Making the server itself answer faster would help the website and the app alike.

**Keeping the `hidden` setting true.** If a new page or a new "upgrade" prompt is added to the site
later, `tests/Feature/InApp/InAppModeTest.php` fails when it would show in the app. Fix it
by wrapping the block in `@inapp … @else … @endinapp` (or `@unless(InApp::active())`), or by
adding the line's key to `lang/*/in_app.php`. Links made with `route('pricing')`,
`route('billing.…')` and the like need nothing: inside the app they already become the
"My account" page, and only the menu shows that link.

---

## 7. Check on a real phone before publishing

These need a real phone and could not be checked where the app was built. Install the
debug APK (or the internal test) and go through them once:

- [ ] The app opens on the sign-in page in the phone's language; Arabic is right-to-left.
- [ ] Email sign-in works, and you are still signed in after closing the app fully and
      opening it again.
- [ ] "Continue with Google" opens the browser, and after choosing an account you land
      back in the app, signed in.
- [ ] With `ANDROID_APP_PLANS=hidden`: no price, plan to buy, or payment anywhere: studio,
      demo, agents, dubbing, menu, "My account".
- [ ] With `ANDROID_APP_PLANS=web`: choose a plan in the app; the browser opens on the
      billing page with that plan, signed in as you; no card form ever shows in the app.
- [ ] Generate a long text (ask first: it uses the shared speech quota). After you start,
      the app asks about notifications; say yes; leave the app; the notification arrives
      and opens the studio. (Needs steps 3 and 4.)
- [ ] Play a long audio, lock the screen: it keeps playing; the lock screen shows play,
      pause and the seek bar; a phone call or another music app pauses it; pulling out
      headphones pauses it.
- [ ] Download: "Save to phone" puts the file in Downloads/Listen with a clear name;
      "Share" opens the share sheet and the file arrives in a chat app.
- [ ] Voice cloning: recording asks for the microphone with an explanation; refusing and
      trying again offers the phone's settings.
- [ ] Talk to a voice agent: the microphone works and you hear it.
- [ ] Airplane mode: the "no connection" page shows in the right language; "Try again"
      works when the connection is back.
- [ ] The back button closes menus first, goes back a page, and from the studio leaves
      the app without closing it.
- [ ] A link to the contact page's chat number or a social page opens outside the app.
- [ ] After step 6.4: tap a studio link in an email, it opens in the app.
- [ ] Light and dark: the status bar matches the page in both.

---

## 8. The checks that come with it

- `php artisan test tests/Feature/InApp` (runs with the site's other tests on every pull
  request): no purchase path on any page the app shows, the sign-in hand-over, phone
  registration, notifications, the link file, and that this folder never goes to the servers.
- The "Android build" workflow: lint, the app's unit tests, the debug APK and the release bundle.
- `mobile/checks/` (run by hand, on a local copy of the site only, with the test members of
  `seed.php`; they open pages and make no voice and no payment):
  - `in-app-pages.js` opens every member page as the app does, at 360×800 and 412×915, in
    Arabic and English, light and dark, and fails on a purchase link, selling words,
    sideways scrolling, anything cut off, the wrong direction or theme, or a script error.
    It keeps a screenshot of each page in `checks/out/`.
  - `page-script.js` runs the app's page script against a stand-in for the phone: the
    save/share sheet, the lock-screen player, the microphone and notification questions,
    the back button, the sign-in button.
