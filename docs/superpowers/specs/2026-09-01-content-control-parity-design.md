# Content Control parity — global restrictions, rule engine, full enforcement

**Date:** 2026-09-01 · **Module:** `modules/content-control` · **Approved by:** Ben (scope: "everything except blocks", approach A)

## Goal

Bring the DPT Content Control module to feature parity with the free Content Control plugin (v2.7.3, code-atlantic), excluding Gutenberg per-block controls. Reference inventory of the plugin's features and mechanisms was extracted from the plugin source (Restriction model, rule engine, enforcement paths, widgets, shortcode, settings).

Out of scope: per-block editor controls (React/JS build), REST handling beyond 403, nested rule groups, paywall/teasers, WooCommerce rules, scheduling, QueryMonitor/TrustedLogin/logging/upgrade pipeline.

## What exists today (unchanged by this work)

- Per-post metabox meta (`_dpt_cc_visibility`, `_dpt_cc_roles`, `_dpt_cc_message`) with content/excerpt/feed/REST blanking.
- Whole-site protection (login/roles; deny = login redirect / page redirect / message; exempt IDs).
- Per-menu-item visibility with descendant hiding.
- `[dpt_restrict]` shortcode.
- Settings page (whole-site + default message) under the DPT menu.

**Precedence rule:** per-post meta wins over global restrictions. If a post has non-public `_dpt_cc_visibility`, the existing per-post path handles it and global restrictions are not consulted for that post. Whole-site protection runs first of all, as today.

## New architecture

Four new files in `modules/content-control/`, loaded from `class-dpt-cc-module.php`:

### 1. `class-dpt-cc-restrictions.php` — model + store

- Option `dpt_cc_restrictions`: ordered array of restriction rows. Array order = priority (first match wins). IDs are stable strings (`r_` + uniqid) for edit/delete addressing.
- Row schema (all keys always present after sanitization):

```php
array(
  'id'                => 'r_abc123',
  'title'             => '',            // label
  'enabled'           => true,
  'who'               => array(
     'status'     => 'logged_in',       // logged_in | logged_out
     'role_match' => 'any',             // any | match | exclude
     'roles'      => array(),           // role slugs
  ),
  'protection'        => array(
     'method'           => 'redirect',  // redirect | replace
     'redirect_type'    => 'login',     // login | home | custom
     'redirect_url'     => '',
     'replacement_page' => 0,           // 0 = message, >0 = replace with page
     'override_message' => false,
     'custom_message'   => '',
     'show_excerpts'    => false,
  ),
  'archive_handling'  => 'filter',      // filter | hide | replace_page | redirect
  'archive_page'      => 0,
  'archive_redirect_type' => 'login',   // login | home | custom
  'archive_redirect_url'  => '',
  'query_handling'    => 'filter',      // filter | hide  (secondary queries)
  'show_in_search'    => false,         // false => force-hide in search queries
  'conditions'        => array(
     'operator' => 'and',               // and | or
     'items'    => array( /* rule | group */ ),
  ),
)
```

- Rule item: `array('type'=>'rule','name'=>'content_is_page','not'=>false,'options'=>array(...))`.
- Group item: `array('type'=>'group','operator'=>'or','items'=>array(<rules only>))` — one nesting level, groups contain only rules.
- API: `all()`, `enabled()` (sanitized, cached per request), `get($id)`, `save_all($rows)`, `sanitize_row($raw)`, `match($context)` → first enabled restriction whose conditions match the current context, with per-request cache keyed like the plugin (`post-{id}` / `term-{id}` / `{context}_{md5(query_vars)}`).
- `who_allows($who)` — evaluates status + role_match (any/match/exclude) against current user; `manage_options` bypass preserved via `DPT_CC_Access::user_can_bypass()`.

### 2. `class-dpt-cc-rules.php` — rule engine

- Registry of rule definitions built lazily on first use (post types/taxonomies registered by then): `name`, `label`, `category`, `fields` (for admin form), `callback`.
- Built-in rules (PHP callbacks; names mirror the plugin where sensible):
  - `entire_site`, `content_is_front_page`, `content_is_blog_index`, `content_is_search_results`, `content_is_404_page`
  - Per public post type `{pt}`: `content_is_{pt}`, `content_is_{pt}_archive` (if `has_archive` or `post`), `content_is_selected_{pt}` (ID list, comma-separated), hierarchical: `content_is_child_of_{pt}`, `content_is_ancestor_of_{pt}`; page only: `content_is_page_with_template` (template file name)
  - Per public taxonomy `{tax}`: `content_is_{tax}_archive`, `content_is_selected_tax_{tax}` (term ID list), hierarchical: `content_is_child_of_tax_{tax}`
  - Combo: `content_is_{pt}_with_{tax}` (has any of the given term IDs)
- Evaluation: `check($conditions, $context)` — AND/OR with short-circuit; group = inner operator over its rules; `not` inverts a rule's result; empty items ⇒ no match (restriction inactive), matching the plugin's "no rules ⇒ not restricted".
- Context object passed to callbacks: `array('type' => 'main'|'post'|'term'|'rest', 'post' => WP_Post|null, 'term' => WP_Term|null)`. Callbacks branch: `main` uses conditional tags (`is_front_page()` etc.); `post`/`term` evaluate against the given object (e.g. `content_is_page` checks `$post->post_type === 'page'`); `rest` maps the route to a post/term check.
- Unknown rule name ⇒ rule evaluates false (fail closed) — no logging subsystem.
- Filter `dpt_cc_rules` to add custom rules.

### 3. `class-dpt-cc-enforce.php` — frontend enforcement

- `template_redirect` @ 5 (after site protection @1): if main query matches a restriction and `who_allows()` fails:
  - `redirect` → login (`wp_login_url(current_url)`) / home / custom URL (`wp_safe_redirect`; custom host added to `allowed_redirect_hosts`).
  - `replace` + `replacement_page > 0` → swap the main query to that page (plugin's `set_query_to_page` approach: new query vars, re-run `get_posts`).
  - `replace` + message → do nothing here; content filter handles it.
  - Then, archive handling for restricted posts inside a main archive query: highest-priority match with `archive_handling` = `redirect`/`replace_page` acts the same way.
- `the_posts` @ 10 (registered on `init` @ 999): for each post in a non-ignorable query matching a restriction the user fails: handling = `archive_handling` (main query) or `query_handling` (secondary); search + `!show_in_search` forces `hide`; `hide` unsets the post and fixes `post_count`; `filter` leaves it to the content filter.
- `get_terms` @ 10: same, term-context rules only, `query_handling` `hide` unsets terms.
- Content filter integration: module's existing `should_hide()` extended — after the per-post meta check, consult `match()` for post context; message = global default unless `override_message`; `show_excerpts` prepends `<div class="dpt-cc-excerpt">` with kses-limited excerpt.
- REST: existing `filter_rest_prepare` extended the same way; additionally `rest_pre_dispatch` returns 403 `WP_Error` for collection/item routes whose intent matches a restriction (post-type routes only; conservative mapping of `/wp/v2/{rest_base}`).
- Ignorable queries: `ignore_restrictions` query var, non-public post types, ignore list (`nav_menu_item`, templates, oembed cache) — filter `dpt_cc_ignored_post_types` / `dpt_cc_ignored_taxonomies`.
- All enforcement skips admin, cron, and users passing `DPT_CC_Access::user_can_bypass()`.

### 4. `class-dpt-cc-restrictions-admin.php` — admin UI (PHP)

- A "Restrictions" tab on the existing Content Control settings page (one menu item stays). List: title, who, protection, enabled toggle, priority up/down (reorders array), edit, delete. Standard `admin_post` handlers + nonces, `manage_options`.
- Edit form: plain PHP form covering the row schema; conditions builder as repeatable rows (rule select + options field + NOT checkbox + AND/OR operator; "add group" adds an OR-group of rules). Progressive disclosure via small vanilla JS (no build step) in the module's existing asset pattern.
- Settings save purges page caches via `DPT_CB_Settings::purge_page_caches()` like the existing settings do.

### Small parity items (existing files)

- `DPT_CC_Access::can_view()` gains `$role_match` (`any|match|exclude`) — default `match` keeps current callers unchanged.
- `[dpt_restrict]` gains `excluded_roles`, `inline` (span wrapper), `class` attributes.
- Classic widget visibility: `in_widget_form` + `widget_update_callback` fields (`dpt_cc_which_users`, `dpt_cc_roles`) + `sidebars_widgets` frontend filter (skip customizer preview/REST). Lives in a fifth small file `class-dpt-cc-widgets.php`.

## Error handling

Fail closed on malformed rows (sanitize to defaults), fail closed on unknown rules; redirects always `wp_safe_redirect` + exit; never enforce in admin/cron; replacement page is always exempt from matching (avoid loops) — a restriction never applies to its own `replacement_page`/`archive_page`.

## Testing

PHPUnit (existing `tests/` harness): rule engine truth table (AND/OR/NOT/groups, empty conditions), `who_allows` incl. `exclude`, sanitize_row round-trip, first-match priority, hide handling on `the_posts`/`get_terms` (unit-style with stub queries), shortcode attribute parsing, precedence (per-post meta beats global). Manual smoke on a dev site for redirects/replace.

## Delivery

Feature branch `feature/content-control-parity`, PR to `main`, Codex review loop, version bump + readme/changelog per repo convention.
