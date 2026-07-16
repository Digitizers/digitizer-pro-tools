=== Digitizer Pro Tools ===
Contributors: digitizers
Author: Digitizer
Author URI: https://www.digitizer.co.il
Tags: cookies, gdpr, privacy, cookie banner, multilingual
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.7.0
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

Admin interface is in English with a full Hebrew translation.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin through the Plugins menu
3. Open "Digitizer Pro Tools" in the admin menu and make sure the Cookie Banner module is enabled
4. Open "Cookie Banner", review the texts per language, paste your analytics/marketing snippets in the Scripts tab
5. Save and check the site

== Changelog ==

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
