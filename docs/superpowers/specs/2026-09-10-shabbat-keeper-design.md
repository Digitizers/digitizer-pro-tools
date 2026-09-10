# Shabbat Keeper: a site that closes for Shabbat and Yom Tov on its own

**Date:** 2026-09-10
**Status:** approved in conversation (Ben, 2026-09-10), spec written for review
**Applies to:** a new module `modules/shabbat-keeper/` in this repository.
Target version 1.39.0, branch `claude/shabbat-keeper`.

## What it is for

Observant Israeli businesses do not want their site trading on Shabbat or a
festival. Today they either leave it open, switch it off by hand every Friday,
or pay a SaaS that redirects visitors away. This module closes the site, or
just its commerce, from candle lighting to Havdalah, every week and every
festival, computed on the server with no external service and no manual step.

Two modes, chosen in settings:

- **Closed** - every front-end request gets a "closed for Shabbat" screen.
- **Open to read, closed for business** - pages stay readable; purchases and
  form submissions are refused and a banner says when the site reopens.

Ben's stated purpose for the plugin family is visibility and leads. The
wordpress.org field is thin (see "Market" below), so this module is also a
standalone candidate, but that is a separate decision taken after the module
is live in Digitizer Pro Tools.

## Market (wordpress.org, searched 2026-09-10)

| slug | active installs | approach |
|---|---|---|
| shamor | 400 | SaaS; redirects visitors off the site |
| shamor-shabbat-guard | 0 | same SaaS, newer plugin; block screen |
| holy-day-off | 10 | WooCommerce only; times computed by the plugin |
| shabbat-menucha | 0 | WooCommerce only; released 2026-09-02 |
| shabbat-blocker | 0 | whole-site block; times from the Hebcal API |

None is Hebrew-first, dependency-free and covering both whole-site and
commerce-only. That is the gap.

## Decisions taken

1. Both modes, one setting (Ben, Q1).
2. Times are computed locally in PHP: NOAA sunset plus a pure-PHP Hebrew
   calendar. No Hebcal, no `ext/calendar`, no "External services" entry in
   the readme (Ben, Q2).
3. Israel only: one-day festivals. Diaspora second days are out of scope and
   documented as such.
4. Business mode blocks WooCommerce and forms and shows a banner by default;
   hiding phone and WhatsApp links is a toggle, off by default (Ben, Q3).
5. Truth is computed at request time. No state flag flipped by cron. WP-Cron
   is used only to purge page caches at a transition, best effort (Ben,
   approach 1).
6. Logged-in users with `manage_options` are exempt, extensible by filter.
7. Administrators can preview the closed state with `?dpt_shabbat=preview`.

## Section 1 - the time engine

Three classes with no WordPress dependency, so the stub harness in
`tests/bootstrap.php` can test them fully. All timestamps are Unix UTC;
conversion to wall-clock time goes through `DateTimeZone('Asia/Jerusalem')`,
which handles Israeli DST.

### `DPT_SK_Hebrew_Calendar`

Gregorian to Hebrew date and back, using the Dershowitz-Reingold fixed-day
algorithm (about sixty lines: `hebrew_calendar_elapsed_days`,
`hebrew_new_year_delay`, `days_in_hebrew_year`, month lengths, leap years).

- `from_gregorian( int $y, int $m, int $d ): array{year, month, day}` with
  months numbered Nisan = 1 ... Adar = 12, Adar II = 13 in a leap year.
- `to_gregorian( int $hy, int $hm, int $hd ): array{y, m, d}`.
- `is_leap_year( int $hy ): bool`.
- `holiday_on( int $y, int $m, int $d ): string|null` - returns the key of
  an Israeli Yom Tov that falls on that Gregorian civil date, or null.

Yom Tov set (Israel, one day each):

| key | Hebrew date |
|---|---|
| `rosh_hashana_1` | 1 Tishrei |
| `rosh_hashana_2` | 2 Tishrei |
| `yom_kippur` | 10 Tishrei |
| `sukkot_1` | 15 Tishrei |
| `shmini_atzeret` | 22 Tishrei |
| `pesach_1` | 15 Nisan |
| `pesach_7` | 21 Nisan |
| `shavuot` | 6 Sivan |

Chol HaMoed, Purim, Chanukah, fast days and Yom HaAtzmaut are not closures
and are not listed.

### `DPT_SK_Sun`

`sunset( float $lat, float $lon, int $y, int $m, int $d ): int|null` -
NOAA solar position algorithm (Julian day, equation of time, declination,
hour angle at zenith 90.833 degrees). Returns the UTC timestamp of sunset on
that civil date, or null above the polar circle (not reachable with the
shipped cities, kept for the custom location).

### `DPT_SK_Zmanim`

Input: a location (`lat`, `lon`, `candle_minutes`) and `havdalah_minutes`.

- `windows( int $from_ts, int $to_ts ): array` - closed windows overlapping
  the range, each `array{start, end, reason}` where `reason` is `shabbat`, a
  holiday key, or a `+`-joined list when windows merged.
- `is_closed_at( int $ts ): bool`.
- `current_window( int $ts ): array|null`.
- `next_transition( int $ts ): int` - the next start or end after `$ts`.

A closure day is Saturday or a Yom Tov. For each closure day D:
`start = sunset(D-1) - candle_minutes`, `end = sunset(D) + havdalah_minutes`.
Adjacent closure days (Shabbat followed by Yom Tov, Rosh Hashana's two days,
Yom Tov followed by Shabbat) merge into one window: start of the first, end
of the last.

Windows are computed per request, no transient: a sixty-day scan is about
sixty sunset calculations, microseconds each, and a cache would only add a
place for a stale location to hide.

### `DPT_SK_Cities`

A fixed table `slug => array{ name (translatable), lat, lon, candle_minutes }`:

| slug | candle |
|---|---|
| jerusalem | 40 |
| tel_aviv | 18 |
| haifa | 30 |
| beer_sheva | 22 |
| eilat | 18 |
| netanya | 18 |
| petah_tikva | 18 |
| ashdod | 18 |
| bnei_brak | 18 |
| modiin | 18 |
| safed | 18 |
| tiberias | 18 |
| rehovot | 18 |
| hadera | 18 |
| kfar_saba | 18 |

Plus `custom`, which reads `custom_lat`, `custom_lon`, `custom_candle` from
settings. Coordinates are the city centre to three decimals; a kilometre
moves sunset by well under a minute.

Havdalah: `42` (default, three medium stars as commonly published in
Israel) or `72` (Rabbeinu Tam).

## Section 2 - settings and enforcement

### Settings

One option, `dpt_shabbat_keeper`, seeded by `install_defaults()`. The
module itself is off by default in `dpt_settings['modules']`, like Copy URL.

| key | type | default |
|---|---|---|
| `mode` | `closed` \| `business` | `business` |
| `city` | city slug \| `custom` | `jerusalem` |
| `custom_lat`, `custom_lon` | float | `0` |
| `custom_candle` | int minutes 0-60 | `18` |
| `havdalah` | `42` \| `72` | `42` |
| `override` | `auto` \| `force_open` \| `force_closed` | `auto` |
| `closed_title` | text | "The site is closed for Shabbat" (Hebrew in catalog) |
| `closed_message` | HTML via `wp_kses_post` | "We observe Shabbat and Jewish holidays. The site reopens automatically after Havdalah." (Hebrew in catalog) |
| `closed_show_times` | bool | `1` |
| `block_woo` | bool | `1` |
| `block_forms` | bool | `1` |
| `hide_contact` | bool | `0` |
| `banner_on` | bool | `1` |
| `banner_text` | text, `%s` = reopening time | "The site is closed for Shabbat. Orders and forms reopen at %s." (Hebrew in catalog) |

`DPT_SK_Settings::get()` returns the merged array; `sanitize( array )`
whitelists every enum, clamps numbers, and runs `wp_kses_post` on the
message. Unknown keys are dropped.

### Deciding "closed now"

`DPT_SK_Enforce::is_closed(): bool`, memoised per request:

1. `override` is `force_open` -> false; `force_closed` -> true.
2. Administrator preview (`manage_options` and `?dpt_shabbat=preview`) -> true.
3. Otherwise `DPT_SK_Zmanim::is_closed_at( time() )`.

`DPT_SK_Enforce::is_exempt(): bool` - `current_user_can('manage_options')`
unless preview, filtered by `dpt_shabbat_keeper_exempt( bool )`. Exempt
users never see a block, a banner or a refusal, so an administrator can
still buy a test product on Shabbat. Preview overrides exemption for that
one request. `applies(): bool` is `is_closed() && ! is_exempt()`, with no
regard for the kind of request. Commerce and form refusals additionally
skip cron, WP-CLI and non-AJAX wp-admin requests (`refusals_apply()`,
filter `dpt_shabbat_keeper_refuse`), so a manager can still build an order
by hand.

Requests that are never touched, in either mode: `is_admin()`, `DOING_AJAX`,
`DOING_CRON`, `REST_REQUEST`, WP-CLI, `wp-login.php`, `xmlrpc.php`. The
REST API stays open so that agents, MainWP and the block editor keep
working; the commerce hooks below cover the Store API on their own.

### Closed mode

On `template_redirect` at priority 0 - before Content Control's whole-site
protection, which exits at priority 1 - when closed and not exempt:

- `status_header( 503 )`, `Retry-After: <seconds until window end>`,
  `nocache_headers()`. The 503 alone tells search engines the outage is
  temporary; the page also carries `<meta name="robots" content="noindex">`
  so a crawler that ignores the status does not index it as content.
- Render `views/closed-screen.php` and exit. The view prints the site icon
  when there is one, `closed_title`, `closed_message`, and when
  `closed_show_times` is on, "Reopens Saturday 19:32" in the site locale,
  plus the city name. RTL when the locale is RTL. Styles come from
  `assets/closed.css` inlined into the page; no theme, no scripts.

The business-mode hooks below are also registered in closed mode, so a
POST to checkout or a form endpoint is refused even though those endpoints
never render the closed screen.

### Business mode

**WooCommerce**, when `block_woo` and WooCommerce is active:

- `woocommerce_is_purchasable` -> `false`. This removes the Add to Cart form
  in classic templates, product blocks and the Store API's
  `is_purchasable` in one place.
- `woocommerce_single_product_summary` at priority 31 prints the banner
  text as a notice on the product page.
- `woocommerce_loop_add_to_cart_link` returns a `<span class="button
  dpt-sk-closed">` with the short message, so shop grids do not show an
  empty slot.
- Server refusals, in case a cart was filled before candle lighting:
  `woocommerce_add_to_cart_validation` -> `false` with `wc_add_notice`;
  `woocommerce_checkout_process` -> `wc_add_notice( ..., 'error' )`;
  `woocommerce_store_api_cart_errors` adds a `WP_Error` so the block
  checkout and the Store API refuse.

**Forms**, when `block_forms`. The server refusal is the truth; the
replaced markup is a courtesy:

| plugin | refusal hook | markup hook |
|---|---|---|
| Elementor Pro Forms | `elementor_pro/forms/validation` -> `$ajax_handler->add_error_message()` | `elementor/widget/render_content` for widget `form` |
| Contact Form 7 | `wpcf7_validate` -> `$result->invalidate()` on the first tag | `do_shortcode_tag` for `contact-form-7` |
| WPForms | `wpforms_process_before` -> `wpforms()->process->errors[ $id ]['header']` | `do_shortcode_tag` for `wpforms` |
| Gravity Forms | `gform_validation` -> `is_valid = false`, `validation_message` | `do_shortcode_tag` for `gravityform` |

Each integration is registered only when its plugin's entry function or
class exists at `init`. The replacement markup is
`<div class="dpt-sk-closed-form"><p>banner text</p></div>`.

**Contact links**, when `hide_contact`: one `<style>` in `wp_head`:
`a[href^="tel:"], a[href*="wa.me"], a[href*="api.whatsapp.com"]{display:none !important}`.

**Banner**, when `banner_on`: printed on `wp_body_open` (with a `wp_footer`
fallback that prepends it to `<body>` with three lines of inline script when
the theme never fired `wp_body_open`). Sticky top bar, theme-neutral colours
from `assets/closed.css`, `banner_text` with `%s` replaced by the reopening
time in the site locale.

### Caches

Truth is computed per request, so the only cache problem is HTML stored by
a page cache or CDN across a transition.

- `send_headers`: when the request is an anonymous front-end GET and the
  response already carries a `Cache-Control` with a `max-age` or `s-maxage`
  longer than `next_transition - now` (capped at one hour, floored at 60
  seconds), each such value is shortened to it, other directives kept. A response with no
  `Cache-Control`, or one that forbids caching (`no-store`, `no-cache`,
  `private`) or already asks for less, is left alone: the module never
  declares a page cacheable on its own, because a page that varies by a
  non-login cookie (a post password) or by HTTP authorization would then be
  served by a shared cache to everyone. The cron purge, not the header, is
  what makes a transition take effect.
- A single WP-Cron event `dpt_sk_transition` is scheduled for the next
  transition whenever a front-end request notices none is pending. When it
  fires it does `do_action( 'dpt_shabbat_keeper_transition', $now_closed )`
  and best-effort purges known page caches: `litespeed_purge_all`,
  `rocket_clean_domain()`, `breeze_clear_all_cache`, `w3tc_flush_all`,
  `wp_cache_clear_cache()` (WP Super Cache), `sg_cachepress_purge_cache()`
  (SiteGround Optimizer) and `wpfc_clear_all_cache()` (WP Fastest Cache).
  Each guarded by `function_exists`/`has_action`. The object cache is left
  alone - `wp_cache_flush()` would blow away unrelated cached data for no
  benefit here. If cron never runs, the `max-age` header still bounds the
  damage; if both fail, only the banner or closed screen is stale -
  refusals are server-side and unaffected.

## Section 3 - administration, catalog, tests, release

### Settings screen

`Digitizer Pro Tools -> Shabbat Keeper`, `manage_options`, one POST handler
with nonce, redirect to `?dpt_saved=1`, following `DPT_CB_Admin`.

1. **Status box** at the top: "Now: open. Next closing: Friday 11.9 at HH:MM.
   Reopens: Saturday at HH:MM (Jerusalem)", a "Preview the closed site" link
   (`home_url('/?dpt_shabbat=preview')`, new tab), and a table of the next
   seven windows with their reasons. This is how Ben or a client verifies
   the times without waiting for Friday.
2. **Mode** - two radios with one-line explanations.
3. **Location** - city select with "Custom" revealing lat, lon, candle
   minutes; Havdalah 42 / 72.
4. **Closed screen** - title, message (`wp_editor`, teeny), show times.
5. **Business mode** - the four checkboxes and the banner text.
6. **Override** - auto / force open / force closed, with a warning that
   "force" ignores the calendar until switched back.

### Files

```
modules/shabbat-keeper/
  class-dpt-sk-module.php          DPT_Module subclass, id shabbat_keeper
  class-dpt-sk-settings.php        option, defaults, sanitize
  class-dpt-sk-hebrew-calendar.php
  class-dpt-sk-sun.php
  class-dpt-sk-zmanim.php
  class-dpt-sk-cities.php
  class-dpt-sk-enforce.php         is_closed, is_exempt, closed-mode 503, banner, contact CSS, cache headers, cron
  class-dpt-sk-integrations.php    WooCommerce and the four form plugins
  class-dpt-sk-admin.php
  views/closed-screen.php
  assets/closed.css
tests/sk-calendar-test.php
tests/sk-sun-test.php
tests/sk-zmanim-test.php
tests/sk-enforce-test.php
tests/sk-settings-test.php
```

Registration: one entry in `includes/class-dpt-plugin.php` after
`copy_url`, key `shabbat_keeper`, class `DPT_Shabbat_Keeper_Module`,
default `'0'`.

### Tests (stub harness, `php tests/sk-*-test.php`)

- Calendar: 1 Tishrei 5787 = 2026-09-12; 15 Nisan 5786 = 2026-04-02;
  6 Sivan 5786 = 2026-05-22; 10 Tishrei 5787 = 2026-09-21; 22 Tishrei 5787
  = 2026-10-03; round trip over every day of 5784-5787; 5784 is leap, 5785
  is not.
- Sun: Jerusalem 2026-09-11, 2026-06-21 and 2026-12-21; Eilat 2026-09-11.
  Each within 2 minutes of a reference value taken from the NOAA solar
  calculator and recorded in the test with its source; no figure is
  written down here so that the test, not the spec, is the record.
- Zmanim: a plain Shabbat has one window `Fri sunset-40 .. Sat sunset+42`;
  Rosh Hashana 5787 (Sat 12.9 and Sun 13.9) plus the preceding Shabbat is
  one window Fri 11.9 to Sun 13.9 evening; Yom Kippur 2026-09-21 (Monday)
  is its own window; `is_closed_at` is false one second before start and
  true at start; `next_transition` from inside a window returns its end.
- Enforce: exempt administrator sees nothing; preview forces closed for an
  administrator only; 503 and `Retry-After` set in closed mode; `force_open`
  wins over the calendar; `is_purchasable` filter returns the input when
  open and `false` when closed; the cache header equals seconds to the next
  transition.
- Settings: every enum rejects an unknown value and keeps the default;
  `custom_candle` clamps to 0-60; `closed_message` strips a script tag.

### Catalog, readme, version

- New strings into `languages/digitizer-pro-tools.pot`, Hebrew in
  `-he_IL.po`, then regenerate `.mo` and `.l10n.php` as every module does.
- `readme.txt`: module count 22 -> 23, a Description bullet, a changelog
  entry under `= 1.39.0 =`, and a line under "External services" saying
  this module contacts nothing.
- `digitizer-pro-tools.php`: Version and `DPT_VERSION` 1.39.0.
- Ship loop as always: branch `claude/shabbat-keeper`, build, tests, PR,
  Codex review to clean on the exact head, squash-merge, ask Ben before
  the release.

## Out of scope, on purpose

- Diaspora second-day Yom Tov and diaspora cities.
- Fast days, Chol HaMoed, Purim, Chanukah.
- WhatsApp or Telegram group locking.
- Per-product or per-page exceptions.
- A wordpress.org standalone - decided after the module is live.
- Logging closures to Agent Log.
