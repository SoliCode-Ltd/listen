# The Listen iPhone app

The iPhone app is the same product as the Android app (`mobile/android`): a small native
app that opens the live site, https://listenai.studio, and adds what a phone app should
have. What the app does inside is decided by the site, so a change to the site shows in
the app the same minute.

- **Apple's rules and what each means for Listen:** `APPLE-RULES.md` (read first).
- **How pages talk to the app:** `../BRIDGE.md`, section 5.
- **The owner's step-by-step guide, from the Apple account to "Submit":** `START-HERE.md`.
- **What to type in every box of the App Store listing, and the pictures:**
  `APP-STORE-LISTING.md`, `store/`.
- **What the person with a real iPhone checks:** `TESTFLIGHT-CHECKLIST.md`.
- **Every known reason for a rejection and where it stands:** `PRE-SUBMISSION-AUDIT.md`.

## What is here

| Part | Where |
|---|---|
| The Xcode project, written down (the project file is made from it at build time) | `App/project.yml` |
| The app: start-up, the one screen, the opening logo, "can't connect" | `App/App/AppDelegate.swift`, `SceneDelegate.swift`, `ShellViewController.swift`, `LaunchWaveView.swift`, `OfflineView.swift` |
| What the web view may show; who may talk to the app | `App/App/WebViewDelegates.swift`, `ListenBridgeViewController.swift` |
| What pages can ask the app to do (`ListenShell`) | `App/App/ListenShellPlugin.swift`, `AudioFiles.swift`, `SignIn.swift` |
| The rules that need no phone (site addresses, file names, sign-in secret, logo motion) | `App/App/Core/` |
| Settings a review looks at: permissions' reasons, background sound, privacy manifest, export compliance | `App/App/Info.plist`, `PrivacyInfo.xcprivacy`, `App.entitlements` |
| The app's own words, Arabic and English | `App/App/ar.lproj`, `en.lproj` |
| Unit tests | `App/AppTests/` |
| Running it in simulators and keeping pictures | `ci/simulator-shots.sh` |

## Building it

Nobody needs a Mac: GitHub builds it. **Actions → iOS build → Run workflow.**

- It runs the unit tests, runs the app in iPhone simulators (the smallest and the largest
  iPhone, and an iPad; Arabic and English; light and dark) and keeps a picture of each on
  the run's page.
- With **upload** ticked it also signs the build and sends it to TestFlight. That needs
  the four repository secrets named at the top of `.github/workflows/ios-build.yml`.
- A run costs roughly one US dollar (Mac machines are 0.062 dollars a minute at the time
  of writing), so it starts only when a pull request changes the iPhone app, or by hand.

On a Mac (Xcode 26 or later, Node 22, `brew install xcodegen`):

```
cd mobile
npm ci
npx cap copy ios                       # the app's bundled files
(cd ios/App && xcodegen generate)      # makes App.xcodeproj from project.yml
npx cap update ios                     # Capacitor's list of plugins
open ios/App/App.xcodeproj
```

The app asks for iOS 16.4 or later (an iPhone 8 or newer): the site's in-app pages use CSS
that older iPhone systems cannot draw.

Settings (build settings; the workflow passes them):

| Name | Meaning | Default |
|---|---|---|
| `LISTEN_APP_URL` | the site the app opens | `https://listenai.studio` |
| `MARKETING_VERSION` | the version people see | `1.0.0` |
| `CURRENT_PROJECT_VERSION` | the build number; must go up with every upload to Apple | `1` |

## What was checked, and what was not

- Checked where it was written: the rules in `App/App/Core/` (27 unit tests, the same
  cases as the Android app's), and that every Swift file is well formed.
- Checked on GitHub's Mac machine by the workflow: that the app builds, its unit tests,
  and that it starts in the simulators and shows the site.
- **Not checked, and it cannot be without a real iPhone:** sound that keeps playing with
  the screen locked and the lock-screen controls, a notification arriving, the microphone,
  saving and sharing a file, sign-in coming back to the app, a link to the site opening in
  the app. These are for the TestFlight tester.
