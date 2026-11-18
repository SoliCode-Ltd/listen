# The App Store listing: what to type in each box

Every value for App Store Connect, in the order its pages ask. Copy each as it is. Where
there are two languages, the listing has two pages: **English (U.S.)**, which is the main
one, and **Arabic**, which you add with the language menu at the top of the version page.

Nothing here names another company or product, and nothing mentions a price or a plan to
buy: Apple rejects listings that do (guideline 2.3), and it is your own rule too.

`START-HERE.md` says when to do this (step 8).

---

## 1. Creating the app record (once)

App Store Connect → **Apps** → **+** → **New App**.

| Box | Value |
|---|---|
| Platforms | **iOS** only |
| Name | `Listen: Arabic AI Voice Studio` |
| Primary language | **English (U.S.)** |
| Bundle ID | choose `studio.listenai.app` from the list (it appears after step 2 of `START-HERE.md`) |
| SKU | `listen-ios-1` (only you ever see it) |
| User access | **Full Access** |

If Apple says the name is taken, use `Listen: Arabic Voice Studio AI` and tell me, so the
texts stay the same everywhere.

---

## 2. The words people see

| Box (limit) | English (U.S.) | Arabic |
|---|---|---|
| Name (30) | `Listen: Arabic AI Voice Studio` | `إسمع: استوديو الصوت العربي` |
| Subtitle (30) | `Text to speech in your dialect` | `حوّل النص إلى صوت بلهجتك` |

**Promotional text (170). English**

```
Write your text, pick a dialect and a voice, and hear a natural voice-over in seconds. Keep listening with the screen locked, then save or share the file.
```

**Promotional text. Arabic**

```
اكتب نصك، اختر اللهجة والصوت، واسمع تعليقاً صوتياً طبيعياً في ثوانٍ. تابع الاستماع والشاشة مقفلة، ثم احفظ الملف أو شاركه.
```

**Description. English**

```
Listen is the Arabic AI voice studio. Write your text, choose the dialect, the voice and the emotion, and get a natural voice-over in seconds.

WHAT YOU CAN DO
• Turn text into speech in Modern Standard Arabic and the Arabic dialects, and in English with its accents
• Direct the performance: emotion, style, pace and pauses
• Write dialogues with more than one voice
• Clone your own voice from a short recording (where your account includes it)
• Design any voice by describing it in a sentence (where your account includes it)
• Set up voice agents that answer your customers in their dialect

IN THE APP
• Keep listening with the screen locked, with play and pause on the lock screen
• Get a notification when a long voice is ready
• Save the audio to your phone or share it straight to another app
• Record your voice with the microphone
• Press and hold the app's icon to jump to a new voice-over, the voices or your voice agents
• Arabic and English, light and dark

Sign in with your Listen account, or create one in the app.

Your audio is made by AI. Before your first voice, the app tells you what is sent, who processes it and why, and asks for your agreement.

Privacy: https://listenai.studio/en/privacy-policy
Terms: https://listenai.studio/en/terms-of-service
```

**Description. Arabic**

```
إسمع هو استوديو الصوت العربي بالذكاء الاصطناعي. اكتب نصك، اختر اللهجة والصوت والمشاعر، واحصل على تعليق صوتي طبيعي في ثوانٍ.

ماذا يمكنك أن تفعل
• تحويل النص إلى صوت بالعربية الفصحى واللهجات العربية، وبالإنجليزية بلكناتها
• التحكم في الأداء: المشاعر، الأسلوب، السرعة، والوقفات
• كتابة حوارات بأكثر من صوت
• استنساخ صوتك من تسجيل قصير (إذا كان حسابك يشمله)
• تصميم أي صوت بوصفه في جملة (إذا كان حسابك يشمله)
• إعداد وكلاء صوتيين يردّون على عملائك بلهجتهم

في التطبيق
• تابع الاستماع والشاشة مقفلة، مع التشغيل والإيقاف من شاشة القفل
• إشعار عندما يجهز صوتك الطويل
• احفظ الملف على هاتفك أو شاركه مباشرة مع تطبيق آخر
• سجّل صوتك بالميكروفون
• اضغط مطوّلاً على أيقونة التطبيق للانتقال إلى تعليق صوتي جديد أو الأصوات أو وكلائك الصوتيين
• عربي وإنجليزي، وضع فاتح وداكن

سجّل الدخول بحسابك في إسمع، أو أنشئ حساباً من التطبيق.

صوتك يُنشأ بالذكاء الاصطناعي. قبل أول صوت، يخبرك التطبيق بما يُرسَل ومن يعالجه ولماذا، ويطلب موافقتك.

الخصوصية: https://listenai.studio/ar/privacy-policy
الشروط: https://listenai.studio/ar/terms-of-service
```

**Keywords (100, commas, no spaces after them)**

- English: `tts,voiceover,narration,read aloud,clone,cloning,accent,egyptian,saudi,gulf,podcast,audiobook`
- Arabic: `تعليق صوتي,قارئ نصوص,نطق,استنساخ,لهجات,مصري,سعودي,خليجي,بودكاست,كتاب صوتي,ذكاء اصطناعي,معلق`

(The words of the name and the subtitle are searched already, so they are not repeated.)

**Links and small boxes**

| Box | English | Arabic |
|---|---|---|
| Support URL | `https://listenai.studio/en/contact-us` | `https://listenai.studio/ar/contact-us` |
| Marketing URL | `https://listenai.studio/en` | `https://listenai.studio/ar` |
| Privacy Policy URL (App Privacy page) | `https://listenai.studio/en/privacy-policy` | `https://listenai.studio/ar/privacy-policy` |
| Copyright | `2026 SoliCode Ltd` | the same |
| Version | `1.0.0` | |
| What's New | empty (a first version has none) | |

**App Information page**

- Category: primary **Productivity**, secondary **Utilities**.
- Content rights: **No, it does not contain, show, or access third-party content.**
- Age rating: section 4 below.

---

## 3. The pictures

They are ready in `mobile/ios/store/`. Upload them as they are, in number order.

| Where in App Store Connect | Files |
|---|---|
| English page → iPhone 6.9" Display | `iphone-en-1320x2868-1…8.jpg` |
| English page → iPhone 6.3" Display (the one Apple requires; listed as 6.5"/6.3" in some menus) | `iphone-en-1206x2622-1…8.jpg` |
| Arabic page → the same two sizes | `iphone-ar-1320x2868-1…8.jpg`, `iphone-ar-1206x2622-1…8.jpg` |

- If the page shows only one iPhone size, give it the 1320×2868 set if it accepts it,
  otherwise the 1206×2622 set. Apple makes the other sizes from it.
- No iPad pictures: the app is for iPhone.
- Order: the studio, Arabic dialects, English accents, emotions, voice cloning, voice
  design, voice agents, voices in more languages. They are the same eight as on Google
  Play, taken again from the pages as the iPhone app shows them. No price is in any of them.
- Picture 8 shows the French, Spanish, German and other voices. Leave it out if members
  cannot make a voice in those languages yet.
- The app icon comes from the build itself; there is nothing to upload for it.
- To make them again after the app's look changes: `node mobile/ios/store/make-screenshots.js`
  (the top of that file says how). Apple asks that pictures show the app as it is.

---

## 4. Age rating (the questionnaire)

Answer what is true. With these answers Apple works out the rating itself (it should come
out as **4+**).

| Question | Answer |
|---|---|
| Parental controls | No |
| Age assurance | No |
| Unrestricted web access | No (the app opens only Listen's own pages) |
| User-generated content (shown widely to other users) | No (each member sees only their own work) |
| Social media | No |
| Messaging and chat (between users) | No |
| Advertising | No |
| Profanity or crude humour; horror or fear; alcohol, tobacco or drugs; medical or treatment information; mature or suggestive themes; sexual content or nudity; violence of any kind; guns or other weapons | **None** for every one |
| Gambling; simulated gambling; contests | No |
| Made for Kids | No |

If the page offers to set a higher age than the one it worked out, you may leave it.

---

## 5. App Privacy (the "nutrition label")

App Store Connect → the app → **App Privacy** → Get Started. These answers match the
privacy file inside the app (`PrivacyInfo.xcprivacy`); the two must say the same thing.
Read them against the privacy policy before you publish them: you are the one declaring them.

- **Do you or your third-party partners collect data from this app?** Yes.

Tick these data types. For every one, the three follow-up answers are the same:
**used for App Functionality** (location also for **Analytics**), **linked to the user's
identity: Yes**, **used for tracking: No**.

| Section → data type | What it is in Listen |
|---|---|
| Contact Info → Name | the member's name |
| Contact Info → Email Address | the account's email |
| Contact Info → Phone Number | only if phone sign-in is switched on; tick it anyway, the app's privacy file lists it |
| Location → Coarse Location | the country, worked out from the network address (App Functionality and Analytics) |
| User Content → Audio Data | voice-cloning recordings, calls with a voice agent |
| User Content → Photos or Videos | a video sent for dubbing (declared now so it is already true the day dubbing opens) |
| User Content → Other User Content | the texts turned into speech |
| Identifiers → User ID | the account's number |
| Identifiers → Device ID | this phone's notification token |

Not collected, leave unticked: payment and financial information (nothing is paid in the
app), precise location, contacts, health, browsing and search history, purchases, usage
data, diagnostics, advertising data. The app has no advertising and no analytics kit, and
inside the app the site leaves out its own analytics and advertising pixel.

Messages sent through "Help and contact" do not need declaring: Apple lets optional
support forms be left out.

---

## 6. Price and where it is offered

**Pricing and Availability**

- Price: **Free** (0.00).
- Countries or regions: all of them **except China mainland**. Apps that generate content
  with AI need a licence from the Chinese authorities to be in that store; leaving it
  unticked avoids a removal notice later.
- **Untick "Make this app available on Mac"** (Apple silicon Mac availability) and
  **"Apple Vision Pro"**. The app is checked for iPhone only.
- Pre-order: no.

**Digital Services Act (EU)**: App Store Connect → Business (or the banner on the app's
page) → declare **trader** status with SoliCode Ltd's address, phone number and email.
They are shown on the app's page in EU countries. Without this the app is not offered in
the EU.

**Agreements**: a free app needs only the free-apps agreement, which is accepted when you
enrol. No bank or tax forms are needed, because nothing is sold in the app.

---

## 7. App Review Information (bottom of the version page)

- **Sign-in required**: ticked. User name and password: the reviewer account (step 7 of
  `START-HERE.md` says how to make it).
- **Contact information**: your own name, phone number and email. Apple calls or writes if
  the reviewer has a question; answer quickly.
- **Notes**: paste this as it is.

```
Listen is an AI voice studio for Arabic and English. Members write a text and get a voice-over, clone their own voice, design a voice from a description, and set up voice agents. This is the iPhone app of the service at https://listenai.studio (also on Android).

SIGNING IN
The demo account above is an ordinary member account with every feature switched on. You can also create a new account in the app (email, Sign in with Apple, or Google).

AI AND PERSONAL DATA (5.1.2)
Before a member's first voice, the app shows a page that says what is sent (their text, recordings and uploads), who receives it (Google, through its AI services) and why, and asks "Agree and continue" or "Not now". Nothing is sent before they agree. If the demo account has not answered yet, you will see this page when you open the Studio tab. It can be read again and withdrawn at any time: Account tab > "How your text and voice are processed".

WHAT TO TRY
1. Studio tab: type a sentence and press "Generate Voice Now". Play the result, then lock the phone: it keeps playing, with play/pause on the Lock Screen and in Control Centre.
2. On the result: Save (the system sheet) and Share.
3. Studio > Voice Cloning: the microphone is asked for only when you press record.
4. Agents tab: open the agent and press "Talk to...": a live voice conversation with an AI agent.
5. Voices tab: samples of the voices.
6. Account tab: help, privacy, how your text and voice are processed, delete account.

ACCOUNT DELETION (5.1.1(v))
Account tab > "Delete my account". It is done inside the app, immediately, after a confirmation. Accounts made with Sign in with Apple have their Apple tokens revoked. If you want to complete a deletion, please do it with a new account that you create, so the demo account stays available to you.

PURCHASES
Nothing is sold in the app. It shows no prices, no plans to buy, and no links or calls to buy anywhere. New accounts get free credits to use the studio. Listen is also used on the web and on Android, and members sign in here with the account they already have.

WHAT THE APP ADDS TO THE WEB SITE (4.2)
Audio that keeps playing in the background with Lock Screen and Control Centre controls; a push notification when a long voice is ready; saving to Files and the share sheet; microphone recording with the system permission; universal links that open in the app; Home Screen quick actions (press and hold the icon); Sign in with Apple; its own launch and "no connection" screens; app navigation with a tab bar instead of a web menu, laid out for the iPhone's safe areas.

CONTENT
Speech is generated by AI from the member's own text. The service refuses abusive wording and can block accounts. Members see only their own work; nothing is shared between users inside the app. Contact and reports: Account tab > "Help and contact".

PERMISSIONS
Microphone: to record a voice sample for cloning and to talk to a voice agent, only when the member starts it. Notifications: optional, asked in context, never at launch. Camera: only if the member chooses to take a video in the system file picker.
```

- **Attachment**: none needed.

**Version Release**: choose **Manually release this version**, so nothing goes public
before you have looked at the approved build.

---

## 8. Questions App Store Connect asks when you press "Add for Review"

| Question | Answer |
|---|---|
| Export compliance (encryption) | Not asked: the build already says it uses only standard encryption (`ITSAppUsesNonExemptEncryption` = NO). If it is asked anyway: "None of the algorithms mentioned above". |
| Advertising identifier (IDFA) | No |
| Content rights | No third-party content (section 2) |
| Does the app use sign-in with a third party | Sign in with Apple is offered beside it (nothing to type; it is in the notes) |
