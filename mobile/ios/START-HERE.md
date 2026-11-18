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
emails (verification, password reset): same site → **Services** → **Sign in with Apple
for Email Communication** → Configure → **+** → add the domain `listenai.studio` and the
exact address Listen's emails come from (the "from" address on any email the site sent
you). Without this, emails to those members are dropped by Apple.

## 3. App Store Connect (15 minutes)

https://appstoreconnect.apple.com

**The key GitHub uses to build and upload.** Users and Access → **Integrations** →
**App Store Connect API** → Team Keys → **+**

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

## 5. The site: merge, deploy, settings (the order matters)

The iPhone app shows the live site, so the site's side must be live before anyone tries
the app. Say "yes merge it" for each when you are ready; I merge nothing without it.

| Order | Pull request | After merging |
|---|---|---|
| any time | #356 Apple's rules and this package | nothing to deploy |
| any time | #363 No empty band above the studio's tabs | deploy |
| 1 | #357 The site knows the iPhone app | deploy |
| 2 | #359 Links and notifications for iPhones | deploy |
| 3 | #360 The question before a first voice | deploy, then migrate |
| 4 | #361 Members can delete their own account | deploy |
| 5 | #362 Sign in with Apple | deploy, then migrate |
| 6 | #358 The iPhone app itself and its build | nothing to deploy (it never goes to the servers) |

"Deploy" is the **Server deploy** workflow as always: `inspect`, then `deploy`, staging
first, then live. "Migrate" is `php artisan migrate --force` by hand on the server; these
two add three columns to the members table (`ai_consent_at`, `apple_id`,
`apple_refresh_token`) and change nothing else.

**Server settings** (the `.env` file on staging and on live), added once, after #359 is
deployed:

```
APNS_TEAM_ID=          the Team ID (step 1)
APNS_KEY_ID=           the Key ID of the "Listen server" key (step 2)
APNS_PRIVATE_KEY=      the "Listen server" key file as one line (the PowerShell line of step 4, with this file's name)
IOS_APP_BUNDLE_ID=studio.listenai.app
```

Then `php artisan config:clear`. The same key serves notifications and Sign in with Apple.

Three checks after that (I can run the first two for you once it is live):

- `https://listenai.studio/.well-known/apple-app-site-association` shows the Team ID
  followed by `.studio.listenai.app`. (Empty `details` means the Team ID is not set.)
- On the server: `curl --http2 -sI https://api.push.apple.com | head -1` prints a line
  starting with `HTTP/2`. Notifications to iPhones need it.
- The Android app still looks and works as before (it should: the changes only add an
  iPhone case).

Account deletion needs one more thing on **live only**, which is already there:
`LISTEN_STRIPE_TRIAL_ACTIONS=true`. With it, deleting an account cancels a running card
subscription on the spot, as you chose. On staging it stays off, so nothing there can
touch a real customer's subscription: staging refuses to delete an account that is paying.

## 6. The first build, onto a real iPhone (TestFlight)

After #358 is merged: GitHub → **Actions** → **iOS build** → **Run workflow** → branch
`main` → tick **Sign the build and send it to TestFlight** → Run.

- It takes about 45 minutes and costs about 3 US dollars of GitHub's Mac time (two Mac
  jobs). An ordinary run without the tick is about 1 to 2 dollars.
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
