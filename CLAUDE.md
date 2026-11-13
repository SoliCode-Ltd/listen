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
