# Start here: getting Listen onto the App Store

This is your list, in order. Each step says what to click and what to type. You need no
Mac and no iPhone for any of it, except step 6, where someone with an iPhone tries the app.

- What Apple's rules ask and how each is handled: `APPLE-RULES.md`.
- What to type in every box of the listing: `APP-STORE-LISTING.md`.
- What the person with the iPhone checks: `TESTFLIGHT-CHECKLIST.md`.
- Every known reason for a rejection and where it stands: `PRE-SUBMISSION-AUDIT.md`.

Two things to know before you start:

1. **Secrets never go in chat, email or the code.** The two key files Apple gives you
   (they end in `.p8`) go only where the steps below say: one into GitHub's secrets, one
   onto the server. Keep a copy of each in your password manager; Apple lets you download
   each one only once.
2. **Nobody can promise that Apple approves the first time.** Everything that is known to
   cause a rejection has been closed or is named in `PRE-SUBMISSION-AUDIT.md`. The two
   that cannot be brought to zero are "this is a website in an app" (4.2) and "paid
   features without Apple's payment" (3.1.1). Section 9 says what to answer if either comes.

---

## 1. The Apple developer account (you are doing this now)

Enrol **SoliCode Ltd** as an organisation at https://developer.apple.com/programs/enroll/
(99 US dollars a year; Apple checks the company through its D-U-N-S number, which can take
a few days). When Apple's email says the membership is active, go on.

Then, once: https://developer.apple.com/account → **Membership details**. Copy the
**Team ID** (ten letters and digits). You will type it in steps 3 and 4.

## 2. Tell Apple about the app (developer.apple.com, 10 minutes)

https://developer.apple.com/account/resources → **Identifiers** → **+**

1. Choose **App IDs** → Continue → **App** → Continue.
2. Description: `Listen`. Bundle ID: **Explicit**, `studio.listenai.app`.
3. In the list of capabilities tick these three, nothing else:
   - **Associated Domains** (links to the site open in the app)
   - **Push Notifications**
   - **Sign in with Apple** (leave it as "Enable as a primary App ID")
4. Continue → **Register**.

Then the key the **server** uses: same page → **Keys** → **+**

1. Key name: `Listen server`.
2. Tick **Apple Push Notifications service (APNs)**. If it asks: environment
   **Sandbox & Production**, key restriction **Team Scoped (All Topics)**.
3. Tick **Sign in with Apple** → Configure → primary App ID: `studio.listenai.app` → Save.
4. Continue → **Register** → **Download**. The file is `AuthKey_XXXXXXXXXX.p8`; the ten
   characters are the **Key ID**. Save the file in your password manager now: it cannot be
   downloaded again.

Last, so that members who hide their email with "Sign in with Apple" still get Listen's
emails (verification, password reset): same site → **Services** in the side list →
**Sign in with Apple for Email Communication** → **Configure** → under "Email Sources"
press **+** → add the domain `listenai.studio` and the address Listen's emails come from
(`help@listenai.studio`, unless the server's settings say another; it is the "from"
address on any email the site sent you). Apple accepts the domain only
if its emails are signed in the usual way (SPF or DKIM), which a working mail service
already does; the row shows a green tick when it passes. Without this, emails to those
members are dropped by Apple.

## 3. App Store Connect (15 minutes)

https://appstoreconnect.apple.com

**The key GitHub uses to build and upload.** Users and Access → **Integrations** →
**Team Keys** → **Generate API Key** (or **+** if a key exists already)

1. Name: `GitHub build`. Access: **Admin** (Apple needs this role to make the signing
   certificate by itself, so no certificate is ever stored anywhere).
2. Generate → **Download** the `.p8` file (once only; password manager). Note the
   **Key ID** on its row and the **Issuer ID** at the top of the page.

**The app's record.** Apps → **+** → New App: the values are in `APP-STORE-LISTING.md`,
section 1.

## 4. Four secrets in GitHub (5 minutes)

GitHub → the `listen` repository → Settings → Secrets and variables → Actions →
**New repository secret**, four times:

| Name | Value |
|---|---|
| `APPLE_TEAM_ID` | the Team ID from step 1 |
| `APPSTORE_API_KEY_ID` | the Key ID of the `GitHub build` key (step 3) |
| `APPSTORE_API_ISSUER_ID` | the Issuer ID (step 3) |
| `APPSTORE_API_KEY_P8_BASE64` | the `GitHub build` key file, turned into one line of text (below) |

To turn the file into one line on Windows: open **PowerShell**, paste this with the real
file name, press Enter. The line is then on your clipboard; paste it as the secret's value.

```
[Convert]::ToBase64String([IO.File]::ReadAllBytes("$HOME\Downloads\AuthKey_XXXXXXXXXX.p8")) | Set-Clipboard
```

## 5. The site: done on 18 November 2026; the server settings wait for the key

**Already done, nothing for you to do here.** All nine pull requests were merged on 18
November (#356 to #364), deployed with the **Server deploy** workflow (`inspect`, then
`deploy`, staging first, then live), and the two migrations were run on both servers.
They added three columns to the members table (`ai_consent_at`, `apple_id`,
`apple_refresh_token`) and nothing else.

What that changed for people today:

- **Website:** members can delete their own account (Account → Delete my account). The
  yearly offer bar is unchanged on the website.
- **Android app:** the offer bar no longer shows inside it, even to a visitor; the empty
  band above the studio's tabs is gone; "Delete my account" works inside the app.
- **iPhone app:** everything it needs from the site is live and waits for it: the Apple
  sign-in button, the question before a first voice, links into the app, notifications.

Checked after the deploy: live answers normally in both languages; the offer bar is on
the website and in neither app; the Apple button shows in the iPhone app only; the link
file answers (with empty `details` until the Team ID is set, below).

What is left on the servers is only this, once you have the key from step 2.

**Server settings** (the `.env` file on staging and on live). The key file must never
be sent in chat or email: when you have it, tell me and I will walk you through putting
it there yourself.

```
APNS_TEAM_ID=          the Team ID (step 1)
APNS_KEY_ID=           the Key ID of the "Listen server" key (step 2)
APNS_PRIVATE_KEY=      the "Listen server" key file as one line (the PowerShell line of step 4, with this file's name)
IOS_APP_BUNDLE_ID=studio.listenai.app
```

Then `php artisan config:clear`. The same key serves notifications and Sign in with Apple.
Do not add `APNS_SANDBOX`: builds from TestFlight and the App Store use Apple's real
notification service, which is what the site uses when that line is absent.

Then three checks (I run the first two for you):

- `https://listenai.studio/.well-known/apple-app-site-association` shows the Team ID
  followed by `.studio.listenai.app`. (Empty `details` means the Team ID is not set.)
- On the server: `curl --http2 -sI https://api.push.apple.com | head -1` prints a line
  starting with `HTTP/2`. Notifications to iPhones need it.
- The Android app still looks and works as before.

Account deletion needs one more thing on **live only**, which is already there:
`LISTEN_STRIPE_TRIAL_ACTIONS=true`. With it, deleting an account cancels a running card
subscription on the spot, as you chose. On staging it stays off, so nothing there can
touch a real customer's subscription: staging refuses to delete an account that is paying.

## 6. The first build, onto a real iPhone (TestFlight)

After #358 is merged: GitHub → **Actions** → **iOS build** → **Run workflow** → branch
`main` → tick **Sign the build and send it to TestFlight** → Run.

- It takes about 50 minutes and costs about 3 US dollars of GitHub's Mac time (two Mac
  jobs: the checks, then the signing and upload). A run without the tick is about half an
  hour and 2 dollars.
- **This is the one part that could not be tried before your Apple account existed.** The
  checks half is proven; the signing and upload half runs for the first time here. If it
  goes red, send me the run's link: it is a fix on my side, not something you did wrong.
  No iPhone has to be registered in the Apple account for it, and no certificate is stored
  anywhere: Apple makes and keeps the signing certificate itself.
- When it is green, the build shows in App Store Connect → the app → **TestFlight** after
  10 to 30 minutes ("Processing", then ready).

Who tries it: the person needs an iPhone with iOS 16.4 or later (an iPhone 8 or newer) and
the free **TestFlight** app.

- App Store Connect → Users and Access → **+** → their email, role **Developer**, access
  to this app only. They accept the invitation by email.
- TestFlight tab → **Internal Testing** → **+** → a group named `Team` → add them → add
  the build. They get an email with the link to install.

Give them `TESTFLIGHT-CHECKLIST.md`. It is the only check of the things that need a real
iPhone: sound with the screen locked, a notification arriving, the microphone, saving a
file, signing in. **These were not checked by me and cannot be without an iPhone.**

## 7. The reviewer's account

Apple's reviewer needs to sign in and see everything. Make a new member for Apple (not the
one Google's reviewers use, so each stays clean):

1. On the live site, register a member with your Gmail address with `+apple-review` before
   the `@`, and a strong password you keep.
2. In the admin panel, give that member the plan that opens every feature, as you did for
   Google's reviewer, and create one voice agent in it (the reviewer is told to open it).
3. Do not open the studio with it in the iPhone app: it should still be asked the question
   before a first voice when the reviewer signs in.

Its email and password go in App Review Information (`APP-STORE-LISTING.md`, section 7).

## 8. Fill in the listing

App Store Connect → the app. Everything is in `APP-STORE-LISTING.md`, page by page: the
words in both languages, the pictures (`mobile/ios/store/`), the age rating, App Privacy,
price and countries, the review notes.

## 9. Send it to Apple

On the version page: choose the build from TestFlight → **Add for Review** → **Submit**.
Reviews usually take one to three days. Apple writes to the account's email and in App
Store Connect → the app → **App Review**.

Before you press Submit, `PRE-SUBMISSION-AUDIT.md` must have no open line, the tester's
checklist must be done, and the site's side must be live (step 5).

**If Apple rejects it**, send me the message as it is before answering. The likely ones:

- **4.2 "Minimum functionality" (it is a website).** The answer lists what the app adds
  (the review notes already do) and offers a short video from the tester's phone: sound
  on the lock screen, a notification, saving a file.
- **3.1.1 "In-app purchase" (paid features bought elsewhere).** The app sells nothing and
  shows no price or link, and new members can use it free. If Apple still insists, the
  choice is yours: sell the plans through Apple's payment inside the iPhone app (a large
  piece of work, and Apple keeps 15%), or keep the app for existing members only. Nothing
  is decided until then.
- **5.1.2 "Who receives the data".** The page before a first voice names the provider and
  asks for a yes; the notes say where it is.
- **2.1 "We could not sign in" or "something did not load".** Usually the reviewer's
  account or a server problem at that moment: check the account works, answer, resubmit.

After approval the app is not public until you press **Release this version** (the
listing is set to manual release).

---

## Later versions

Changes to the site show in the app at once and need no new version. A new version is
needed only when the app itself changes (`mobile/ios/`). Then: Actions → iOS build → Run
workflow with **upload** ticked and the new version name (for example `1.0.1`); the build
number goes up by itself.

Never change what the app shows about plans, prices or payment through the site after
approval. Apple treats behaviour that changes after review as grounds for removing the
app and closing the developer account (2.3.1, 2.5.19). On iPhone that switch does not
exist, on purpose.
