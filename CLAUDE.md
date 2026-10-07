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
