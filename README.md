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
| **Theme Switch** | `[dpt_theme_switch]` selects Light, Dark or System. Maps an explicit alternate palette, lists Elementor Kit colors, and remembers the visitor's choice. |
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

New releases are offered on the normal **Dashboard → Updates** screen. By default only published stable GitHub Releases are offered, never pre-releases, drafts, a branch or a bare tag. WordPress automatic-update controls and Onboarding enrollment are unchanged.

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

### Release flow and opt-in beta channel

Every release is published as a **pre-release first**, with the built installation
ZIP attached. Stable sites keep using GitHub's latest stable release. For a staging
or test site, add this to `wp-config.php` before WordPress is loaded:

```php
define( 'DPT_BETA', true );
```

The constant is read by DPT 1.54.0 and later. A site still on an older version
never sees a pre-release, even with `DPT_BETA` set: install the first beta
manually (upload the release ZIP, or rsync it), and later pre-releases arrive
through the updater.

Only boolean `true` opts in. PUC then considers stable releases and pre-releases,
skips drafts, and retains the release-only strategy (no tag or branch fallback).
Beta uses separate persisted update state, so its cached offers do not leak into
stable when opting out. Remove the constant or set it to `false` to return to
stable; an installed beta is not downgraded. Use **Dashboard → Updates → Check
again** after a channel change. This does not change automatic-update enrollment.

After the reviewed commit is ready, build with `bash bin/build-zip.sh` and prepare
`release-notes.md`. Replace `vX.Y.Z` and `REVIEWED_COMMIT_SHA` below with the release
version and reviewed full commit SHA. If the tag already exists, verify it points
to that same commit before publishing:

```bash
gh release create vX.Y.Z dist/digitizer-pro-tools.zip --repo Digitizers/digitizer-pro-tools --target REVIEWED_COMMIT_SHA --prerelease --title vX.Y.Z --notes-file release-notes.md
```

Test the attached installation ZIP and upgrade path on staging with `DPT_BETA`
enabled, including the changed features. **Only after testing and Ben's explicit
yes**, promote that same release and asset to stable:

```bash
gh release edit vX.Y.Z --repo Digitizers/digitizer-pro-tools --prerelease=false --latest
```

Promotion offers the release to stable sites, including those with automatic
updates enabled. Permission to create a pre-release is not permission to promote
it. See the official CLI references for [create](https://cli.github.com/manual/gh_release_create)
and [edit](https://cli.github.com/manual/gh_release_edit).

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
