# Agent Log module — design

**Status:** design approved in conversation, spec awaiting review
**Module id:** `agent_log`
**Directory:** `modules/agent-log/`
**Class prefix:** `DPT_AL_`
**Target version:** 1.30.0

## What this is

A log of **what the automations did** to this site.

Digitizer sites are edited by software as much as by people: ContentEngine, an
open-claw agent on a remote server, SiteAgent, and WP-Cron. When a page is
wrong, the first question is not "what happened on this site" but "did an agent
touch this, and what did it touch". A general activity log answers that only by
burying it — every page view by every editor is in the same table.

So this module records one thing and refuses the rest: **writes that arrived
from somewhere other than a browser.** A change made by a person logged into
wp-admin is not recorded at all. That is a condition before the row is written,
not a filter on the way out.

### What it is not

It is not a replacement for a general activity log. The obvious one is
[ARYO Activity Log](https://wordpress.org/plugins/aryo-activity-log/) (GPLv2,
2.13.1 at the time of writing), which is mature, actively developed, covers
fourteen families of events and already distinguishes request channels of its
own. This module does not stand down for it and does not compete with it: ARYO
records everything and leaves you to search; this records little and answers
immediately. A site may run both.

The two other DPT modules that stand down for a plugin — `update_policy` and
`rest_bridge` — do so because they *duplicate* one. This one does not.

## Storage

One table, created with `dbDelta` on module activation.

```sql
CREATE TABLE {$wpdb->prefix}dpt_agent_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  logged_at      DATETIME        NOT NULL,
  channel        VARCHAR(20)     NOT NULL DEFAULT '',
  app            VARCHAR(100)    NOT NULL DEFAULT '',
  user_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  action         VARCHAR(40)     NOT NULL DEFAULT '',
  object_type    VARCHAR(40)     NOT NULL DEFAULT '',
  object_subtype VARCHAR(60)     NOT NULL DEFAULT '',
  object_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  object_name    VARCHAR(191)    NOT NULL DEFAULT '',
  fields         TEXT            NULL,
  PRIMARY KEY (id),
  KEY logged_at (logged_at),
  KEY object (object_type, object_id),
  KEY channel (channel)
) {$charset_collate};
```

`logged_at` is UTC. Rendering in the site's timezone is the screen's job.

### Three indexes, not nine

ARYO indexes almost every column. On a table whose whole purpose is to absorb
writes, each index is a tax on every INSERT, paid to make queries nobody runs
faster. Three cover the questions this module exists to answer: *what happened
recently*, *what touched this object*, *what did this channel do*.

### No IP column

ARYO stores one. Here the clients are the operator's own automations, reached
over credentials the operator issued; the address adds no answer that `app` and
`channel` do not already give, and it adds a privacy surface to a table that
otherwise holds none.

### Retention — two bounds, both filterable

A log without expiry is a table that grows forever. Pruned by a daily cron
event against **both** limits:

| Bound | Default | Filter |
|---|---|---|
| Age | 30 days | `dpt_agent_log_max_age_days` |
| Rows | 20,000 | `dpt_agent_log_max_rows` |

Either alone fails: an age bound does not stop a runaway agent filling the
table in an afternoon, and a row bound alone keeps a year of a quiet site's
history that nobody wants.

A bound set to zero or below disables *that* bound, the same way
`DPT_UP_Settings::hold_days()` treats a nonsense window — the setting turning a
limit off and the setting being nonsense are one code path, deliberately.

### Deactivating the module does not drop the table

Only uninstalling the plugin does. Deactivation is reversible and routine — the
first thing anyone does when hunting a conflict — and a log destroyed by it is
a log that is gone exactly when someone was investigating. This is not
hypothetical: it is the defect this project found in Smart Safe Auto Update
2.0.0 (`safe-auto-update.php:63`), which drops both its tables on
`register_deactivation_hook`.

## What is recorded

**The fact, and the names of the fields that changed. Never the values.**

The row says *the agent updated post 812 and changed `post_content`,
`rank_math_title` and `_elementor_data`*. It does not say what any of them
became. That keeps rows bounded, keeps the table free of a second copy of the
site's content, and keeps it from becoming the place where a leak hurts.

`fields` is a JSON array of names, written with `wp_json_encode`.

### One row per object per request

Writes are accumulated in memory during the request, keyed by object, and
flushed once on `shutdown`. Updating a post with eight meta keys is **one row
with eight names**, not nine rows.

This is the difference between a log that answers "what did that run do" and
one that has to be reassembled by eye.

## Scope

Content **and** structural changes. Rare, but structural changes are what break
a site.

| `object_type` | Recorded on |
|---|---|
| `post` | create, update, delete, status change — any post type |
| `term` | create, update, delete |
| `attachment` | create, delete |
| `user` | create, update, role change, delete |
| `plugin` | activate, deactivate |
| `theme` | switch |
| `option` | update — **allowlist only** |

### Reads are never recorded

ContentEngine polls. Logging GETs floods the table within a day and drowns the
writes that were the reason to look.

### Options are recorded from an allowlist only

`updated_option` fires for every transient. Recording all of them buries
everything else in a day. The allowlist is code, extendable by filter
`dpt_agent_log_watched_options`; it starts with the options whose change alters
how the site behaves — `siteurl`, `home`, `blogname`, `users_can_register`,
`default_role`, `permalink_structure`, `template`, `stylesheet`,
`active_plugins`.

## Channel detection

Evaluated in this order, first match wins:

| Channel | Condition |
|---|---|
| `cli` | `defined( 'WP_CLI' ) && WP_CLI` |
| `cron` | `wp_doing_cron()` |
| `xmlrpc` | `defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST` |
| `rest` | `wp_is_serving_rest_request()`, falling back to the `REST_REQUEST` constant |
| *(none)* | **nothing is recorded** |

Order matters because contexts nest — code run by WP-CLI can set `REST_REQUEST`
— and the outermost context is the true origin.

### ⚠️ Verification required before implementation

**The application-password name is an open question, not a decision.**

The intent is for `app` to carry the name of the Application Password that
authenticated a REST request, so the log distinguishes ContentEngine from
SiteAgent from a person's own token. WordPress is believed to record which
application password authenticated a request, but **no copy of WordPress core
was available to read while writing this spec, and this project has been bitten
repeatedly by asserting vendor behaviour from memory** — see the REST Bridge
audit, where reasoning about Rank Math and Elementor instead of reading them
produced four critical defects that nine review rounds had missed.

The implementer must read core before writing this:

- `wp-includes/rest-api.php` and `wp-includes/user.php` for the
  application-password authentication path
- `wp-includes/class-wp-application-passwords.php` for what a password record
  holds and how the one in use is identified
- whether a global, a filter (`application_password_did_authenticate`), or user
  meta is the supported way to name it

Design constraint that holds whatever the answer is: **when the name cannot be
determined, `app` is the empty string.** The module never guesses a name, and
never falls back to a User-Agent string dressed up as one — a fabricated
attribution in a log is worse than an absent one.

## REST API

`GET /digitizer/v1/activity`

Namespace shared with REST Bridge, registered independently: the endpoint works
whether or not that module is enabled.

**Permission:** `manage_options`. The log names who changed what, which is more
than `edit_posts` should reveal.

**Parameters:**

| Name | Type | Notes |
|---|---|---|
| `after`, `before` | string | ISO 8601, compared against `logged_at` in UTC |
| `channel` | enum | `rest`, `cron`, `cli`, `xmlrpc` |
| `object_type` | enum | as above |
| `object_id` | integer | |
| `app` | string | exact match |
| `page` | integer | default 1 |
| `per_page` | integer | default 20, maximum 100 |

**Response:** a collection, with `X-WP-Total` and `X-WP-TotalPages` headers, as
core collections do.

### No delete over the API

A log that can be erased through the API is a log an attacker erases. Clearing
is available on the screen only, behind a nonce and `manage_options`.

## Screen

One page under the Digitizer Pro Tools menu, `manage_options`. A table of recent
entries with filters for channel, object type and date range, and a clear
button. Read-only otherwise.

The screen is the bonus; the API is the product. It exists so that a person
looking at a site can answer the question without a terminal.

## Testing

Against `tests/bootstrap.php`, as `tests/agent-log-test.php`.

The valuable surface is pure and must stay pure:

- **Channel detection** — every constant and function state, including the
  nesting cases, and the browser case that records nothing.
- **Field extraction** — names from a post before/after pair; meta names
  accumulated across several hook fires in one request collapsing to one row.
- **Retention boundaries** — a row exactly on the age limit, a table exactly at
  the row cap, each bound disabled by zero, and both active at once.
- **Option allowlist** — a watched option recorded, a transient ignored, the
  filter able to add one.
- **Query parameter sanitization** — every parameter above, including values
  outside the enums and `per_page` over the maximum.

Two harness notes, learned the hard way in this repo:

- The harness has no `$wpdb`. Either the storage layer takes an injected
  writer, or the tests exercise the pure layer and the SQL is verified by
  reading it. **The first is preferred**: a storage class that cannot be
  exercised is a storage class whose bugs ship.
- Before writing an assertion, confirm it **can fail**. Three times in one
  session in this repo, a lenient stub made an assertion vacuous —
  `strpos( $x, '' )` returns `0`, a stub that always returns true asserts
  nothing, and a missing case in a helper hides the branch under test.

## Open questions for review

1. **Application-password naming** — the verification item above. If core turns
   out not to expose it, `app` stays empty for REST and the module still works;
   confirm that is acceptable rather than a reason to redesign.
2. **Multisite** — this module's table is per site, and its subject (content
   and structure) is per site too, unlike `update_policy` whose subject is the
   network. Assumed per-site with no main-site special case. Confirm.
3. **`user_id` on a cron write** — WP-Cron runs with no user. The row records
   `0`, which is correct and slightly unhelpful. No fix proposed.
