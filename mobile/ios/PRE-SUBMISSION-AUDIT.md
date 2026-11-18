# Before pressing "Submit": every known reason for a rejection

One line for each. "Closed" means it is built and was checked, and the line says how.
"For the tester" means it is built but can only be seen on a real iPhone. "Yours" means it
is a step only the owner can take. Nothing may stay open in the first three tables when
the app is submitted; the last table is what nobody can bring to zero.

Checked means one of these, named in each line:

- **tests**: the site's automated tests (they run on every pull request).
- **Mac build**: GitHub's Mac machine built the app with Xcode 26, ran its unit tests and
  started it in three simulators (smallest iPhone, largest iPhone, an iPad).
- **page check**: every page the app can show was opened on a local copy of the site as
  the iPhone app (its name, its screen sizes), in Arabic and English, light and dark, in
  two browser engines (Chromium and WebKit, the engine iPhones use), with rules that fail
  the check on a price, a purchase link, an "Upgrade" button, another platform's name,
  anything cut off or scrolling sideways, or a script error.
- **read**: the file was read against Apple's page.

Nothing was checked on a real iPhone. Nothing was checked in Apple's own consoles.

---

## 1. Closed

| Rule | The risk | Where it stands |
|---|---|---|
| 2.1 | The app crashes or shows a blank page at start | Closed. Mac build: 12 first openings in 3 simulators, each waits for the first page and fails the build if it does not come. |
| 2.1 | Placeholder text, broken links, "coming soon" | Closed. Page check found none; dubbing, which is not public, is not shown or advertised. |
| 2.3.1, 2.5.19 | Behaviour that can be switched after review (plans appearing later) | Closed. On iPhone the "show plans" setting does not exist; tests prove the iPhone app gets the hidden form whatever the setting says. |
| 2.3.3, 2.3.7 | Screenshots that are not the app; a price or "free" in name, subtitle or pictures | Closed. The pictures are the app's real pages; the maker fails on a price. Name and subtitle hold neither. |
| 2.3.8 | Listing material not suitable for everyone | Closed. Read. |
| 2.3.10 | Naming Android, Google Play or another store in the app or listing | Closed. Page check fails on those words; the footer's store badge is not part of the app's pages; the listing names none. |
| 2.4.1 | An iPhone app that fails on an iPad | Closed. Mac build starts it on an iPad simulator, where it runs in iPhone size. |
| 2.5.1, 2.5.2 | Private APIs; code downloaded to change the app | Closed. None used; the app shows the site's pages in the system web view, which Apple allows. |
| 2.5.4 | Background mode declared but unused | Closed in the build (only "audio", which the player uses). Its working is for the tester. |
| 2.5.14 | Recording without a clear sign and consent | Closed. The microphone is asked only when the member presses record or starts a call, with a reason in both languages; no page gets the camera except through the phone's own picker. |
| 3.1.1, 3.1.3 | A price, a plan to buy, an "Upgrade" button, or a link that leads to paying outside the app | Closed as far as it can be: tests and the page check prove none exists in the iPhone app, and every purchase page turns into the plain account page. What remains is in table 4. |
| 4.0, 4.2 | Looks like a browser | Closed as far as it can be: no address bar, own opening and "no connection" screens, a bar of tabs, pages kept clear of the notch and the home bar. What remains is in table 4. |
| 4.5.4 | Notifications used for advertising, or required to use the app | Closed. Asked in context, optional, only "your voice is ready". |
| 4.8 | Another company's sign-in without an equal private choice | Closed. "Continue with Apple" sits beside Google on sign-in and sign-up, in Apple's own artwork; tests cover the whole exchange with Apple's servers stood in for. |
| 5.1.1(i) | No privacy policy link in the app and on the listing | Closed. Account page, sign-in page and the listing. |
| 5.1.1(ii), 5.1.2(i) | Personal data sent to a third-party AI without saying who and asking | Closed. Before a member's first use, a page says what is sent, names who receives it and asks for a yes; until then the studio, voices and agents refuse to work (tests list every such route). It can be withdrawn. |
| 5.1.1(iii) | Asking for more than is needed | Closed. Name and email; Sign in with Apple may hide the email. |
| 5.1.1(v) | Account creation without deletion inside the app | Closed. Account page → "Delete my account" finishes inside the app, at once; a running subscription is cancelled and Apple's sign-in tokens are revoked. Tests cover each case. |
| 5.1.1(v) | Forcing an account to see anything | Closed. The voice samples can be heard without one; the rest is a member's own work. |
| 5.1.2 | Tracking without permission | Closed. No tracking, no advertising, no analytics kit; inside the app the site leaves its own analytics and advertising pixel out. |
| Privacy manifest | Missing file, or a "required reason" API without its reason | Closed. Mac build checks the file is in the app; it lists what is collected and the one reason code. |
| Export compliance | Upload held for the encryption question | Closed. The build declares standard encryption only. |
| Build tools | Built with an old Xcode | Closed. Xcode 26 on GitHub's `macos-26` machine. |
| Icon | Icon with transparency, or missing sizes (upload refused) | Closed. One 1024 px icon without transparency. |
| Purpose strings | A permission asked without a reason, or with a vague one | Closed. Microphone and camera reasons, in Arabic and English. |
| 1.2 | AI content without filtering, reporting and contact | Closed. The service refuses abusive wording and can block accounts; members see only their own work; "Help and contact" is on the account page. Said in the review notes. |
| 5.2 | Using others' names or marks | Closed. Only Apple's and Google's own sign-in buttons, as their rules ask. |

## 2. For the tester (built; needs a real iPhone to be seen)

Each is a section of `TESTFLIGHT-CHECKLIST.md`. A "no" on any of them is fixed before submitting.

| Rule | The risk | Checklist |
|---|---|---|
| 2.5.4 | Sound stops when the phone is locked, or no lock-screen controls | section 4 |
| 2.1 | Save or Share does nothing | section 5 |
| 2.1, 2.5.14 | The microphone is refused, or asked at the wrong moment | section 6 |
| 2.1 | A notification never arrives, or opens the wrong page | section 7 |
| 2.1 | Signing in with Apple or Google does not come back to the app | section 2 |
| 2.1 | A link to the site opens Safari instead of the app | section 8 |
| 5.1.1(v) | After deletion Apple still lists Listen under "Sign in with Apple" | section 10 |
| 2.1 | Keyboard covers the text box; text too large to use; Arabic cut off | section 11 |

## 3. Yours (steps of `START-HERE.md`)

| Rule | The risk | Step |
|---|---|---|
| 2.1 | The reviewer cannot sign in | 7: the reviewer's account, with every feature and one voice agent |
| 2.1 | The reviewer opens the app before the site knows the iPhone app (Google button without Apple's, no consent page, no deletion) | 5: all site pull requests merged and deployed to live, and the server settings in place, before the first TestFlight build is tried |
| 4.8, 2.1 | "Sign in with Apple" fails, or its emails never arrive | 2: the capability on the app's id, the key, and the email-sending domain |
| 2.1 | Notifications fail on live | 5: the `APNS_*` settings and the HTTP/2 check |
| 2.3 | Listing texts or pictures in the wrong box; missing Arabic page | 8: `APP-STORE-LISTING.md` |
| 5.1.2 | The privacy answers do not match the app | 8: section 5 of the listing file, as written |
| 2.3.6 | Wrong age rating | 8: section 4 of the listing file |
| EU law | Not offered in the EU | 8: trader status |
| — | The app also appears for Mac and Vision Pro, where it was never tried | 8: untick both |
| 5 | Offered in China mainland without the licence AI apps need there | 8: leave China mainland unticked |

## 4. What nobody can bring to zero

| Rule | Why it remains | If it happens |
|---|---|---|
| 3.1.1 | Listen has paid plans on the web. The app sells nothing and says nothing about them, and new members can use it free, but a reviewer may still ask for Apple's in-app purchase. Apple's own exception for "companion apps to paid web tools" names only a few kinds of service, and voice tools are not on that list. | Answer once with the facts in the review notes. If Apple insists: add Apple's in-app purchase (large work, Apple keeps 15%), or keep the app for existing members. The owner decides then. |
| 4.2 | The app shows the site. It adds real phone features, but "enough" is the reviewer's judgement. | Answer with the list in the review notes and a short video from the tester's phone. |
| 5.1.2(i) | The page names the provider and asks for a yes; a reviewer may want different wording or timing. | A wording change is a site change: no new build. |
| 2.1 | A fault on the day (the site slow, a reviewer on a poor network). | Fix, answer, resubmit. A rejection does not harm the account. |

---

## The last look, on the day

- [ ] Every site pull request is merged, deployed to live, and the two migrations are run.
- [ ] `https://listenai.studio/.well-known/apple-app-site-association` shows the Team ID.
- [ ] The tester's checklist is back with every line ticked, on the same build that is
      being submitted.
- [ ] The reviewer's account signs in on that build and reaches the question before a
      first voice.
- [ ] Android still works as before (the site changes touch pages it shows too).
- [ ] The build chosen on the version page is the one the tester tried.
