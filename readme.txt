=== Digitizer Pro Tools ===
Contributors: digitizers
Author: Digitizer
Author URI: https://www.digitizer.co.il
Tags: cookies, gdpr, privacy, cookie banner, multilingual
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.11.0
License: GPLv2 or later

One toolbox plugin by Digitizer. Modules: multilingual cookie-consent banner, one-click post duplication, auto-update email silencing.

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

= Module: Duplicate Post =

One-click duplication of posts, pages and custom post types:

* "Duplicate" and "Duplicate & Edit" links in the post list rows, plus a bulk action
* Copies are created as drafts with a configurable title suffix
* Copies custom fields - including page-builder data such as Elementor's - taxonomies, featured image, page template, menu order and parent
* Configurable per post type; respects each post type's edit capabilities

= Module: Update Emails =

Silences the routine "site updated" notifications WordPress emails after automatic updates:

* Separate toggles for plugin, theme and core auto-update emails
* Core updates: only SUCCESS notifications are silenced - failure and critical emails always go out
* Neutralizes the well-known legacy functions.php snippets on these hooks (blanket __return_false and the WPBeginner-style core callbacks), so they cannot hide failure emails or fatal the cron - still, remove those snippets once the module is active

= Module: Disable Comments =

Turns comments off - dynamically (disabled by default; enable it on the Modules screen):

* Everywhere, or only on selected post types
* Closes comment/trackback forms, hides existing comments, strips editor support
* Removes the admin comments UI (menu, admin-bar bubble, dashboard widget, edit-comments screen) - only when comments are disabled for every relevant post type
* WooCommerce product reviews are comments under the hood - a dedicated toggle (on by default) keeps them working

= Module: Hide Login =

Moves the login page to a custom URL (disabled by default; enable it on the Modules screen):

* Custom login slug (default: /login), configurable per site
* wp-login.php returns the theme's real 404 page; logged-out wp-admin requests land on a 404 as well
* All generated login/logout/lost-password/registration URLs are rewritten automatically, including the links in password-reset emails
* Post-password forms, admin-ajax, admin-post and cron keep working
* Reserved WordPress slugs are rejected to avoid lockouts

= Module: User Role Editor =

Full control over what every role can do (disabled by default; enable it on the Modules screen):

* Per-role capability matrix - grant or revoke any capability with a live "select all" toggle
* Add a new role, optionally cloning the capabilities of an existing one
* Delete a role and move its users to another role in one step
* Register custom capabilities and grant them to any roles
* Gated by a dedicated `dpt_manage_roles` capability (granted to administrators), so a delegated manage_options account cannot open the editor and escalate its own privileges
* Lockout-proof: the administrator role always keeps the capabilities needed to manage the site, and you can never strip your own access
* Protected roles (administrator) and the default new-user role cannot be deleted

= Module: Content Control =

Restrict who can see what (disabled by default; enable it on the Modules screen):

* Per-page/post restriction metabox: everyone, logged-in users, logged-out visitors only, or specific roles - with a custom restriction message
* Whole-site protection: require login (or specific roles) for the entire site, redirecting to the login form, a page, or showing a message; exempt individual pages by ID
* Per-menu-item visibility so navigation entries appear only to the right audience (descendants of a hidden item are hidden too)
* `[dpt_restrict role="editor,author"]...[/dpt_restrict]` shortcode to gate partial content (works in the block editor and Elementor)
* Restricted content is replaced in listings, single views, feeds and the REST API - not just hidden with CSS
* Administrators always retain access so they cannot lock themselves out

= Module: Enlighter =

Syntax-highlight code on the front end - dependency-free, no external CDN (disabled by default; enable it on the Modules screen):

* "Code (Enlighter)" block for the block editor
* `[dpt_code lang="php"]…[/dpt_code]` shortcode (works in the classic editor and Elementor)
* Optional automatic highlighting of every existing `<pre><code class="language-…">` block
* Elementor "Code (Enlighter)" widget when Elementor is active
* Languages: PHP, JavaScript, CSS, HTML/XML, SQL, Bash, Python, JSON (and plain text)
* Light, dark and auto themes (auto follows the visitor's colour scheme), optional line numbers and a copy button
* Code is always HTML-escaped and highlighted client-side from the escaped text, so nothing in a snippet can inject markup or scripts
* Migration-friendly: the legacy `[enlighter]` shortcode and Enlighter's saved `data-enlighter-language` markup (block and inline) are recognised automatically; per-language shortcodes such as `[php]`/`[js]` can be enabled with the `dpt_en_language_shortcodes` filter

= Module: Site Tweaks =

Small site-wide tweaks that replace assorted functions.php snippets - each an independent toggle (disabled by default; enable it on the Modules screen):

* HTTP security headers on front-end responses: X-Frame-Options (SAMEORIGIN), X-Content-Type-Options (nosniff), an optional legacy X-XSS-Protection, and optional HSTS (Strict-Transport-Security) sent over HTTPS only, with an extra opt-in for includeSubDomains/preload
* Sanitised SVG uploads: every SVG is cleaned on upload (scripts, event handlers, javascript: URLs, external references and entity/DOCTYPE payloads are stripped), and uploads are limited to users with a configurable capability (administrators by default)
* Hide the WordPress version: removes the generator meta tag/RSS marker and the `?ver=` core version from asset URLs, while keeping plugin/theme asset versions so cache-busting still works
* Elementor helpers (only when Elementor is active): disable Elementor's Google Fonts, and validate phone numbers in Elementor Pro `tel` form fields

= Module: WooCommerce Checkout =

Checkout-field helpers for WooCommerce (disabled by default; enable it on the Modules screen):

* Email typo suggestions: when a customer types an email whose domain looks like a misspelling of a known provider (Gmail, Outlook, Walla, etc.), a one-click correction is offered. The provider list is editable, and matching uses edit-distance so only genuine near-misses are suggested
* Israeli phone-number validation: accepts numbers starting with 05 (10 digits), 972 (12) or +972 (13), with a live in-browser hint and an authoritative server-side check that blocks checkout on an invalid number
* All suggestion/error text is inserted as plain text, so nothing typed into the fields can inject markup

= Module: Rank Math Breadcrumbs =

Adds extra crumbs to the Rank Math breadcrumb trail (disabled by default; enable it on the Modules screen):

* A "Blog" crumb after Home on single posts and blog archives (category, tag, author, date)
* A "Shop" crumb after Home on WooCommerce product pages
* URLs and labels are auto-detected - the Blog crumb from the posts page set under Settings > Reading, the Shop crumb from the WooCommerce shop page - and each can be overridden manually
* A crumb is not added if the same URL is already present in the trail
* Applies only when Rank Math is active

= Module: Resend Mail =

Delivers all site email through the Resend API (disabled by default; enable it on the Modules screen):

* Routes every wp_mail() call - order emails, form notifications, password resets - through Resend's HTTP API via the pre_wp_mail short-circuit; no SMTP credentials on the server
* Verified-domain sender with an optional "force sender" mode (on by default), so plugins cannot send from unverified addresses that Resend would reject
* Full wp_mail compatibility: To/Cc/Bcc and Reply-To headers, HTML and plain-text content types, custom headers and file attachments
* Send log (last 100 emails) with per-email delivery status - delivered, bounced, opened, marked as spam - fed by a signed Resend webhook (Svix signature verification, replay protection)
* Automatic fallback to the default WordPress mailer when the API errors, so emails are never silently dropped
* Test-email button, masked API key storage with an optional DPT_RESEND_API_KEY wp-config.php constant override
* Replaces WP Mail SMTP / FluentSMTP on Resend-backed sites

Admin interface is in English with a full Hebrew translation.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin through the Plugins menu
3. Open "Digitizer Pro Tools" in the admin menu and make sure the Cookie Banner module is enabled
4. Open "Cookie Banner", review the texts per language, paste your analytics/marketing snippets in the Scripts tab
5. Save and check the site

== Changelog ==

= 1.11.0 =
* New module: Resend Mail - delivers all wp_mail() email through the Resend API with a send log, webhook-fed delivery statuses (signed, replay-protected), forced verified sender and automatic fallback to the default mailer on API errors (module ships disabled; enable per site)

= 1.10.0 =
* New module: Rank Math Breadcrumbs - adds a Blog crumb on post contexts and a Shop crumb on WooCommerce product pages to the Rank Math breadcrumb trail, with auto-detected URLs/labels and manual overrides (module ships disabled; enable per site)

= 1.9.0 =
* New module: WooCommerce Checkout - email-domain typo suggestions and Israeli phone-number validation (client-side hint plus a server-side check) on the checkout billing fields (module ships disabled; enable per site)

= 1.8.0 =
* New module: Site Tweaks - HTTP security headers, sanitised SVG uploads, hiding the WordPress version, and Elementor helpers (disable Google Fonts, phone-field validation), each an independent toggle (module ships disabled; enable per site)

= 1.7.0 =
* New module: Enlighter - dependency-free code syntax highlighting via a block, the [dpt_code] shortcode, automatic pre/code highlighting and an Elementor widget (module ships disabled; enable per site)

= 1.6.0 =
* New module: Content Control - per-page/role restrictions, whole-site protection, per-menu-item visibility and a [dpt_restrict] shortcode, enforced across listings, feeds and REST (module ships disabled; enable per site)

= 1.5.0 =
* New module: User Role Editor - edit role capabilities, add/clone/delete roles and register custom capabilities, with lockout protection (module ships disabled; enable per site)

= 1.4.0 =
* New module: Hide Login - custom login URL with 404 for wp-login.php and logged-out wp-admin (module ships disabled; enable per site)

= 1.3.0 =
* New module: Disable Comments - global or per-post-type, with WooCommerce product reviews protected by default (module ships disabled; enable per site)

= 1.2.0 =
* New module: Update Emails - silences automatic-update email notifications (plugins, themes, successful core updates), with failure emails always kept

= 1.1.0 =
* New module: Duplicate Post - one-click duplication of posts, pages and custom post types as drafts, including custom fields (Elementor data), taxonomies and the featured image

= 1.0.1 =
* Banner box is now 100% wide with a 700px max-width (existing installs still on the old 900px/95% defaults are migrated automatically)
* All emoji replaced with real icons: inline SVG in the banner (categories + floating cookie button), native Dashicons in the admin
* Plugin author details added

= 1.0.0 =
* Initial release: modular core + multilingual cookie banner module.
