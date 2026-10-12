# Shabbat Keeper as a standalone plugin for wordpress.org

**Date:** 2026-10-13
**Status:** proposed, not approved. Ben chose to do the standalone (2026-10-13); the name and the three defaults under "Decisions that are Ben's" are not confirmed.
**Applies to:** a new repository and wordpress.org plugin, plus a small stand-down change to `modules/shabbat-keeper/` in this repository.
**Source:** the module shipped in Digitizer Pro Tools 1.39.0 (`cb00a55`), designed in `2026-09-10-shabbat-keeper-design.md`. Behaviour is the module's unless this document says otherwise.

## Why

Ben's goal for the standalone family is visibility and leads for Digitizer. The wordpress.org field, searched again today:

| slug | active installs | approach |
|---|---|---|
| shamor | 400 | SaaS; redirects visitors off the site |
| calj | 70 | displays Shabbat times, closes nothing |
| adatosystems-friday-zmanim | 40 | times widget |
| holy-day-off, saturday-site-blocker, weekly-shabbat-times | 10 each | |
| shabbat-menucha, shamor-shabbat-guard, shabbat-blocker, keep-sabbath | 0 | |

Nothing there closes a site on computed times with no external service, covers both whole-site and commerce-only, and speaks Hebrew out of the box. The module already does all three.

## Decisions that are Ben's

1. **Name and slug - permanent on wordpress.org.** Proposed: slug `digitizer-shabbat-keeper`, name "Digitizer Shabbat Keeper", repository `Digitizers/digitizer-shabbat-keeper` (public, as the other two are). Both earlier standalones were renamed to the `digitizer-` form during directory review, so starting there avoids a second rename, and the brand in the name is the visibility the plugin exists for. The four candidates checked (`shabbat-keeper`, `shomer-shabbat`, `digitizer-shabbat-keeper`, `shabbat-mode`) are all unclaimed today.
2. **Hebrew ships inside the plugin.** Proposed: yes. The closed page and the banner are the product and their readers are Israeli; Digitizer Update Hold's Hebrew has waited on a wordpress.org translation editor for weeks, and a Shabbat plugin that greets Israeli visitors in English until someone approves a string set is not shippable. wordpress.org language packs still take precedence when they exist.
3. **A credit on the closed page, off by default.** Proposed: one setting, "Show 'Shabbat Keeper by Digitizer' under the closed message", unticked. Directory guideline 10 forbids a front-end credit without opt-in; an opt-in one is the only place the plugin can be seen by a site's visitors, which is the lead surface.

Everything else below is a default taken on Ben's behalf; each says what it costs to change.

## What the standalone is

The same nine classes, renamed, at the repository root, in the layout of `digitizer-ai-agent-log`:

```
digitizer-shabbat-keeper.php                         bootstrap, constants, requires
includes/class-digitizer-shabbat-keeper-core.php     boot, activation, deactivation, DPT settings import
includes/class-digitizer-shabbat-keeper-hebrew-calendar.php
includes/class-digitizer-shabbat-keeper-sun.php
includes/class-digitizer-shabbat-keeper-cities.php
includes/class-digitizer-shabbat-keeper-zmanim.php
includes/class-digitizer-shabbat-keeper-settings.php
includes/class-digitizer-shabbat-keeper-enforce.php
includes/class-digitizer-shabbat-keeper-integrations.php
includes/class-digitizer-shabbat-keeper-admin.php    loaded under is_admin() only
views/closed-screen.php
assets/closed.css
languages/                                           .pot, he_IL .po/.mo/.l10n.php
uninstall.php
readme.txt, LICENSE
tests/, bin/, .wordpress-org/, .github/workflows/    not shipped (.distignore)
```

| in the module | in the standalone |
|---|---|
| class prefix `DPT_SK_` | `Digitizer_Shabbat_Keeper_` |
| `DPT_Shabbat_Keeper_Module` (a `DPT_Module`) | `Digitizer_Shabbat_Keeper_Core::boot()` on `plugins_loaded` |
| option `dpt_shabbat_keeper` | `digitizer_shabbat_keeper` |
| text domain `digitizer-pro-tools` | `digitizer-shabbat-keeper` |
| cron hook `dpt_sk_transition` | `digitizer_shabbat_keeper_transition_event` |
| filters `dpt_shabbat_keeper_now`, `_exempt`, `_refuse` | `digitizer_shabbat_keeper_now`, `_exempt`, `_refuse` |
| actions `dpt_shabbat_keeper_transition`, `_purge` | `digitizer_shabbat_keeper_transition`, `_purge` |
| `?dpt_shabbat=preview`, `?dpt_sk=<t>` | `?digitizer_shabbat=preview` (with a nonce), `?digitizer_sk=<t>` |
| CSS classes `dpt-sk-*` | `digitizer-sk-*` |
| `DPT_VERSION` | `DIGITIZER_SHABBAT_KEEPER_VERSION` |

The time engine (calendar, sun, cities, zmanim) and the settings whitelist port with nothing but the rename. Version `1.0.0`. Requires WordPress 5.7 (see "Review risks"), PHP 7.2, tested up to the version the other two standalones declare.

## Where the standalone differs from the module

**It has no module switch.** Activating the plugin is switching it on. With the defaults (business mode, Jerusalem, Havdalah 42) a site that activates and forgets will refuse purchases from candle lighting in Jerusalem this Friday - which is what the plugin is for, in the wrong city for most sites by a few minutes. Until the settings have been saved once, wp-admin shows one notice on the Plugins and Dashboard screens: the times in use, and a link to the settings. A `saved` flag in the option records the first save. *To change:* an `enabled` setting defaulting to off is a ten-line alternative; it trades a wrong-city Shabbat for a first Shabbat on which nothing happens.

**Its own settings screen.** Settings -> Shabbat Keeper (`options-general.php` submenu), `manage_options`, built from core admin markup (`wrap`, `card`, `form-table`, plain checkboxes with a hidden `0`) - no Digitizer Pro Tools stylesheet exists here and the screen does not need one. A "Settings" link in the plugin's row. Same sections, same order, same status box with the next seven closures. The preview link carries a nonce, and `is_preview()` verifies it: the module justified a bare flag with a capability check, and a directory reviewer will ask for the nonce anyway.

**Translations.** `Domain Path: /languages` in the header, and the catalogs bundled. WordPress 6.8 and later register a plugin's Domain Path for just-in-time loading on their own (`wp-settings.php` calls `set_custom_path()` from 6.8; 6.7 and earlier do not), so on current cores nothing more is needed; for 5.7-6.7 `Core::boot()` calls `load_plugin_textdomain()` on `init`. The 69 module strings move across with their Hebrew; the new strings (notice, credit, row link) get Hebrew in the same commit.

**Activation, deactivation, uninstall.** Activation seeds the option (importing from Digitizer Pro Tools, below) and purges page caches. Deactivation clears the scheduled transition event and purges page caches - the module never cleared its event, a deferred minor from its final review. `uninstall.php` deletes the option and clears the event, on every site of a network, in the closure-per-site shape `digitizer-ai-agent-log` uses.

**Settings follow a site that moves from Digitizer Pro Tools.** On activation, when `digitizer_shabbat_keeper` does not exist and `dpt_shabbat_keeper` does, its values are copied through `sanitize()`. One way, once; the module's option is left alone.

**The opt-in credit** (decision 3): setting `credit`, default `'0'`; when on, the closed page prints one line under the site name, "Shabbat Keeper by Digitizer", linking to `https://www.digitizer.studio` with `rel="noopener"`. Not on the banner, not on refusals.

**Assets are enqueued, not echoed.** The module prints its stylesheet and its transition script as raw tags. The standalone enqueues `assets/closed.css` on front-end requests where business mode applies, adds the contact-hiding rule with `wp_add_inline_style()`, and prints the transition script with `wp_print_inline_script_tag()`. The closed page is the exception: it answers and exits before `wp_head`, so its one stylesheet stays inline, with the reason in a comment.

## Digitizer Pro Tools stands down

Same pattern as Agent Log and Update Policy, shipped as Digitizer Pro Tools 1.40.0:

- `DPT_Shabbat_Keeper_Module::standalone_active()` - `class_exists( 'Digitizer_Shabbat_Keeper_Core' )`.
- `init()` returns early when it is; `user_can_toggle()` is false and `toggle_denied_reason()` says the standalone is active and where its settings live.
- One Hebrew string, one test, readme changelog.

Two copies of the code then exist, as they do for Agent Log. The standalone is where changes land first; this repository mirrors them, and the start-of-session parity check that covers Agent Log covers this module too. Retiring the module in favour of the standalone is possible later and is not proposed here.

## Tests

The six `tests/sk-*-test.php` files port with the rename onto a self-contained `tests/bootstrap.php` (the standalone carries only the stubs these tests use). Added: activation seeds and imports; deactivation clears the event; uninstall removes the option on a single site and on a network; the first-save notice appears and disappears; the preview nonce is required; the credit is absent by default and present when switched on. Run as everywhere: `for f in tests/*-test.php; do php "$f" || exit 1; done`.

## Packaging for the directory

- `readme.txt` in English: what it does in the first sentence, **Israel only** in the second (one-day festivals, Israeli cities, Jerusalem time), then modes, integrations, the cache purge action, FAQ ("Does it work outside Israel?" - no; "Does it send my data anywhere?" - no), screenshots, changelog. Tags: `shabbat`, `jewish holidays`, `woocommerce`, `maintenance mode`, `israel`. "External services": none.
- `.wordpress-org/`: banner 772 and 1544, icon 128 and 256, screenshots of the settings screen, the closed page and the business-mode banner - built from HTML by `bin/build-assets.sh`, as the other two are.
- `.distignore`, `assets.yml`, `deploy.yml` copied from `digitizer-ai-agent-log` with the slug changed. Deploy runs on a published GitHub release and uses the organisation secrets `SVN_USERNAME` and `SVN_PASSWORD`.
- Plugin Check clean before submission.

## Review risks, named so they are not surprises

- **Inline output.** Reviewers want assets enqueued; the design above does that everywhere except the closed page, which cannot. If they object, the closed page can call `wp_print_styles()` for a registered handle before exiting.
- **`wp_print_inline_script_tag()` is 5.7.** Hence the 5.7 floor rather than the 5.5 the other two declare. *To change:* a `function_exists` fallback keeps 5.5.
- **`load_plugin_textdomain()`** draws a "discouraged for directory plugins" notice from Plugin Check. It is a notice, and it is why Hebrew works on 6.7.
- **A generic-sounding name** is what got the first two renamed; decision 1 answers it.
- **Refusing commerce** is the plugin's stated purpose and off for administrators; the readme says so in the first paragraph so nobody is surprised by a closed checkout.

## Order of work, and who does what

1. Ben confirms the three decisions. Creating the public repository waits for that.
2. Repository created; the port, the standalone-only pieces, tests and packaging built on a branch; PR; Codex review to a clean verdict on the exact head; squash-merge.
3. Ben submits the built zip at wordpress.org/plugins/developers/add/ from his account, or approves it being done in his browser session. Directory review follows; replies to the reviewer are drafted for Ben.
4. In parallel with the review: the stand-down in this repository, PR, Codex, merge; Digitizer Pro Tools 1.40.0 released on Ben's yes.
5. On approval: GitHub release 1.0.0, which deploys to the directory; banner and icon through `assets.yml`.
6. The Hebrew readme on translate.wordpress.org is submitted and waits for an editor, as Update Hold's does. It affects the directory page, not the plugin.

## Out of scope, on purpose

- Diaspora: second-day Yom Tov, cities outside Israel, a timezone per location. The audience that makes Digitizer leads is in Israel; this is the first candidate for 1.1 if installs ask for it.
- Fast days, Chol HaMoed, per-page or per-product exceptions, WhatsApp or Telegram locking - as in the module.
- A paid tier, licence keys, telemetry.
- Removing the module from Digitizer Pro Tools.
