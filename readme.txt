=== Digitizer Pro Tools ===
Contributors: digitizers
Author: Digitizer
Author URI: https://www.digitizer.co.il
Tags: cookies, gdpr, privacy, cookie banner, multilingual
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later

One toolbox plugin by Digitizers. First module: a multilingual cookie-consent banner with per-category script blocking.

== Description ==

Digitizer Pro Tools is a modular plugin that consolidates the tools Digitizers deploys on client sites. Modules can be toggled on and off from the Modules dashboard.

= Module: Cookie Banner =

A multilingual cookie-consent banner:

* Texts per language (Hebrew and English seeded out of the box; add any language by code, e.g. ru or pt_BR)
* Language auto-selected by the current page locale - works with WPML, Polylang, TranslatePress and any plugin that switches the WordPress locale per page
* Automatic RTL/LTR direction per displayed language
* 5 banner positions (bottom/top bar, centered modal, corners), 5 entry animations
* 4 cookie categories (essential, functional, analytics, marketing) with per-category script blocking until consent (GA, GTM, Facebook Pixel, any snippet)
* Floating "manage cookies" button so visitors can change preferences at any time
* Rich design controls: colors, background image with overlay, typography with shadows, per-button styling, border, page overlay
* Cache-proof: banner renders hidden and an inline head precheck decides visibility, so page caches / CDNs never show it to visitors who already answered
* Consent stored in a cookie and localStorage (cross-healing), with correct cookie domain on double-suffix TLDs (.co.il, .co.uk, .com.au)
* Consent version field - bump it to re-prompt all visitors (popular page caches are purged automatically on change)
* Consent lifetime in days, optional show delay, optional auto-accept on scroll
* Admin-only live preview (?dpt_cb_preview=1) and debug panel (?dpt_debug=1)
* JS API window.DPTCB (open/close/getConsent/acceptAll/rejectAll) and a dpt:consent event
* WP Rocket and Cloudflare Rocket Loader compatibility built in

Admin interface is in English with a full Hebrew translation.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin through the Plugins menu
3. Open "Digitizer Pro Tools" in the admin menu and make sure the Cookie Banner module is enabled
4. Open "Cookie Banner", review the texts per language, paste your analytics/marketing snippets in the Scripts tab
5. Save and check the site

== Changelog ==

= 1.0.1 =
* Banner box is now 100% wide with a 700px max-width (existing installs still on the old 900px/95% defaults are migrated automatically)
* All emoji replaced with real icons: inline SVG in the banner (categories + floating cookie button), native Dashicons in the admin
* Plugin author details added

= 1.0.0 =
* Initial release: modular core + multilingual cookie banner module.
