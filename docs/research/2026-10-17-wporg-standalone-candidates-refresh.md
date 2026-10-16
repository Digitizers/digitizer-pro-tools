# Which module to publish on WordPress.org next: refreshed

**Date:** 2026-10-17
**Goal set by Ben:** visibility and leads for Digitizer, not reducing DPT maintenance.
**Starting point:** the Hebrew Cookie Banner, runner-up in
[2026-08-30-wporg-standalone-candidates.md](2026-08-30-wporg-standalone-candidates.md).
Agent Log, the winner there, is live.
**Method:** `api.wordpress.org/plugins/info/1.2/`:
- `query_plugins` for total result counts and the top hits per term;
- `plugin_information` for each Israeli competitor.

Install counts are WordPress.org's own buckets.

## What the live standalones taught

| Plugin | Live since | Installs |
|---|---|---|
| digitizer-update-hold | 2026-08-30 | under 10 |
| digitizer-ai-agent-log | 2026-09-09 | under 10 |
| digitizer-shabbat-keeper | 2026-10-14 | under 10 |

Six to seven weeks on the directory have brought almost no installs. A listing on its own does not bring traffic. Installs come from people searching for something they must have.

That favours a plugin people go looking for because they believe they need it, over one that is nice to have.

A cookie banner is the first kind for many Israeli sites, but **not all of them**. Amendment 13 sets duties that depend on what an organisation processes and how. It is not a blanket "every site must run a banner" rule (the Privacy Protection Authority's guidance: https://govextra.gov.il/privacy-protection-regulations/privacy-protection-regulations/).

The demand is real on sites that load analytics, advertising or other tracking that needs consent. That is most business sites and Digitizer's customers, but it is narrower than every site. This document makes no legal assessment.

## Cookie Banner (Hebrew / Israel): still a candidate, with thinner differentiation than expected

The August research found one Israel-specific banner, with 200 installs. There are now five, all added in the past thirteen months.

"Advertises" below means the feature is named in the plugin's own directory page, read through the plugin API on 2026-10-17. It does not mean tested.

| Plugin | Installs | Ratings | Added | Google Consent Mode v2 | Consent log | Multilingual beyond he/en | Notes |
|---|---|---|---|---|---|---|---|
| atr-cookie-notice | **1,000** | 13 (100%) | 2025-09-14 | not mentioned | not mentioned | not mentioned | Amendment 13 framing, three categories, live preview, "Full" vs "Simple" modes (blocking vs informational), cache purge tool |
| il-privacy-cookie-consent | 200 | 2 (100%) | 2025-12-27 | not named (mentions gtag) | **yes**, database with CSV export | mentions WPML and Polylang | Script blocking by category, privacy policy page generator, cookie table shortcode, focus trap |
| aviyo-cookie-consent ("NB Cookie Consent") | 50 | 0 | 2025-12-09 | **yes**, per category, plus the WP Consent API | unclear: the word appears, no log feature is described | mentions WPML and Polylang | Holds enqueued trackers inert until consent, no reload |
| edigital-cookie-consent | 40 | 0 | 2026-07-14 | **yes** | **yes** | not mentioned | Hebrew page copy, privacy policy generator, "every feature free" |
| mantaweb-consent-shield | 30 | 0 | 2026-07-19 | **yes** | **yes**, an audit log | free: admin catalogues in Hebrew, Arabic and Spanish; **Pro** (paid, not on WordPress.org): per-language banner texts following the locale, with WPML, Polylang or TranslatePress | Output-buffer blocking of inline scripts, iframes and pixels; compliance checklist; mentions caching |

`icomm-site-health`, which the "amendment 13" search also returns, is a broken-link and site-health tool, not a banner. It is excluded.

Searches:

| Term | Results |
|---|---|
| cookie banner hebrew | 43 |
| cookie consent israel | 4 |
| amendment 13 | 11 |
| עוגיות | 2 |
| cookie consent (global) | 1,315, led by Complianz and CookieYes at 1M each |

**Reading it:**
- **The segment is real and small:** about 1,320 installs across the five banners.
- **The features are table stakes, not differentiators.** Three plugins that launched in 2025–2026 already advertise Google Consent Mode v2 (aviyo, EDigital, MantaWeb). Three advertise a database consent log (il-privacy, EDigital, MantaWeb). All of it is free. Lacking them is a reason not to be chosen; having them is no reason to be chosen.
- **Installs do not follow features.** The plugins with the fullest feature lists sit at 30 to 50 installs. The leader advertises the fewest of them and has 1,000 installs and 13 ratings. It was first, by three to ten months. In this segment, early entry and ratings have counted for more than the feature list.
- **What that means for us:** a sixth entrant at feature parity should expect the small plugins' numbers unless something else brings installs. That could be ratings from real users, Digitizer's name among Israeli site owners, or the design quality.

### What the module has that the competitors' pages do not claim

None of this is unique by itself. It is a matter of how the plugin page presents it.

- **Any language, not only Hebrew and English.** Per-language texts, with the language picked per page, so it works with WPML, Polylang and TranslatePress. Not unclaimed. il-privacy and aviyo mention WPML and Polylang, and MantaWeb offers locale-selected per-language banner texts in its paid Pro. What the module offers is narrower: this for free, with any language added by code.
- **Cache-proof display.** The banner renders hidden, and an inline head check decides whether to show it, so page caches and CDNs never show it to a visitor who has already answered. Built-in WP Rocket and Cloudflare Rocket Loader compatibility. MantaWeb also mentions caching.
- **Design control.** Not uncontested: ATR advertises colours, positions, styling presets, custom CSS and a live preview, and MantaWeb has a Design tab with a live preview.
  - The module's claim is narrower: a site can rebuild a specific existing banner (Complianz, in #55 to #62) with settings alone, with no custom CSS.
  - Add Elementor's global font, and a link that reopens the banner (#59).
- **A correct cookie domain** on `.co.il`.

### What it would need before submitting

1. **Google Consent Mode v2, and the WP Consent API.** The module has neither (no `gtag('consent', ...)`). Three competitors advertise Consent Mode v2, and aviyo also the WP Consent API. Without them the plugin starts behind.
2. **A consent log: not for the first submission.** Three competitors advertise one. The module stores consent only in the visitor's browser.
   - The first standalone ships without it, so the first review is a review of a banner.
   - It is decided, and if built, built in the module, before the standalone's 1.1.
   - A log means a database table. That raises the review bar, as it did for Agent Log: `$wpdb->prepare`, retention, uninstall.
   - It must store no raw IP address.
3. **No legal claims.**
   - The plugin page may describe what the plugin does: categories, blocking until consent, a reject button as prominent as accept.
   - It should not say "compliant with Amendment 13". The competitors say it; the reviewers and the liability are ours.
4. **Tags.** What was checked:
   - **What the leader shows:** atr-cookie-notice's directory page shows only `consent`, `cookies` and `privacy`.
   - **What its readme declares:** the plugin API returns two more, `ammendment-13` (misspelled) and `israeli-law`.
   - **What that means:** WordPress.org does not show those two on the page. The `israeli-law` tag archive still exists and lists the plugin.
   - **The three verified ones** (`consent`, `cookies`, `privacy`) are the baseline.
   - **`amendment-13` and `israeli-law`** are an experiment: they may reach the tag archives without ever appearing on the page. Search placement depends mainly on the name, short description and readme text, not on tags.

**Cost:** the same as the other standalones: a mirror of the module with a parity check each session. One thing differs: the banner is the module that changes most often (#55 to #62 in two days). Changes stay module-first, mirrored to the standalone.

Item 1 is worth building in the DPT module anyway, and so is item 2 if it is built at all. Digitizer's own client sites run Google Ads and GA4, and Complianz, which the module is replacing on those sites, does both. That value holds whether or not a standalone follows.

## Other candidates checked

| Module | Market | Verdict |
|---|---|---|
| **Israeli phone validation** (`woo-checkout`, plus the Elementor check in `site-tweaks`) | "israeli phone validation": 2 results, the top being cf7-israeli-phone-validation with 40 installs | **Possible later.** Empty, Israeli and cheap to review. Tiny, though, and it should cover Woo, Elementor and CF7 to be worth a page. Fix digitizer-pro-tools#51 first: valid numbers with dashes are rejected. |
| **Resend mail** | "resend smtp": 208 results. send-emails-with-resend at 1,000, inside a category led by WP Mail SMTP (4M) and FluentSMTP (700k) | **Not now.** There is a Resend-specific niche, but the audience is developers, not Digitizer's customers. |
| **REST Bridge** | "jetengine rest api": 58 results, no dedicated plugin | **Not as it is.** See [2026-10-17-rest-bridge-standalone.md](2026-10-17-rest-bridge-standalone.md): migration-shaped, not yet on a real site; maybe later as JetEngine only. |
| Code Highlighter (enlighter) | 191 results; enlighter 10k, code-block-pro 10k | Ruled out. Being RTL-correct (#52) is not enough to compete. |
| Content Control | 2,280 results; content-control 40k, ultimate-member 200k | Ruled out. |
| Disable Comments | 2,529 results; disable-comments 1M | Ruled out. |
| Copy URL | 5,111 results, mostly copy-protection | Ruled out. Too small a job for a plugin page. |
| Woo checkout fields | 4,557 results; woo-checkout-field-editor-pro 400k | Ruled out. |
| Rank Math breadcrumbs | 222 results; Rank Math itself 4M | Ruled out. |

The August research already ruled out Hide Login, Duplicate Post, User Role Editor, Name Your Price and Update Emails. Nothing found here changes that.

## Recommendation

1. **Build Google Consent Mode v2 (with the WP Consent API) into the DPT Cookie Banner module now.** Digitizer's client sites need it to replace Complianz, standalone or not. The consent log is a separate, later decision: it does not block the first submission.
2. **The Cookie Banner standalone stays the best candidate, with an honest expectation.**
   - It is the only module whose searchers are Digitizer's customers.
   - The segment is small, and its features are table stakes.
   - Count on installs from ratings and Digitizer's name, not from the feature list. Design is not uncontested; position it narrowly, as rebuilding an existing banner without CSS.
   - Submit it once item 1 is in the module.
3. **Israeli phone validation** after it, as a small, quick one, once #51 is fixed.
4. **REST Bridge**, revisited after it runs on real client sites.
