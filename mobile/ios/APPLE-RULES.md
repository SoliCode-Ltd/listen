# The iPhone app: Apple's rules, as read on 17 November 2026

Before any code for the iPhone app, Apple's current rules were read on Apple's own pages
(not from memory), because they change often. This file says what each rule asks, what it
means for Listen, and what is done about it. The App Review Guidelines page said
"Last Updated: October 16, 2026" on the day it was read.

Nobody can promise a first-time approval. The aim is that every known reason for a
rejection is found and closed before submitting, and that what stays uncertain is said
plainly. Section 3 lists what stays uncertain.

---

## 1. What needed a decision from the owner

These four change what gets built. **The owner accepted all four recommendations on 17
November 2026**; each section ends with what was decided and built.

### 1.1 Signing in (guideline 4.8)

**The rule.** An app that offers a sign-in through another company (Apple's list names
Google first) must also offer, as an equal choice, a sign-in that limits data to name and
email, lets people keep their email private, and does not follow them for advertising. In
practice that is "Sign in with Apple". It is not required when the app uses only the
company's own accounts (email and password).

**For Listen.** The app offers "Continue with Google". So the iPhone app must do one of:

| Choice | What members see on iPhone | Cost |
|---|---|---|
| **A. Add "Sign in with Apple" beside Google, in the iPhone app only** (recommended) | Google, Apple, email | Apple's name and button on the sign-in page inside the iPhone app. That needs the owner's approval, as the Google Play badge did. More to build and to set up in the Apple account. |
| B. No Google button in the iPhone app | Email and password only. A member who joined with Google taps "Forgot your password?" once to choose a password. | Nothing to approve, nothing to set up. Less convenient for members who joined with Google. |

**Decided: A.** The sign-in and sign-up pages inside the iPhone app show "Continue with
Apple" beside "Continue with Google", with Apple's own button artwork. Recorded in
`CLAUDE.md` as an approved exception to "no other company named", for those pages of the
iPhone app only.

### 1.2 Paying (guidelines 3.1.1 and 3.1.3)

**The rule.** Anything that unlocks features inside an app must be sold with Apple's
in-app purchase (Apple keeps 15% for businesses under 1 million US dollars a year, 30%
above). Outside the United States store, an app may not show a button, link or wording
that leads people to pay somewhere else. An app that works across platforms may let
people use what they bought on the website, and here is the hard part: 3.1.3(b) adds
"provided those items are also available as in-app purchases within the app".

**For Listen.** Plans are bought on the website. The iPhone app will show no price, no
plan to buy and no link to one, exactly like the Android app in its `hidden` setting. Two
differences from Android:

- The "Upgrade" button on the account page (which opens the billing page in the browser)
  **cannot exist on iPhone**. It is a call to pay outside the app.
- The Android setting can be switched to `web` later without a new version. On iPhone
  that switch is **not offered at all**: changing what an app does after review is
  grounds for removing the app and closing the developer account (2.3.1, 2.5.19).

**What stays uncertain.** Many apps for a paid web service are approved as "sign in and
use, nothing sold here". Some are rejected under 3.1.1 with "your app gives access to paid
features that cannot be bought with in-app purchase". Which one happens depends on the
reviewer. The only way to remove this risk completely is to sell the plans with Apple's
in-app purchase inside the iPhone app, which is a large piece of work (Apple's payment
screens, Apple's prices, a second billing system beside Stripe, and Apple's 15%).

**Recommendation.** Submit without in-app purchase. If Apple asks for it, decide then:
the app is built so that it can be added. This is the most likely reason for a first
rejection, together with 1.4 below.

**Decided: as recommended.** No price, no plan to buy and no "Upgrade" button anywhere in
the iPhone app, and no setting that could switch them on.

### 1.3 Telling people where their text and voice go (guideline 5.1.2(i))

**The rule.** "You must clearly disclose where personal data will be shared with third
parties, including with third-party AI, and obtain explicit permission before doing so."

**For Listen.** The text a member types and the recordings for voice cloning go to the
speech provider. The privacy policy says so in general words ("our speech and translation
processing providers"). Apple asks for more inside the app: a clear notice and a yes from
the member before anything is sent.

**What will be built.** The first time a member is about to make a voice in the iPhone
app, one sheet: what is sent (the text, or the recording), to whom, for what, with
"Agree" and "Not now". Nothing is sent before "Agree".

**The decision.** The owner's rule is that the speech provider is never named on the
site. Apple's wording ("where", "third-party AI") suggests reviewers may expect the name.
Two ways:

- **Name the provider in that one sheet inside the iPhone app** (safest with Apple).
- Keep general words ("our AI speech provider") and accept that a reviewer may ask for
  the name.

**Decided: name the provider**, on that one page of the iPhone app and nowhere else
(`resources/views/app/consent.blade.php`; recorded in `CLAUDE.md`). The page comes before
the member's first use of the studio, the voices they make and the agents, and the member
can read it again and withdraw from the account page.

### 1.4 "More than a website" (guideline 4.2)

**The rule.** "Your app should include features, content, and UI that elevate it beyond a
repackaged website."

**For Listen.** The app shows the site, so this is the standing risk for this kind of
app. What it adds, and what the review notes will say: audio that keeps playing with the
screen locked, with lock-screen and Control Centre controls; a notification when a long
voice is ready; saving to Files and the share sheet; the microphone with the phone's own
permission; links to the studio that open in the app; its own opening screen and "no
connection" page; the bar of tabs at the bottom instead of a website menu; shortcuts on
the app's icon (press and hold: New voice, My voices, Agents). No decision needed; the
risk is named here because it cannot be brought to zero.

---

## 2. Every rule checked, and how it is handled

| Rule | What Apple asks | How Listen handles it |
|---|---|---|
| Build tools (Upcoming Requirements) | Since 28 April 2026 uploads must be built with **Xcode 26 or later** and the **iOS 26 SDK**. Apps must target iOS 13 or later. | Built on GitHub's `macos-26` machine (Xcode 26.6). The app asks for iOS 16.4 or later: Capacitor 8 supports iOS 15, but the site's in-app pages use CSS that older iPhone systems cannot draw. |
| 2.1 App completeness | Final build, no placeholders, working links, backend switched on, **a demo account** when there is a sign-in. | A reviewer account on the live site, on a paid plan so the studio opens (see the owner's list). |
| 2.3 Accurate metadata | Screenshots show the app in use (not a sign-in or opening screen). No prices in name, subtitle or screenshots. Name at most 30 characters. | Screenshots are made from the app's real pages. No price anywhere. |
| 2.3.10 Other platforms | No name, icon or picture of another mobile platform or app store in the app or its listing. | The Google Play badge is in the website footer, which the app never shows; the iPhone app's pages and listing are checked for "Android" and "Google Play". |
| 2.3.1, 2.5.19 Hidden features | Nothing hidden or switched on after review. | No `web` plans switch for iPhone (1.2). |
| 2.4.1 iPad | "iPhone apps should run on iPad whenever possible." | The app is for iPhone; on an iPad it runs in iPhone size. It is opened in an iPad simulator before submitting, because reviewers often use an iPad. No iPad screenshots are needed for an iPhone-only app. |
| 2.5.4 Background | Background modes only for their purpose. | One mode, `audio`, used only while a voice-over plays. |
| 2.5.5 IPv6 | Must work on IPv6-only networks. | The app only talks to the site by name over HTTPS; nothing in it holds an IPv4 address. |
| 2.5.6 Web | Web content must use WebKit. | Capacitor uses WKWebView. |
| 2.5.14 Recording | Ask clearly before recording; show that recording is on. | The phone's own microphone question with a purpose sentence; the page shows its recording state and iOS shows its orange dot. |
| 1.2 Content made by users, and AI content apps | Filtering of objectionable material, a way to report, blocking abusive users, published contact details. | The service already refuses abusive wording and can block an account; "Help and contact" is on the account page. Said in the review notes. |
| 3.1.1, 3.1.3 Payments | See 1.2 above. | No price, plan, purchase or link to one. |
| 4.2 Minimum functionality | See 1.4 above. | Native features plus review notes. |
| 4.8 Sign-in | See 1.1 above. | Email only, or Apple beside Google. |
| 4.5.4 Notifications | Not required to use the app; no marketing without opt-in. | Asked only after a long job is started; only "your voice is ready" messages. |
| 5.1.1(i) Privacy policy | A link in App Store Connect **and inside the app**. | Account page → Privacy policy. |
| 5.1.1(ii) Purpose strings | Each permission explains its use clearly. | Microphone, in Arabic and English. Nothing else is asked for (no camera, photos, location, contacts, tracking). |
| 5.1.1(v) Account deletion | An app that lets people create an account must let them **start deleting it inside the app**. Sending people to email, chat or a support form is not accepted. A manual process that takes a reasonable time is accepted if the person is told how long. | **The site must change.** Today "Delete my account" opens the contact form. A real page is needed: Account → Delete my account → confirm → the account and its data are deleted. See section 4. |
| 5.1.1(v) Sign-up | Sending people to the browser to register is "not appropriate". | Registering happens inside the app. "Continue with Google" (if kept) uses the system sign-in sheet that stays in the app, not the Safari app. |
| 5.1.2(i) Sharing data, AI | See 1.3 above. | A consent sheet before the first voice. |
| Privacy manifest | A `PrivacyInfo.xcprivacy` file listing collected data and each "required reason" API used. Capacitor is on Apple's list of SDKs that must bring their own manifest. | The app has its own manifest; Capacitor 8 ships one. Firebase is **not** used on iPhone (notifications go straight to Apple's service), so no Google SDK is in the app. |
| App Privacy answers | Data collected through the web view must be declared too. | The answers mirror the Play "Data safety" answers; given field by field in the submission guide. No tracking. |
| Export compliance | Only the phone's own HTTPS encryption is "exempt". | `ITSAppUsesNonExemptEncryption` = NO in the app, so the question is not asked at each upload. |
| Age rating | New questionnaire (4+, 9+, 13+, 16+, 18+). | Answers given in the submission guide. No user-to-user content, no chat between users, no ads, no open web browsing. |
| Screenshots | At least one for "iPhone with Dynamic Island (medium display)": 1179×2556 or 1206×2622. Larger displays (1320×2868 and others) are optional and scaled from it otherwise. Up to 10, PNG or JPEG, no transparency. | Made at 1206×2622 and 1320×2868, Arabic and English. |
| EU Digital Services Act | Trader status must be declared for the app to be available in the EU. | The owner declares it in App Store Connect (address, phone, email of SoliCode Ltd). |

---

## 3. What cannot be made certain

1. **Payments (1.2).** A reviewer may ask for in-app purchase.
2. **"More than a website" (1.4).** A reviewer may still find the app too close to the site.
3. **The consent page (1.3).** The provider is named and a yes is asked; a reviewer could
   still want other wording or another place for it.
4. **A real iPhone.** Neither the owner nor the builder has one. Lock-screen audio,
   notifications arriving, the microphone, saving and sharing, and sign-in returning to the
   app can only be confirmed by the TestFlight tester. Apple reviews on real devices.

If Apple rejects, the message names the guideline; the answer is a reply in App Store
Connect (and a new build if needed). A rejection does not harm the account.

---

## 4. What the site must add (each in its own pull request)

1. **Know the iPhone app** as it knows the Android one (its name in the user agent ends
   with `(iOS)`), with plans always hidden and no "Upgrade" button.
2. **Account deletion that a member completes alone** (5.1.1(v)), on the website and in
   both apps. The existing admin deletion refuses accounts with a live card subscription
   (Stripe would keep charging). The member's page must therefore either cancel the
   subscription as part of deleting, or tell the member to cancel first and link to the
   place. **Decided:** deleting cancels a running card subscription on the spot, with no
   refund, and the page says so before the member confirms.
3. **Links that open in the app** (`/.well-known/apple-app-site-association`).
4. **Notifications to iPhones**, sent straight to Apple's service with a key from the
   Apple developer account.
5. **The consent sheet** of 1.3.
6. **Sign in with Apple** (1.1 A, chosen).

---

## 5. Sources (all read on 17 November 2026)

- App Review Guidelines: https://developer.apple.com/app-store/review/guidelines/
- Offering account deletion: https://developer.apple.com/support/offering-account-deletion-in-your-app/
- Upcoming requirements (Xcode and SDK minimums): https://developer.apple.com/news/upcoming-requirements/
- Third-party SDK requirements: https://developer.apple.com/support/third-party-SDK-requirements/
- Privacy manifest files: https://developer.apple.com/documentation/bundleresources/privacy-manifest-files
- Required reason API: https://developer.apple.com/documentation/bundleresources/describing-use-of-required-reason-api
- App privacy details: https://developer.apple.com/app-store/app-privacy-details/
- Export compliance: https://developer.apple.com/documentation/security/complying-with-encryption-export-regulations
- Screenshot specifications: https://developer.apple.com/help/app-store-connect/reference/screenshot-specifications/
- Age ratings: https://developer.apple.com/help/app-store-connect/reference/age-ratings-values-and-definitions/
- Enrolment: https://developer.apple.com/programs/enroll/
- Small Business Program (15%): https://developer.apple.com/app-store/small-business-program/
- Capacitor on iOS (iOS 15+, Xcode 26+): https://capacitorjs.com/docs/ios
- GitHub's macOS build machines: https://github.com/actions/runner-images
- GitHub Actions prices (macOS: 0.062 US dollars a minute): https://docs.github.com/en/billing/reference/actions-runner-pricing
