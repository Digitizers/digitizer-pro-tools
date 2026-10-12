# Shabbat Keeper standalone - implementation plan

**Goal:** `Digitizers/digitizer-shabbat-keeper` 1.0.0, a wordpress.org-ready plugin ported from `modules/shabbat-keeper/` at `cb00a55`, and Digitizer Pro Tools 1.40.0 standing its module down when the standalone is active.

**Spec:** `docs/superpowers/specs/2026-10-13-shabbat-keeper-standalone-design.md` (approved 2026-10-13).

**Execution:** inline, in this session, no subagents. The port is a rename; the plan gives the rename as a script and the new pieces as interfaces plus the tests that pin them. Code for the new pieces is written at execution against those tests.

## Global constraints

- Slug, text domain, repository: `digitizer-shabbat-keeper`. Class prefix `Digitizer_Shabbat_Keeper_`. Option `digitizer_shabbat_keeper`. Constants `DIGITIZER_SHABBAT_KEEPER_VERSION`, `DIGITIZER_SHABBAT_KEEPER_PATH`, `DIGITIZER_SHABBAT_KEEPER_URL`, `DIGITIZER_SHABBAT_KEEPER_FILE`.
- Version 1.0.0. Requires WordPress 5.7, PHP 7.2. Tested up to: the value `digitizer-ai-agent-log/readme.txt` declares.
- No external HTTP. No `ext/calendar`. Israel only.
- Shipped: root PHP, `includes/`, `views/`, `assets/`, `languages/`, `uninstall.php`, `readme.txt`, `LICENSE`. Not shipped: `tests/`, `bin/`, `design/`, `.wordpress-org/`, `.github/`, `dist/`.
- Commit trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. PR body ends with the Claude Code line.
- Public actions wait for Ben: publishing a GitHub release, submitting to wordpress.org. Creating the public repository was approved with the name.

## Task 1 - repository and port

1. `~/Documents/GitHub/digitizer-shabbat-keeper`, `git init -b main`; first commit `LICENSE` (copied from `digitizer-ai-agent-log`) and `.gitignore`; `gh repo create Digitizers/digitizer-shabbat-keeper --public --source . --push`; work on branch `claude/initial-plugin`.
2. Copy each `modules/shabbat-keeper/class-dpt-sk-<x>.php` to `includes/class-digitizer-shabbat-keeper-<x>.php` (not `-module`), `views/closed-screen.php` and `assets/closed.css` to the same paths, each `tests/sk-<x>-test.php` to `tests/<x>-test.php`, `tests/bootstrap.php` to `tests/bootstrap.php`.
3. Apply, in this order, to every copied file:

```
s/DPT_SK_/Digitizer_Shabbat_Keeper_/g
s/dpt_sk_transition/digitizer_shabbat_keeper_transition_event/g
s/dpt_shabbat_keeper/digitizer_shabbat_keeper/g
s/dpt_shabbat/digitizer_shabbat/g
s/dpt-sk-/digitizer-sk-/g
s/dpt_sk/digitizer_sk/g
s/'digitizer-pro-tools'/'digitizer-shabbat-keeper'/g
s/class-dpt-sk-/class-digitizer-shabbat-keeper-/g
s/modules\/shabbat-keeper\//includes\//g          (tests only)
s/dpt_stub_/dsk_stub_/g ; s/dpt_test_/dsk_test_/g  (tests only)
```

4. By hand: `__DIR__ . '/views/...'` and `__DIR__ . '/assets/...'` in the enforce class become `DIGITIZER_SHABBAT_KEEPER_PATH . 'views/...'` / `'assets/...'`; the bootstrap defines the four constants and drops the `DPT_*` ones the ported tests do not use.
5. Gate: `grep -rn "dpt\|DPT" includes views assets` prints nothing; the six ported tests pass.

## Task 2 - bootstrap, core, lifecycle

`digitizer-shabbat-keeper.php`: header (`Domain Path: /languages`), constants, requires, `register_activation_hook` / `register_deactivation_hook` to `Core`, `add_action( 'plugins_loaded', array( 'Digitizer_Shabbat_Keeper_Core', 'boot' ) )`.

`Digitizer_Shabbat_Keeper_Core`:
- `boot()` - `Settings::install_defaults()` when the option is missing, `Enforce::register()`, `Integrations::register()`, admin under `is_admin()`, `load_plugin_textdomain()` on `init` when `version_compare( get_bloginfo( 'version' ), '6.8', '<' )`.
- `activate()` - `import_from_dpt()` then `Settings::install_defaults()`, then `Enforce::purge_caches()`.
- `import_from_dpt(): bool` - when `digitizer_shabbat_keeper` is absent and `dpt_shabbat_keeper` is an array: `add_option( OPTION, Settings::sanitize( $dpt ) )`.
- `deactivate()` - `wp_clear_scheduled_hook( Enforce::CRON_HOOK )`, `Enforce::purge_caches()`.

`uninstall.php`: per site `delete_option( 'digitizer_shabbat_keeper' )` and `wp_clear_scheduled_hook( 'digitizer_shabbat_keeper_transition_event' )`; on multisite every site, via a closure, as `digitizer-ai-agent-log/uninstall.php` does.

Tests (`tests/core-test.php`): import copies and sanitizes; import does nothing when the option exists; activate seeds defaults on a clean site; deactivate clears the event; uninstall on a single site and on a network of two.

## Task 3 - settings additions

`Settings::defaults()` gains `credit => '0'` (a bool) and `saved => '0'`. `save()` sets `saved` to `'1'`; `sanitize()` never lets a posted `saved` through (it carries the stored value). Tests: sixteen-plus-one keys; `saved` flips on first `save()`; a posted `saved=1` through `sanitize()` alone stays `'0'`; `credit` round-trips.

## Task 4 - enforce: enqueued assets, preview nonce, credit

- `register()`: `wp_enqueue_scripts` -> `enqueue_assets()` replaces the `wp_head` printer: when business mode applies on a front request, `wp_enqueue_style( 'digitizer-shabbat-keeper', URL . 'assets/closed.css', array(), VERSION )` and, with `hide_contact`, `wp_add_inline_style()` with the contact rule.
- `print_transition_script()` uses `wp_print_inline_script_tag( $js, array( 'id' => 'digitizer-sk-transition' ) )`; `transition_script()` returns the bare JavaScript, and the closed view wraps it with `wp_get_inline_script_tag()`.
- `is_preview()`: flag, `manage_options`, and `wp_verify_nonce( $_GET['_wpnonce'], 'digitizer_shabbat_preview' )`. `preview_url()` builds the link with `wp_nonce_url()`.
- Closed view: when `credit` is `'1'`, one line `<p class="digitizer-sk-credit">` with the translated "Shabbat Keeper by %s" and a link to `https://www.digitizer.studio`.

Tests: updated enforce tests for the enqueue and the nonce; credit absent by default, present when on; preview without a valid nonce is not a preview.

## Task 5 - admin

`Digitizer_Shabbat_Keeper_Admin`: `add_options_page()` slug `digitizer-shabbat-keeper`; core markup (`wrap`, `card`, `form-table`), checkboxes with a hidden `0`; the credit checkbox in the "Closed page" section; `plugin_action_links_{basename}` "Settings" link; `admin_notices` on `plugins` and `dashboard` screens while `saved` is `'0'`, for `manage_options`, naming city and mode with a link to the screen; save through `admin_post_digitizer_sk_save` with nonce, then `Enforce::on_settings_saved()`.

Tests (`tests/admin-test.php`): notice shown before the first save and gone after; not shown without `manage_options`; `reason_label()` covers all nine keys; the row link points at the options page.

## Task 6 - catalog

`bin/build-i18n.php`: collects every gettext call in the shipped PHP, writes `languages/digitizer-shabbat-keeper.pot`, and `-he_IL.po` / `.l10n.php` from a Hebrew map seeded from this repository's `languages/digitizer-pro-tools-he_IL.l10n.php` plus the new strings; fails when a string has no Hebrew. Then `msgfmt` for the `.mo`. Gate: zero untranslated, and the script's own "missing" list is empty.

## Task 7 - packaging

`readme.txt` (spec, "Packaging"), `.distignore`, `bin/build-zip.sh` (the skip list adjusted: `design/` instead of `assets/`), `design/icon.html`, `design/banner.html`, `bin/build-assets.sh` (sources from `design/`; plus `screenshot-1.png` rendered from the real closed view through the test harness), `.github/workflows/`: `deploy.yml`, `assets.yml` (slug changed), `ci.yml` (PHP lint + the test loop on 7.4 / 8.1 / 8.3, and `wordpress/plugin-check-action@v1` on the built directory).

Gate: `bash bin/build-zip.sh`; the zip holds no `tests/`, `bin/`, `design/`, `.github/`, `.wordpress-org/`; `php -l` on every shipped file; all tests green.

## Task 8 - PR and review

Push, PR against `main`, CI green including Plugin Check, `@codex review` loop to a clean verdict on the exact head, squash-merge, pull, build the zip from clean `main`. Stop: Ben submits the zip (or approves it being done in his browser).

## Task 9 - Digitizer Pro Tools 1.40.0

Branch `claude/shabbat-keeper-standalone` (this one): `DPT_Shabbat_Keeper_Module::standalone_active()`, early return in `init()`, `user_can_toggle()` / `toggle_denied_reason()`; one test; one Hebrew string; readme changelog; version 1.40.0; PR, Codex, merge. Stop: Ben approves the release.
