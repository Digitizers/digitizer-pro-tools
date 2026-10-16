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

That favours a plugin every site of a kind is obliged to run over one that is nice to have. A cookie banner on an Israeli site after Amendment 13 is the first kind.

## Cookie Banner (Hebrew / Israel): still the lead, but the market has moved

The August research found one Israel-specific plugin, with 200 installs. There are now six, all added in the past thirteen months:

| Plugin | Installs | Ratings | Added | Last update | Notes from its own page |
|---|---|---|---|---|---|
| atr-cookie-notice | **1,000** | 13 (100%) | 2025-09-14 | 2026-02-08 | Amendment 13 framing, three categories, live preview, Hebrew and English, "Full" vs "Simple" consent modes, cache purge tool |
| il-privacy-cookie-consent | 200 | 2 (100%) | 2025-12-27 | 2026-07-14 | Script blocking by category, **consent log in the database with CSV export**, privacy policy page generator, cookie table shortcode, focus trap |
| aviyo-cookie-consent | 50 | 0 | 2025-12-09 | 2026-07-25 | |
| edigital-cookie-consent | 40 | 0 | 2026-07-14 | 2026-07-23 | |
| mantaweb-consent-shield | 30 | 0 | 2026-07-19 | 2026-09-21 | |
| icomm-site-health | 10 | 0 | 2026-09-18 | 2026-10-09 | |

Searches:

| Term | Results |
|---|---|
| cookie banner hebrew | 43 |
| cookie consent israel | 4 |
| amendment 13 | 11 |
| עוגיות | 2 |
| cookie consent (global) | 1,315, led by Complianz and CookieYes at 1M each |

**Reading it:**
- The segment is real and still small: about 1,300 installs across all six plugins.
- It is fragmented. One plugin leads, at 1,000 installs; nobody has the segment locked.
- A seventh entrant has to be better on the page, not merely present.

### What the module already has that the Israeli plugins do not advertise

- **Per-language texts.** The locale is picked per page, so it works with WPML, Polylang and TranslatePress. The competitors offer Hebrew and English, not "any language".
- **Cache-proof display.** The banner renders hidden, and an inline head check decides whether to show it. Page caches and CDNs never show it to a visitor who has already answered. Built-in WP Rocket and Cloudflare Rocket Loader compatibility.
- **Design parity with Complianz, by settings alone** (#55 to #62). Elementor's global font, plus a link that reopens the banner (#59).
- **A correct cookie domain** on `.co.il`.

### Gaps to close before submitting

1. **Google Consent Mode v2.** The module has none (no `gtag('consent', ...)`), and neither Israeli leader advertises it. Every global plugin has it, and any site running Google Ads or GA4 is asked about it. This is the clearest way to be better on the page.
2. **A consent log.** il-privacy-cookie-consent stores one and exports CSV. The module stores consent only in the visitor's browser.
   - A log means a database table. That raises the review bar, as it did for Agent Log: `$wpdb->prepare`, retention, uninstall.
   - It must store no raw IP address.
3. **No legal claims.**
   - The plugin page may describe what the plugin does: categories, blocking until consent, a reject button as prominent as accept.
   - It should not say "compliant with Amendment 13". The competitors say it; the reviewers and the liability are ours.
4. **Tags.** The tags the leader uses, so the plugin appears where Israeli sites search: `amendment-13`, `israeli-law`, `cookies`, `consent`, `privacy`.

**Cost:** the same as the other standalones: a mirror of the module with a parity check each session. One thing differs: the banner is the module that changes most often (#55 to #62 in two days). Changes stay module-first, mirrored to the standalone.

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

1. **Cookie Banner as the next standalone**, once it has Google Consent Mode v2. The consent log can follow in the standalone's 1.1, so the first review stays a review of a banner. It is built module-first in DPT and then mirrored.
2. **Israeli phone validation** after it, as a small, quick one, once #51 is fixed.
3. **REST Bridge**, revisited after it runs on real client sites.
