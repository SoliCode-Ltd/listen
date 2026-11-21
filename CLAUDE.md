# Working rules for this repository

These are the owner's standing instructions. Follow them in every session.

## Pull requests
- Give every PR a title that says plainly what it changes, so the PR list
  reads like a changelog. Good: "Footer: show only the two SEO hub links",
  "Billing: don't end a free trial without asking". Not: "Fixes", "Updates",
  "Misc changes", or a branch name.
- Never merge a PR until the owner says "yes merge it" (or clearly says yes to
  merging that PR).

## Site content
- Never mention other companies or products in the site's text or UI
  (for example competing voice platforms or the speech provider's name).
  The one exception the owner approved is NVIDIA Inception (Listen is a
  member): its badge and name under the homepage hero and in the footer, using
  NVIDIA's own badge file unchanged (`public/images/partners/`), never larger
  than Listen's logo when the two sit side by side, never under 30px (the
  owner chose 64px for the homepage strip, 54px on phones, 54px in the
  footer); and for search engines and AI tools,
  the membership in the structured data (`memberOf`) and in llms.txt.
  A second exception the owner approved (17 November 2026): the Google Play
  badge in the footer, leading to the app's page on the store. Google's own
  badge files, unchanged, in the page's language (`public/images/partners/`).
  The badge only: no trademark sentence beside it (the owner had it removed),
  and nowhere else on the site.
  A third exception the owner approved (17 November 2026), for the iPhone app
  only: the page that asks a member, before their first voice, whether their
  text and voice may be sent to the AI provider names that provider (Google:
  Gemini and Google Cloud), because Apple requires that people are told who
  receives their data. That one page inside the iPhone app
  (`resources/views/app/consent.blade.php`, `lang/*/app.php` → `consent`), and
  nowhere else on the site or in the Android app.
  A fourth exception the owner approved (17 November 2026), for the iPhone app
  only: "Sign in with Apple" on the sign-in and sign-up pages, beside "Continue
  with Google", because Apple requires its own sign-in wherever another
  company's is offered. Apple's own button pictures, unchanged
  (`public/images/partners/apple-*.png`), the same size as the Google button,
  and nowhere else on the site or in the Android app.
  A fifth exception the owner asked for (20 November 2026): the Apple Pay and
  Google Pay buttons in the extra-credits dialog in the studio, the same wallet
  button as the checkout (`<x-wallet-button>`, drawn by Stripe). The buttons
  only: no sentence naming them.
  A sixth exception the owner approved (21 November 2026): Microsoft Clarity
  (visit recordings and heatmaps) named in the privacy policy and the cookie
  policy, in English and Arabic (`lang/*/ui.php` → `legal.privacy.s5_clarity*`,
  `legal.cookies.c7_*`), together with Microsoft, Microsoft Advertising and the
  link to Microsoft's privacy statement, because Clarity's terms require them
  there. Nowhere else in the site's text. Its script runs only on the live
  site's public pages for visitors not signed in and on the free demo
  (`App\Support\Clarity`), never in the studio, for paying members, in the apps,
  or for visitors from the UK, the EEA or Switzerland.

## Email
- Never send email. Draft it only, and let the owner send it.

## Servers and money
- Staging and live are plain file uploads on Cloudways, not git checkouts.
  Deploy with the "Server deploy" workflow: run `inspect` first, then
  `deploy`, staging before live. Migrations and `composer install` are run
  by hand.
- Staging currently uses the LIVE Stripe keys and a copy of live customers:
  never run payment tests there. Test billing in the Stripe sandbox instead.
- The Gemini speech quota is shared between staging and live: ask before
  running anything that generates audio.

## Checking on live
- For any check on live that needs a signed-in member, use the test member
  "Claude test member" (member 8215; the owner's Gmail address with
  "+claude-test" before the @). Never sign in as a real member.
- No password is stored. Sign in by asking the site for a password reset for
  that address and reading the link through the Gmail connection.
- Look only: never pay with it (live Stripe takes real money), and ask before
  generating audio with it.
