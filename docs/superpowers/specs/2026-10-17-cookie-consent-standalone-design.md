# Cookie Banner as a standalone plugin for wordpress.org

**Date:** 2026-10-17
**Status:** Ben approved two decisions on 2026-10-17, replying "כן" to the proposal in chat:
- the name and slug;
- dropping the `window.DPTCB` alias.

The spec as a whole awaits his review.
**Applies to:** a new repository and wordpress.org plugin, plus a stand-down change to `modules/cookie-banner/` in this repository.
**Source:** the module as of Digitizer Pro Tools 1.46.1 (`39d2d69`). Behaviour is the module's unless this document says otherwise.
**Why this one:** [`docs/research/2026-10-17-wporg-standalone-candidates-refresh.md`](../../research/2026-10-17-wporg-standalone-candidates-refresh.md). It is the only module whose searchers are Digitizer's customers. The Israeli segment is small, its features are table stakes, and installs will come from ratings and Digitizer's name rather than from the feature list.

## Decisions that are Ben's

1. **Name and slug** (permanent on wordpress.org): **"Digitizer Cookie Consent"**, slug `digitizer-cookie-consent`, repository `Digitizers/digitizer-cookie-consent` (public). Approved.
   - The plugin API returned "not found" for it on 2026-10-17. A slug reserved by a pending submission also returns that, so this is a good sign rather than proof that the slug is free.
   - "Cookie consent" is the category's own term, and the term with slightly more results: 1,315 against 1,177 for "cookie banner".
2. **No `window.DPTCB` alias.** Approved. The JS API becomes `window.DigitizerCookieConsent`. A three-letter global is what a directory reviewer flags, and no code of Digitizer's calls `DPTCB`.
3. **Hebrew ships inside the plugin.** This is a default, following the Shabbat Keeper decision. The banner's own texts are settings, seeded in Hebrew and English. The admin screen's Hebrew comes from the bundled catalog, since Update Hold's still waits on a translation editor.
4. **An opt-in credit, off by default.** This is a default, following the Shabbat Keeper decision. One setting adds "Cookie consent by Digitizer" under the banner's buttons, linking to `https://www.digitizer.studio`. Directory guideline 10 allows a front-end credit only as an opt-in. *To change:* drop the setting; nothing depends on it.

## What does not change, so a site moving from Digitizer Pro Tools loses nothing

A visitor who already chose on a site must not be asked again because the site swapped the module for the plugin. The site's own CSS, links and code must keep working. So these keep their names:

| Kept | Why |
|---|---|
| Cookie `dpt_consent`, localStorage key `dpt_consent_v1` | the visitor's stored choice |
| `#dpt-cookie-settings`, class `.dpt-cb-open`, `[dpt_cookie_settings]` | links and shortcodes already placed on pages (#59) |
| Event `dpt:consent` | listeners in site code |
| HTML ids and classes `dpt-cb-*`, `#dpt-cb-banner`, `#dpt-cb-float-button` | site CSS that styles the banner |

These are not PHP or JS globals, so the directory's prefix rule does not apply to them.

## What is renamed

| In the module | In the standalone |
|---|---|
| classes `DPT_CB_Settings`, `DPT_CB_Frontend`, `DPT_CB_Admin`, `DPT_Cookie_Banner_Module` | `Digitizer_Cookie_Consent_Settings`, `_Frontend`, `_Admin`, `_Core` (boot on `plugins_loaded`) |
| option `dpt_cookie_banner` | `digitizer_cookie_consent` |
| text domain `digitizer-pro-tools` | `digitizer-cookie-consent` |
| `admin_post_dpt_cb_save`, `_add_lang`, `_remove_lang` | `admin_post_digitizer_cc_save`, `_add_lang`, `_remove_lang` |
| filters `dpt_cb_open_selector`, `dpt_cb_rtl_langs`; action `dpt_cb_purge_caches` | `digitizer_cookie_consent_open_selector`, `_rtl_langs`, `_purge_caches` |
| `window.DPTCB`, `DPT_CB_CONFIG`, `window.dptCbPurge` | `window.DigitizerCookieConsent`, `DigitizerCookieConsentConfig`, `window.digitizerCookieConsentPurge` |
| `?dpt_cb_preview=1`, `?dpt_debug=1` | `?digitizer_cc_preview=1` (with a nonce), `?digitizer_cc_debug=1` |
| script and style handles `dpt-cb-frontend` | `digitizer-cookie-consent` |
| `DPT_VERSION`, `DPT_URL` | `DIGITIZER_COOKIE_CONSENT_VERSION`, `_URL` |

## Layout

The layout follows `digitizer-shabbat-keeper`:

```
digitizer-cookie-consent.php                     bootstrap, constants, requires
includes/class-digitizer-cookie-consent-core.php      boot, activation, deactivation, DPT import
includes/class-digitizer-cookie-consent-settings.php
includes/class-digitizer-cookie-consent-frontend.php
includes/class-digitizer-cookie-consent-admin.php     loaded under is_admin() only
assets/css/frontend.css   assets/js/frontend.js
assets/css/admin.css      assets/js/admin.js          what the module took from DPT's shared admin assets
languages/                                        .pot, he_IL .po/.mo/.l10n.php
uninstall.php   readme.txt   LICENSE
tests/  bin/  .wordpress-org/  .github/workflows/   not shipped (.distignore)
```

Version `1.0.0`. Requires WordPress 5.7 (`wp_print_inline_script_tag()`) and PHP 7.4, the module's floor. "Tested up to" matches the other standalones.

## Where the standalone differs from the module

**Its own settings screen.**
- Settings → Cookie Consent (`options-general.php`), `manage_options`, with the module's tabs in the module's order.
- The module leaned on Digitizer Pro Tools' shared admin assets: `assets/js/admin.js` (the colour picker, the background-image media picker) and `assets/css/admin.css` (switches, tabs, section headings, the preview). The parts it used are copied into the plugin's own `admin.css` and `admin.js`, enqueued only on its own screen.
- A "Settings" link in the plugin's row.
- The preview link carries a nonce.

**It has a master switch, and it starts on.**
- `enabled` already exists, and a cookie plugin that is activated is expected to show a banner.
- Until the settings are saved once, a notice on the Plugins and Dashboard screens says the banner is live with the default texts and links to the screen.
- The `saved` flag works as Shabbat Keeper's does.

**Inline output goes through the core APIs.** The module prints these as raw tags. The standalone changes each:

| What | Module | Standalone |
|---|---|---|
| Head precheck: consent check, Consent Mode defaults, cookie purge | `<script>` | `wp_print_inline_script_tag()` at `wp_head` priority 1. It keeps `data-no-defer` and `data-cfasync="false"` as attributes, because WP Rocket and Cloudflare must not delay it. |
| Precheck CSS that hides the banner for a visitor who has answered | `<style>` | registered handle plus `wp_add_inline_style()`, printed at the same priority |
| Design CSS | `<style>` in the footer | `wp_add_inline_style()` on the front-end stylesheet |
| Floating button's `onclick` | inline attribute | removed; `frontend.js` already binds the button |

The CSS contract the module documents still holds: values are sanitised at assignment, and are not HTML-escaped inside CSS.

**Admin-provided scripts.** With script blocking off, the module prints the site's own snippets server-side, but only those saved by a user with `unfiltered_html`. The standalone keeps that, and says it in the readme. "Header and footer scripts" plugins are accepted on the same terms.

**Activation, deactivation, uninstall.**
- **Activation** seeds the option, importing from Digitizer Pro Tools (below).
- **Deactivation** purges page caches through the existing purge action, so cached pages stop carrying the banner.
- **`uninstall.php`** deletes the option on every site of a network, in Shabbat Keeper's closure-per-site shape. Visitors' own consent cookies stay in their browsers; nothing server-side can delete them.

**Settings follow a site that moves from Digitizer Pro Tools.** On activation, when `digitizer_cookie_consent` does not exist and `dpt_cookie_banner` does, the module's values are copied through `sanitize`:
- the texts for every language;
- the scripts;
- the cookie-cleanup patterns;
- the consent version.

The import is one way and runs once. The module's option is left alone.

The consent version comes across too. This matters: a changed version would re-ask every visitor.

## Digitizer Pro Tools stands down

This is the same pattern as Shabbat Keeper in 1.40.0:
- `DPT_Cookie_Banner_Module::standalone_active()` returns `class_exists( 'Digitizer_Cookie_Consent_Core' )`.
- `init()` returns early when it is.
- `user_can_toggle()` is false.
- `toggle_denied_reason()` names the plugin and where its settings are.
- One Hebrew string, one test, and a readme changelog entry.

Two copies then exist, as for Agent Log and Shabbat Keeper:
- the standalone is where changes land first;
- this repository mirrors them;
- the start-of-session parity check covers this module.

## Tests

- The module's tests port with the rename onto a self-contained `tests/bootstrap.php`:
  - `cb-design-test.php`;
  - `cb-consent-signals-test.php`, with the precheck run in Node;
  - `cb-cookie-cleanup-test.php`.
- Added:
  - activation seeds the option, and imports from the DPT option, including the consent version;
  - uninstall removes the option on a single site and on a network;
  - the first-save notice appears and goes;
  - the preview needs its nonce;
  - the credit is absent by default and present when on;
  - the precheck still runs first in `<head>`, through `wp_print_inline_script_tag()`, with its attributes;
  - no raw `<script>` or `<style>` is printed by PHP outside the admin-snippet path.

## Packaging for the directory

**`readme.txt` in English:**
- The first sentence says what it does. The second says it is built for Hebrew and multilingual sites, but works on any site.
- Then the features:
  - categories and blocking until consent;
  - Google Consent Mode v2 and the WP Consent API;
  - deleting a denied category's cookies;
  - the cache-proof display;
  - design control;
  - the reopen link.
- **No legal claims.** The readme does not say "compliant with Amendment 13" or "GDPR compliant". It describes what the plugin does and recommends a site's own legal review.
- FAQ, including "Does it send data anywhere?": no. Under "External services": none. Google's tags are the site's own.
- Tags: `cookies`, `consent`, `privacy`, `gdpr`, `cookie banner`.

**`.wordpress-org/`:**
- banner 772 and 1544, icon 128 and 256;
- screenshots of the banner in Hebrew and English, the settings view and the settings screen. They are real captures from WordPress Playground, as Shabbat Keeper's third screenshot was.

**Workflows and checks:**
- `.distignore`, `assets.yml` and `deploy.yml` come from Shabbat Keeper with the slug changed. The deploy runs on a published GitHub release, with the organisation secrets `SVN_USERNAME` and `SVN_PASSWORD`.
- Plugin Check must come back clean before submission.

## Review risks, named so they are not surprises

- **Injecting scripts.** Two kinds: the site's own snippets after consent (`frontend.js`), and the snippets printed server-side when blocking is off. Both are admin content saved under `unfiltered_html`, and the readme says so. If a reviewer objects to the server-side path, it can go through `wp_print_inline_script_tag()` per snippet. That is a small change.
- **The head precheck must run before everything.** `wp_print_inline_script_tag()` at priority 1 keeps that. The attributes stop WP Rocket and Cloudflare from deferring it.
- **Deleting cookies.** It deletes only first-party cookies, never WordPress or WooCommerce ones, and only from names the administrator listed. The admin text says what it cannot reach.
- **`load_plugin_textdomain()`** for 5.7–6.7 draws a Plugin Check notice. It was accepted for Shabbat Keeper.
- **No legal claims.** A reviewer reading "compliant" would ask for proof. The readme makes none.

## Order of work, and who does what

1. Ben reviews this spec.
2. Create the repository, then make the port, the standalone-only pieces, the tests and the packaging on a branch. Then a PR, a Codex review to a clean verdict on the exact head, and a squash-merge. This will take several PRs, not one.
3. Ben submits the built zip at wordpress.org/plugins/developers/add/ from his account, or approves doing it in his browser session; he logs in himself. Replies to the reviewer are drafted for Ben.
4. In parallel, the DPT stand-down: a PR, Codex, merge, and a DPT release on Ben's yes.
5. On approval: GitHub release 1.0.0, which deploys to the directory. Banner, icon and screenshots go through `assets.yml`.
6. Hebrew for the directory page goes through translate.wordpress.org, under the PTE request already open (Polyglots post 73531).

## Out of scope, on purpose

- A consent log. It is the first candidate for 1.1, as the research recommends.
- Region-specific Consent Mode defaults, `ads_data_redaction`, `url_passthrough`.
- A cookie scanner or an automatic cookie table.
- A paid tier, licence keys, telemetry.
- Removing the module from Digitizer Pro Tools.
