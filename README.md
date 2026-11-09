# Digitizer Pro Tools

One WordPress plugin in place of the small plugins and `functions.php` snippets that Digitizer client sites used to collect - among them WPS Hide Login, User Role Editor, Content Control, WPC Name Your Price, WP Mail SMTP-style mailers and the Digitizer API Extensions plugin.

Each feature is a **module**. Activating the plugin adds a Modules screen and does nothing else: **every module ships switched off**, so a site runs exactly what you turn on, and nothing more.

- WordPress 5.8+ · PHP 7.4+ · GPLv2 or later
- Interface in English, with a full Hebrew translation. RTL is supported throughout.
- No external services unless a module's job is one, such as Resend Mail. Updates come from this repository's GitHub Releases.
- Product page (Hebrew): https://www.digitizer.co.il/digitizer-pro-tools/

---

## Modules

### Setting up and maintaining a site

| Module | What it does |
|---|---|
| **Onboarding** | Installs the Digitizer baseline on a new site: Hello Elementor with the Digitizer child theme, and the standard plugin set. It never changes what is already installed, and a second run is safe. |
| **Update Policy** | Holds a major WordPress release back for a set number of days, so plugins and themes can catch up. Security releases are never held. |
| **Update Emails** | Silences the "site updated" emails after automatic updates. Failure emails always go out. |
| **Agent Log** | Records what automations changed (REST API, WP-Cron, WP-CLI, XML-RPC): who, what and when, never the values. |
| **Site Tweaks** | Independent toggles: security headers, sanitised SVG uploads, hiding the WordPress version, Elementor helpers, and dropping front-end weight an Elementor site doesn't use. |

### Privacy and access

| Module | What it does |
|---|---|
| **Cookie Banner** | A multilingual consent banner with categories. Tagged scripts are blocked until consent, Google Consent Mode v2 and the WP Consent API are supported, and a refused category's cookies are deleted. It works through page caches and optimisation plugins. |
| **Content Control** | Restricts content by role, with global rules, redirect or replace protection, menu and widget visibility, and a site-wide login gate. |
| **Hide Login** | Moves the login page to a custom URL. `wp-login.php` and logged-out `wp-admin` requests return 404. |
| **User Role Editor** | Edit the capabilities of every role, and add, clone or delete roles. |
| **Disable Comments** | Turns comments off everywhere or per post type, including the comment feeds and the admin UI. WooCommerce reviews are protected. |
| **Shabbat Keeper** | Closes the site, or only its shop and forms, from candle lighting to Havdalah on Shabbat and Israeli holidays. Times are computed on the server. |

### Content and editing

| Module | What it does |
|---|---|
| **Duplicate Post** | One-click duplicates as drafts, with custom fields (Elementor data included), taxonomies and the featured image. |
| **Embed** | `[dpt_embed]` for PDFs and Google Docs, Sheets, Slides, Forms and Drive, in a responsive frame. |
| **Code Highlighter** | Syntax highlighting with a block, a shortcode, automatic `pre/code` and an Elementor widget. No CDN. |
| **Copy URL** | `[digitizer_copy_url]`, a copy-this-page-address field, and `[digitizer_geturl]` for dynamic values. |
| **Related Posts Fill** | An Elementor Posts widget with Query ID `dpt_related_fill` always shows a full row: the post's own categories first, then the newest posts. |
| **Rank Math Breadcrumbs** | Adds Blog and Shop crumbs to Rank Math's breadcrumb trail. |

### Marketing and performance

| Module | What it does |
|---|---|
| **UTM Passthrough** | Keeps a visitor's `utm_*` parameters on internal links, with an optional switch for HubSpot ad parameters. Runs in the browser and stores nothing. |
| **Cache Ignore Params** | WP Rocket serves visits carrying HubSpot tracking parameters from the page cache. |

### WooCommerce

| Module | What it does |
|---|---|
| **WooCommerce Checkout** | Suggests corrections for mistyped email domains and validates Israeli phone numbers. |
| **Checkout Field Editor** | Show, hide, require and reorder checkout fields, or add your own (saved to the order and the emails). |
| **Name Your Price** | Pay-what-you-want on chosen products, with minimum, maximum and suggested prices enforced on the server. |

### Integrations

| Module | What it does |
|---|---|
| **REST Bridge** | Puts the site's JetEngine fields (repeaters included) and Rank Math's SEO fields on the REST API, plus endpoints for editing Elementor content without breaking the design. |
| **Resend Mail** | Sends all site email through the Resend API, with a send log and a fallback to the default mailer. |

Every module's full behaviour, its options and its limits are in [`readme.txt`](readme.txt).

---

## Also on WordPress.org

Four modules are also published as standalone plugins, for sites that need only that one:

| Plugin | From the module |
|---|---|
| [Digitizer Cookie Consent](https://wordpress.org/plugins/digitizer-cookie-consent/) | Cookie Banner |
| [Digitizer Shabbat Keeper](https://wordpress.org/plugins/digitizer-shabbat-keeper/) | Shabbat Keeper |
| [Digitizer Update Hold](https://wordpress.org/plugins/digitizer-update-hold/) | Update Policy |
| [Digitizer AI Agent Log](https://wordpress.org/plugins/digitizer-ai-agent-log/) | Agent Log |

When a standalone is active, its module stands down so the two never run together.

---

## Installing and updating

1. Download `digitizer-pro-tools.zip` from the [latest release](https://github.com/Digitizers/digitizer-pro-tools/releases/latest).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, then activate.
3. Open the **Digitizer Pro Tools** menu and switch on what this site needs. A module with options gets its own screen in the same menu.

New releases are offered on the normal **Dashboard → Updates** screen. Only published GitHub Releases are offered, never a branch or a bare tag.

> On Digitizer's own live sites, updates go out by rsync with a permissions script, not through the WordPress updater.

---

## Development

```bash
# Tests: plain PHP scripts, no PHPUnit (see tests/README.md)
for f in tests/*-test.php; do php "$f" || exit 1; done

# The release zip, built from what git tracks
bash bin/build-zip.sh        # -> dist/digitizer-pro-tools.zip
```

- Every change goes through a branch and a pull request, with a Codex review until it is clean.
- A release is a GitHub Release with the built zip attached.
- The version lives in three places: the plugin header and `DPT_VERSION` in `digitizer-pro-tools.php`, and `Stable tag` in `readme.txt`.

**Layout:**

```
digitizer-pro-tools.php   bootstrap, version, activation hooks
includes/                 module base class, registry, admin, updater
modules/<module>/         one folder per module
languages/                .pot, Hebrew .po/.mo/.l10n.php
tests/                    one script per area
bin/build-zip.sh          release packaging
```

A new module is a folder in `modules/` with a class extending `DPT_Module`, plus an entry in the registry in `includes/class-dpt-plugin.php`. It ships switched off.

---

## Changelog and license

The full changelog is in [`readme.txt`](readme.txt#L1), under `== Changelog ==`.

GPLv2 or later. © Digitizer.
