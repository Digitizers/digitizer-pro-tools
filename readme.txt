=== Digitizer Pro Tools ===
Contributors: benkalsky
Tags: cookies, gdpr, privacy, cookie banner, multilingual
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.27.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One toolbox plugin by Digitizer. Modules: multilingual cookie-consent banner, one-click post duplication, auto-update email silencing.

== Description ==

Digitizer Pro Tools is a modular plugin that consolidates the tools Digitizers deploys on client sites. Modules can be toggled on and off from the Modules dashboard.

Every module ships disabled. Activating the plugin adds its Modules screen and does nothing else - each module below stays dormant until you switch it on, so a site runs exactly what you chose for it. On a new site, Onboarding is usually the first one you turn on.

= Module: Onboarding =

Brings a new site up to the Digitizer baseline in one pass:

* Checklist of the baseline - Hello Elementor with the Hello Digitizer child theme, and twelve plugins - showing what this site already has
* Untick anything a given client does not need, then install the rest
* **Only the child theme and Elementor are switched on.** Everything else is installed and left inactive, so you decide per client what actually runs
* Installs from WordPress.org, and from the public GitHub repositories for Hello Digitizer, Elementor MCP and MCP Adapter
* Optionally turns on WordPress's automatic updates for the items it installed, and for Digitizer Pro Tools itself
* Reports updates for the baseline's GitHub items - Hello Digitizer, Elementor MCP and MCP Adapter - which are not in the WordPress.org directory and are installed inactive, so nothing else on the site would ever check them. Switch the module off and those offers stop with it
* Optionally removes the unused default themes, always keeping the newest one and the theme this WordPress is configured to fall back to if the active theme ever breaks. The active theme, any theme another theme depends on, and anything that is not actually one of WordPress's own themes are never removed. Not offered on a multisite network, where one directory of themes is shared by every site
* Nothing already installed is updated, downgraded or reconfigured
* Safe to run again: the second pass finds everything in place and does nothing
* One request per item, so a slow download cannot time out the whole run, and each row reports what happened
* The theme is switched only while the site is still on a WordPress default - a site with a live custom theme keeps it, and the summary says so
* The list is fixed in code. There is no field for pasting a plugin URL

= Module: Update Policy =

Decides when this site takes a major WordPress release:

* A major release is held back for a set number of days - 30 by default - after this site is first offered it, so the plugins and themes running here have time to catch up with it
* **Security and maintenance releases are never held.** They are what keeps a site safe, WordPress installs them on its own, and this module does not look at them
* A held release is removed from the update offer rather than merely discouraged: a warning does not stop someone pressing the button, and a button that is not there does
* The Updates screen says which release is held, when this site first saw it, and when the hold ends - a hold nobody can see is indistinguishable from a site whose updates are broken
* The hold can be lifted from that notice at any time, permanently for that release line
* The window is counted from the day this site first saw the release, not from the day it was published: nothing is asked of any external service, and a site checks for updates twice a day
* Nothing is ever installed automatically. When the window passes the release is simply offered again, and updating stays a deliberate act
* On a multisite network the policy is the main site's, since the WordPress it governs belongs to the network
* Also available as a standalone plugin, Update Policy, for sites that do not run Digitizer Pro Tools. When that plugin is active this module stands down and says so

= Module: REST Bridge =

Puts this site's own custom fields on the REST API (disabled by default; enable it on the Modules screen):

* Fields are discovered from the definitions JetEngine already stores - meta boxes and their fields, including repeaters with their sub-fields - so a field added in JetEngine appears in the API without anyone writing code for it
* Discovered fields are added to the standard wp/v2 posts, pages and terms endpoints, reading and writing exactly like a post title or any other core field
* Discovered fields are readable only by someone logged in (with context=edit), because this module cannot tell which of a site's fields were meant for the public - a field holding internal notes or a client's details is not put on an anonymous request. The fields the old plugin already published - reading_time, the author bio fields and the FAQ - stay public, and a site can make any other field public with the dpt_rb_field_context filter
* A repeater keeps the columns this module does not understand - an icon, a gallery, a nested repeater - exactly as they were: read the field, change one value, write the whole list back, and nothing else in the row is lost. A repeater with no column this module understands at all is left off the API rather than exposed as an empty shape, and the info endpoint says so
* A field whose name is one the REST API already uses on that endpoint - title, excerpt, meta, or the name of a taxonomy attached to the post type - is never registered over it: that would replace the real property rather than sit beside it, and would break the endpoint for everything else reading it, the block editor included. The field is exposed under another name instead, and never dropped - the FAQ section title, whose meta key really is title, is jet_faq_title, the name the old plugin gave it, and anything else takes a jet_ prefix. The info endpoint names every field this happened to and the name it answers to now
* Legacy field names from the standalone Digitizer API Extensions plugin - reading_time, the author bio fields, the FAQ section title as jet_faq_title, and the qna FAQ repeater under its old name jet_qna - keep working wherever a discovered field does not already claim that name, including a fallback for jet_qna on a site whose JetEngine definitions this module cannot see
* Exposes Rank Math's SEO fields on posts and pages - title and meta description overrides, focus keyword, robots directives, canonical URL, primary category, SEO score, and the Open Graph and Twitter card fields - only while Rank Math is active
* Elementor endpoints (GET/POST /digitizer/v1/elementor/{post_id}) for reading and merging widget settings by widget ID, without touching the rest of the page's layout
* An /digitizer/v1/info endpoint reports exactly what was discovered, registered and skipped on this site, and why - each registered field with the schema it was given, so an automation can see what a field will accept before it writes to it. Restricted to users who can edit posts
* Two notes for anything reading and writing these fields: a number field that was never saved reads back as 0 rather than as empty, so a read-modify-write cycle stores a 0 where there was nothing; and the Elementor endpoint saves widget settings as Elementor itself does, without stripping HTML, so anyone who may edit a page may put markup in it - the same as editing that page in Elementor
* Replaces the standalone Digitizer API Extensions plugin and stands down while that plugin is active, since both would register the same fields and the same routes

= Module: Cookie Banner =

A multilingual cookie-consent banner:

* Texts per language (Hebrew and English seeded out of the box; add any language by code, e.g. ru or pt_BR)
* Language auto-selected by the current page locale - works with WPML, Polylang, TranslatePress and any plugin that switches the WordPress locale per page
* Automatic RTL/LTR direction per displayed language
* 5 banner positions (bottom/top bar, centered modal, corners), 5 entry animations
* 4 cookie categories (essential, functional, analytics, marketing) with per-category script blocking until consent (GA, GTM, Facebook Pixel, any snippet)
* Floating "manage cookies" button so visitors can change preferences at any time
* Rich design controls: colors, background image with overlay, typography with shadows, per-button styling, border, page overlay
* Separate box width for desktop and mobile - a narrow corner card on desktop, full-width on phones
* Font control: inherit the site font (default), follow Elementor's primary global font, or set a custom font stack
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

= Module: Code Highlighter =

Syntax-highlight code on the front end - dependency-free, no external CDN (disabled by default; enable it on the Modules screen):

* "Code Highlighter" block for the block editor
* `[dpt_code lang="php"]…[/dpt_code]` shortcode (works in the classic editor and Elementor)
* Optional automatic highlighting of every existing `<pre><code class="language-…">` block
* Elementor "Code Highlighter" widget when Elementor is active
* Languages: PHP, JavaScript, CSS, HTML/XML, SQL, Bash, Python, JSON (and plain text)
* Light, dark and auto themes (auto follows the visitor's colour scheme), optional line numbers and a copy button
* Code is always HTML-escaped and highlighted client-side from the escaped text, so nothing in a snippet can inject markup or scripts
* Migration-friendly: code already saved with `data-enlighter-language` attributes or `EnlighterJSRAW` classes - the markup format of the standalone Enlighter plugin - is recognised and highlighted without editing the post. Its shortcode tags are not claimed unless you ask: `[enlighter]` is enabled with the `dpt_en_legacy_shortcode` filter and stands down while that plugin is active and already owns the tag, and per-language tags such as `[php]`/`[js]` with `dpt_en_language_shortcodes`. The two are designed to coexist

= Module: Site Tweaks =

Small site-wide tweaks that replace assorted functions.php snippets - each an independent toggle (disabled by default; enable it on the Modules screen):

* HTTP security headers on front-end responses: X-Frame-Options (SAMEORIGIN), X-Content-Type-Options (nosniff), an optional legacy X-XSS-Protection, and optional HSTS (Strict-Transport-Security) sent over HTTPS only, with an extra opt-in for includeSubDomains/preload
* Sanitised SVG uploads: every SVG is cleaned on upload (scripts, event handlers, javascript: URLs, external references and entity/DOCTYPE payloads are stripped), and uploads are limited to users with a configurable capability (administrators by default)
* Hide the WordPress version: removes the generator meta tag/RSS marker and the `?ver=` core version from asset URLs, while keeping plugin/theme asset versions so cache-busting still works
* Elementor helpers (only when Elementor is active): disable Elementor's Google Fonts, validate phone numbers in Elementor Pro `tel` form fields, and drop Elementor's icon fonts (Font Awesome and eicons) on a design that uses no icons - a page that does use one shows an empty square, so check first
* Front-end weight: drop the block editor stylesheets from public pages on a site built entirely in Elementor (the editor itself is unaffected, and any page that does contain a block loses its styling), and switch the block editor off for posts and pages so there is one way to edit content rather than two

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

= Module: Name Your Price =

Let customers set their own price on chosen WooCommerce products (disabled by default; enable it on the Modules screen):

* Enable per product on the product edit screen (Product data > General), with optional minimum, maximum, suggested and default (pre-filled) prices
* A price input replaces the fixed price on the product page, with an optional allowed-range hint
* The price is enforced on the server: an out-of-range or invalid price is rejected at add-to-cart, re-validated when the cart line is created, and re-clamped to the range at total calculation - a tampered client value can never bypass the limits
* Configurable field label; applies only when WooCommerce is active
* Replaces the WPC "Name Your Price" plugin

Admin interface is in English with a full Hebrew translation.

= Module: Checkout Field Editor =

Tailor the WooCommerce (classic/shortcode) checkout without a snippet (disabled by default; enable it on the Modules screen):

* Show, hide, require and reorder the standard Company, Address line 2, Phone and Order notes fields
* Add up to 10 custom fields - text, dropdown or checkbox - in the billing, shipping or additional-info section
* Custom values are validated on the server (a dropdown value outside the configured options is rejected), saved to the order, and shown on the admin order screen and in order emails
* Covers the common checkout-field edits without a dedicated field-editor plugin; applies only when WooCommerce is active

Admin interface is in English with a full Hebrew translation.

= Module: Embed =

Embed the sources WordPress core does not oEmbed on its own, with the [dpt_embed] shortcode (disabled by default; enable it on the Modules screen):

* PDF files and Google Docs / Sheets / Slides / Forms / Drive links in a responsive frame
* Per-shortcode aspect ratio or fixed pixel height, optional accessible title, optional lazy-loading
* Only http(s) PDF URLs and docs.google.com / drive.google.com links are embedded; anything else is left to core oEmbed
* Usage: [dpt_embed url="https://example.com/file.pdf" ratio="16:9"]

Admin interface is in English with a full Hebrew translation.

== External services ==

This plugin is self-contained: every script, style and font it ships is bundled locally, so no module loads assets from a CDN or a font service. There is no telemetry and no usage tracking. The outside connections it can make are listed below - the first two are made by your server, the third by the visitor's browser and only for content you choose to embed.

= Resend (email delivery) =

The Resend Mail module - disabled by default - delivers your site's email through the Resend API instead of the server's mail function. It is only ever contacted after you enable the module and enter an API key.

* Service: Resend, https://resend.com
* Endpoint: https://api.resend.com/emails
* When: on every email WordPress sends (wp_mail), while the module is enabled and configured
* Data sent: the sender and recipient addresses (including cc/bcc and reply-to), the subject, the message body, any attachments, any custom headers the sending code added to the message, and your API key for authentication. In other words, the complete message - whatever a plugin, theme or WordPress itself puts in an email passes through Resend.
* Data received: delivery status events (delivered, bounced, opened, clicked) sent back to this site's webhook endpoint, which are stored in the module's local send log
* Terms of Service: https://resend.com/legal/terms-of-service
* Privacy Policy: https://resend.com/legal/privacy-policy

The module keeps a local send log (recipient, subject, status - not the message body) of the most recent messages. It can be switched off in the module settings, and it is deleted when the plugin is uninstalled.

= GitHub (update checks, and the Onboarding module) =

Two things reach GitHub, both only from your server and never from a visitor's browser.

This plugin can update itself from its public GitHub repository, periodically asking for the latest published release.

The Onboarding module additionally installs three items from public GitHub repositories - the Hello Digitizer child theme, Elementor MCP and MCP Adapter. It asks for each repository's latest release and then downloads the archive. Installing happens only when an administrator opens the Onboarding screen and presses its button.

While that module is enabled it also keeps those three items' update information current, asking each repository for its latest release while WordPress runs one of its own update checks, and answering from that cache in between. This is what lets WordPress offer them updates at all: they are not in the WordPress.org directory, and the wizard leaves most of them inactive, so their own code cannot check for them. Nothing is looked up while a page is merely being displayed, to the admin or to a visitor: those requests use what is already cached or report nothing.

* Service: GitHub, https://github.com
* Endpoints: https://api.github.com/repos/Digitizers/digitizer-pro-tools/releases for updates; https://api.github.com/repos/{owner}/{repo}/releases/latest for each onboarding item, followed by the archive download it points to (codeload.github.com, objects.githubusercontent.com or release-assets.githubusercontent.com)
* When: updates during WordPress's normal update checks and when an administrator installs an offered update; onboarding installs only while a run is in progress; the baseline's update lookups only while WordPress performs an update check of its own, never on an ordinary page load. A lookup made for an install is cached for six hours; one made for the baseline's update information is asked again at every update check and kept for three days between them, so the gap between two checks never leaves it empty. A failed lookup is kept for fifteen minutes
* Data sent: nothing about the site, on any of these requests. No site URL, admin details or usage data - the plugin replaces WordPress's default user agent (which would otherwise include the site address) with just its own name and version, on the lookups and on the downloads alike. As with any HTTP request, GitHub sees the originating IP address.
* Terms of Service: https://docs.github.com/site-policy/github-terms/github-terms-of-service
* Privacy Policy: https://docs.github.com/site-policy/privacy-policies/github-privacy-statement

= WordPress.org (Onboarding module) =

The Onboarding module installs the rest of its list from the WordPress.org plugin and theme directories, using the same APIs the built-in "Add Plugin" screen uses.

* Service: WordPress.org, https://wordpress.org
* Endpoints: https://api.wordpress.org/plugins/info/1.2/ and https://api.wordpress.org/themes/info/1.2/, followed by the package download at https://downloads.wordpress.org
* When: only while an administrator is running the Onboarding wizard
* Data sent: the slug being installed, plus whatever WordPress itself attaches to its API requests. Nothing is added by this plugin
* Privacy Policy: https://wordpress.org/about/privacy/

= Content you embed yourself (Embed module) =

The Embed module - disabled by default - does not contact anything on its own. It is worth understanding, though, that the `[dpt_embed]` shortcode places the URL you give it in an iframe, so **the visitor's browser** loads that document directly from wherever it is hosted. Nothing is proxied through your site.

* When: whenever a page containing a `[dpt_embed]` shortcode is viewed
* Who: the visitor's browser, not your server
* Data disclosed to that host: at minimum the visitor's IP address, browser user agent and the referring page, plus any cookies that host has already set in the browser
* Typical hosts: Google (docs.google.com / drive.google.com) for Docs, Sheets, Slides, Forms and Drive previews - see https://policies.google.com/terms and https://policies.google.com/privacy - or whichever server hosts a PDF you link to

If your site shows a cookie or privacy notice, embedded documents are third-party content and normally belong in it.

== Frequently Asked Questions ==

= Do I have to use every module? =

No. Modules are toggled independently from the Modules screen, so you can enable only what a given site needs.

= Which modules are active right after activation? =

None. Every module ships disabled, so activating the plugin changes nothing on the front end, nothing in the admin beyond its own Modules screen, and nothing for visitors.

Open **Digitizer Pro Tools** in the admin menu and switch on what this site needs. On a new site that usually means starting with **Onboarding**, which installs the standard theme and plugin set for you.

Updating an existing site does not change what it already has: a module you switched on stays on, and one you switched off stays off. The new default applies only to modules that site has never saved a choice for.

= Does the plugin send any data anywhere? =

There is no telemetry, no analytics and no "phone home": the plugin never collects information about your site or its visitors and sends it anywhere on its own initiative.

Data does leave, but only where a module's job is to send it, and only for the modules you switch on. Everything below is described in full under "External services" above:

* **Resend Mail**, if you enable it and enter an API key, sends your site's email through the Resend API - the complete message, including recipients, subject, body and attachments. That is what the module is for.
* **Embed** makes the *visitor's browser* load the document you embedded straight from its host, so that host sees the visitor. The request comes from the visitor, not from your server.
* **Onboarding**, while an administrator is running it, asks WordPress.org and GitHub for the packages it installs. While enabled, it also asks GitHub for the latest release of the three items it installs from there, so WordPress can offer them updates. Nothing about the site is sent.
* **The update check** asks GitHub for the latest release, on WordPress's normal schedule. Nothing about the site is sent.

Nothing else in the plugin makes an outbound request.

= Does the cookie banner work with page caching? =

Yes. The banner is rendered hidden and an inline head precheck decides whether to show it, so cached pages and CDNs never display it to visitors who already answered. Consented third-party tags are injected client-side rather than baked into cached HTML.

= Which languages does the admin support? =

The admin interface is English with a complete Hebrew translation. The cookie banner texts themselves can be set for any number of languages.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin through the Plugins menu
3. Open "Digitizer Pro Tools" in the admin menu. Every module ships disabled; switch on the ones this site needs
4. On a new site, switch on "Onboarding" first and run it to install the standard theme and plugin set. It activates the child theme and Elementor, and leaves the rest installed for you to enable per client
5. Configure each module you enabled from its own screen. For the Cookie Banner that means reviewing the texts per language and pasting your analytics/marketing snippets in the Scripts tab, then checking the site

== Changelog ==

= 1.27.0 =
* New module: REST Bridge - puts this site's own custom fields on the REST API, discovered from the definitions JetEngine already stores, repeaters included. Also exposes Rank Math's SEO fields on posts and pages, and endpoints for reading and editing Elementor content
* The module replaces the standalone Digitizer API Extensions plugin and stands down while that plugin is active

= 1.26.0 =
* Update Policy stands down when the standalone Update Policy plugin is active. The module was extracted into a plugin of its own for the WordPress.org directory; a site running both would have two filters on one update transient and two screens saying the same thing, so the module now does nothing on such a site and says so on the Modules screen

= 1.25.0 =
* Site Tweaks: three more toggles, all off by default, taken from the snippets the Hello Digitizer child theme used to carry
* Do not load Elementor icon fonts - drops Font Awesome and eicons, four stylesheets a design without icons never needs. A design that does use one shows an empty square, so the switch says to check first
* Do not load block styles on the front end - a site built entirely in Elementor never uses them, and the editor is unaffected
* Edit in the classic editor - one way to edit content on a site built in Elementor rather than two

= 1.24.0 =
* New module: Update Policy - holds a major WordPress release back for a set number of days after this site is first offered it, so the plugins and themes running here have time to catch up with it. Disabled by default, like every module
* Security and maintenance releases are never held. They are what keeps a site safe, WordPress installs them on its own, and this module does not look at them
* A held release is removed from the update offer rather than merely discouraged - a warning does not stop the button being pressed - and the Updates screen says which version is held, when the site first saw it, and when the hold ends
* The hold can be lifted for a release at any time from that notice, permanently for that branch
* The window is counted from the day this site first saw the release, not from the day it was published: no external service is asked, and a site checks for updates twice a day, so the difference is under half a day

= 1.23.0 =
* Onboarding now reports updates for the three items it installs from GitHub - the Hello Digitizer child theme, Elementor MCP and MCP Adapter. They are not in the WordPress.org directory and the wizard leaves most of them inactive, so their own code never runs and nothing on the site would otherwise look for a new version. They now appear on the Plugins and Themes screens like anything else, and can be updated with the usual button
* Because of that, those items can now be put on automatic updates as well - previously the wizard excluded them, since an entry on WordPress's list was a promise nothing would act on
* Update information for them is never fetched from a visitor's page load, only from the admin or from cron, and a failed lookup is remembered for fifteen minutes so an unreachable GitHub cannot slow the dashboard down
* An update answer that another source already provided - a plugin's own checker, or the WordPress.org directory - is never overwritten
* What this reports is added as WordPress reads its update lists, not written into them, so disabling the Onboarding module removes the offers in the same request rather than leaving one behind that nothing is left to install safely

= 1.22.0 =
* The Onboarding wizard's automatic-updates checkbox now covers Digitizer Pro Tools itself. The plugin brings its own update check, so WordPress installs its releases unattended exactly as it would a plugin from the directory - which is why it is included while the baseline's other GitHub items are not

= 1.21.0 =
* Onboarding now installs the baseline without switching it on. Only the Hello Digitizer child theme and Elementor are activated; the other eleven plugins are installed and left inactive, so each client site runs what you choose for it
* Onboarding can turn on WordPress's automatic updates for the items it installed - a checkbox on its screen, on by default, and it never touches a plugin that is not on the list. Items installed from GitHub are left out, because WordPress has no way to check them, and the checkbox is not offered at all on a site that has background updates turned off. Re-running applies it to items that were already in place, so an older site catches up. Offered only to a user who may update both plugins and themes - and on a multisite network, only to a network administrator, since the lists are network-wide
* Onboarding can remove the default themes a finished site no longer needs. It keeps the newest one and the theme WordPress is configured to fall back to if the active theme ever breaks, and it never removes the active theme, a theme another theme depends on, or a theme that only carries a core directory name without being one of WordPress's own
* Every module now ships disabled, Onboarding included. Activating the plugin changes nothing at all until you switch something on from the Modules screen

= 1.20.0 =
* New module: Onboarding - a wizard that installs and activates the Digitizer baseline (Hello Elementor plus the Digitizer child theme, and twelve plugins) on a new site. Anything already active is left alone, the run is repeatable, and each item reports what happened
* Every other module now ships disabled. A new site starts empty and you turn on what that client needs. Existing sites are unaffected - a module you already switched on stays on
* Onboarding is the one module that ships enabled, so a fresh site has a reachable wizard
* The test suite moved into the repository, and neither it nor the design documents are included in the built ZIP

= 1.19.0 =
* The code-highlighting module is now called Code Highlighter, in the modules list, its settings page, the block inserter and the Elementor widget. Nothing about it changes otherwise - saved settings, existing blocks and existing shortcodes are untouched
* Module descriptions no longer name other plugins. What each module does, and which saved markup it can read, is described directly instead

= 1.18.0 =
* Cleared every error reported by the official Plugin Check tool, so the codebase now passes the WordPress.org automated review
* Code Highlighter: the block editor's own labels are now extractable by translation tools - two of them ("Code settings" and the block description) had never reached the Hebrew catalog and were showing in English
* Cookie Banner: the banner's inline CSS documents why HTML escaping is inapplicable inside a <style> element, and every value in it is int-cast, escaped at assignment or allowlisted
* Cookie Banner: the admin preview's inline style attributes are escaped
* Content Control, Cookie Banner: the page dropdowns escape their "no selection" label
* WooCommerce Checkout: documented why the block-checkout phone error is thrown as plain text - it is serialized into a JSON response, so escaping it here would show HTML entities to the customer
* Documented, at each site, why a superglobal is read without a sanitizer or a nonce - covering read-only screen flags and values validated further down the call
* Tested up to WordPress 7.0

= 1.17.0 =
* Added an "External services" section to this readme documenting the Resend email API, the GitHub update check and the third-party content the Embed module loads in the visitor's browser - what is sent, when, and links to each service's terms and privacy policy
* Added a Frequently Asked Questions section, a LICENSE file and the License URI header
* Update checks and update downloads no longer disclose the site address: WordPress's default user agent (which embeds the site URL) is replaced with just the plugin name and version on both requests
* Documented exactly which three modules are active immediately after activation
* Code Highlighter: the legacy [enlighter] shortcode is no longer claimed by default. It is opt-in via the dpt_en_legacy_shortcode filter and stands down when the plugin that owns that tag is active, so the two can coexist
* Cookie Banner: font weight, border style and background size/position/repeat are now pinned to the values the settings screen offers, so a crafted request cannot inject CSS
* Hardened admin screens: the page query argument is sanitized before comparison
* Removed an unused parameter from the Site Tweaks settings renderer

= 1.16.0 =
* Cookie Banner: separate box width for desktop and mobile, so the banner can be a narrow corner card on desktop and full-width on phones
* Cookie Banner: font control - inherit the site font (default), follow Elementor's primary global font, or set a custom font stack; the close button no longer forces Arial

= 1.15.0 =
* Added self-updates from GitHub: the plugin now offers its own updates on the WordPress Plugins and Dashboard > Updates screens, pulled from tagged GitHub Releases of the public repository (via the bundled Plugin Update Checker library)

= 1.14.0 =
* New module: Embed - a [dpt_embed] shortcode that embeds PDF files and Google Docs/Sheets/Slides/Forms/Drive links in a responsive frame, covering the sources WordPress core oEmbed does not (module ships disabled; enable per site)

= 1.13.0 =
* New module: Checkout Field Editor - show/hide/require/reorder standard WooCommerce checkout fields and add custom text/dropdown/checkbox fields saved to the order and shown in the admin and emails (classic checkout; module ships disabled; enable per site)

= 1.12.0 =
* New module: Name Your Price - customer-set pricing on chosen WooCommerce products with server-enforced minimum/maximum/suggested prices (module ships disabled; enable per site)

= 1.11.0 =
* New module: Resend Mail - delivers all wp_mail() email through the Resend API with a send log, webhook-fed delivery statuses (signed, replay-protected), forced verified sender and automatic fallback to the default mailer on API errors (module ships disabled; enable per site)

= 1.10.0 =
* New module: Rank Math Breadcrumbs - adds a Blog crumb on post contexts and a Shop crumb on WooCommerce product pages to the Rank Math breadcrumb trail, with auto-detected URLs/labels and manual overrides (module ships disabled; enable per site)

= 1.9.0 =
* New module: WooCommerce Checkout - email-domain typo suggestions and Israeli phone-number validation (client-side hint plus a server-side check) on the checkout billing fields (module ships disabled; enable per site)

= 1.8.0 =
* New module: Site Tweaks - HTTP security headers, sanitised SVG uploads, hiding the WordPress version, and Elementor helpers (disable Google Fonts, phone-field validation), each an independent toggle (module ships disabled; enable per site)

= 1.7.0 =
* New module: Code Highlighter - dependency-free code syntax highlighting via a block, the [dpt_code] shortcode, automatic pre/code highlighting and an Elementor widget (module ships disabled; enable per site)

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
