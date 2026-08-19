# Onboarding module — design

Date: 2026-08-20
Status: approved in chat, ready for an implementation plan
Target version: 1.20.0

## Problem

Standing up a new client site means installing the same theme and the same dozen
plugins by hand, every time. It is slow, and it is easy to forget one — a site
that silently never got Wordfence or SiteAgent looks fine until it matters.

WordPress Blueprints solve this declaratively, but they run at provisioning time
in Playground and are not available inside a live wp-admin. This module brings
the same idea to a site that already exists: one screen that reports what is
missing against the Digitizer baseline and installs it.

## Decisions taken

Settled with Ben before design:

| Question | Decision |
|---|---|
| Who runs it, and where | An **admin wizard** in wp-admin on the new site. Not remote, not WP-CLI. |
| How far it goes per item | **Install and activate only.** No configuring other plugins' settings, no seed content. |
| How much choice the operator gets | A **checklist, everything pre-ticked**. Untick what a given client does not need. |

Two consequences worth stating, because they are what keeps this small:

- We never write another plugin's options. Their settings tables are theirs, and
  seeding them couples us to their internal structure, which changes without
  notice. Configuration stays a human step.
- We never update or downgrade something already installed. "Install and
  activate" means exactly that; an existing install is left at its version.

## The baseline

The manifest lives in code, not in settings. Every slug below was verified
against the WordPress.org API on 2026-08-20.

**Theme**

| Item | Source | Identifier |
|---|---|---|
| Hello Elementor | wp.org theme | `hello-elementor` |
| Hello Digitizer (child) | GitHub | `Digitizers/hello-digitizer` → installs as `hello-digitizer` |

**Plugins**

| Item | Source | Identifier |
|---|---|---|
| Angie – Agentic AI | wp.org | `angie` |
| Cloudflare | wp.org | `cloudflare` |
| Elementor | wp.org | `elementor` |
| FluentSMTP | wp.org | `fluent-smtp` |
| Imagify | wp.org | `imagify` |
| Maspik | wp.org | `contact-forms-anti-spam` |
| Rank Math SEO | wp.org | `seo-by-rank-math` |
| Wordfence Security | wp.org | `wordfence` |
| WPCode | wp.org | `insert-headers-and-footers` |
| SiteAgent for Aura | wp.org | `digitizer-site-worker` |
| Elementor MCP | GitHub | `Digitizers/elementor-mcp` → installs as `elementor-mcp` |
| MCP Adapter | GitHub | `WordPress/mcp-adapter` → installs as `mcp-adapter` |

All four GitHub repositories are public, so no token, no credential storage and
no authentication path is needed. If a private source is ever added, that is a
new design conversation, not a config field.

### The manifest is code on purpose

There is no field anywhere in this module for pasting a plugin ZIP URL. An
admin-facing "install from this URL" box is an arbitrary-code-execution surface
dressed as a convenience, and the list it would serve is fixed. Adding an item
means editing the manifest and shipping a release, which is the correct amount
of friction for something that installs code on client sites.

## Components

Five units, each independently understandable and testable.

**`DPT_ONB_Manifest`** — the list. `items()` returns an ordered array of item
descriptors: `id`, `label`, `type` (`plugin`|`theme`), `source`
(`wporg`|`github`), `slug` (the directory the item must end up in), `repo` (for
GitHub items), and `parent` (for the child theme). Pure data; no WordPress
calls. Order matters and is part of the contract — a parent theme precedes its
child.

**`DPT_ONB_State`** — where the site is now. For each manifest item returns one
of `missing`, `inactive`, `active`. Resolving a plugin from a slug is the part
that is easy to get wrong: a plugin's main file is not reliably
`slug/slug.php`, so this reads `get_plugins()` and matches on the **directory**
component of each plugin file. Themes go through `wp_get_theme()`.

**`DPT_ONB_Source`** — turns an item into a downloadable ZIP URL. For `wporg`
items that is `plugins_api()` / `themes_api()`. For `github` items: query the
latest release, prefer a `.zip` release asset, and fall back to the branch
zipball when a repository publishes no releases. The resolved URL is cached in a
transient so a run does not spend one API call per item per attempt — GitHub's
unauthenticated limit is 60 requests per hour per IP and a shared host burns
through that quickly.

**`DPT_ONB_Installer`** — applies one item. Decides from the state what to do
(`missing` → install then activate; `inactive` → activate; `active` → skip),
then drives core's own `Plugin_Upgrader` / `Theme_Upgrader` with
`WP_Ajax_Upgrader_Skin`. Returns a result object: item id, outcome
(`installed`, `activated`, `skipped`, `failed`), and a human-readable message.

The GitHub path needs one extra step. A GitHub zipball extracts to a directory
named `repo-<sha>`, which changes on every commit — install it as-is and the
plugin lands in a different folder each time, so WordPress treats it as a new
plugin and the old copy is orphaned. The installer hooks
`upgrader_source_selection` for the duration of a single GitHub install and
renames the extracted directory to the manifest's declared `slug`. The hook is
removed immediately afterwards, so it can never affect an unrelated install
triggered by another plugin during the same request.

**`DPT_ONB_Admin`** — the wizard screen and its AJAX endpoint. Renders the
checklist with each item's current state, and exposes one endpoint that applies
exactly one item per request.

## Flow

1. The operator opens the wizard. `DPT_ONB_State` reports every item's status;
   the checklist renders with every item ticked. Items already active are marked
   as such, and stay ticked — running them is a no-op, and unticking them would
   make the list disagree with itself on a second pass.
2. They untick anything this client does not need and press the button.
3. The browser walks the ticked items **one request per item**, in manifest
   order, updating that row as each result comes back.
4. When the list is exhausted, a summary lists what was installed, what was
   skipped, and what failed with the reason for each.

One item per request is the important part. A single request that installs
fourteen things will hit `max_execution_time` on ordinary shared hosting, and
when it does, the operator has no idea how far it got. Per-item requests also
mean a failure is attributable and the rest of the run continues.

The run is **idempotent**. Running it twice is harmless: the second pass finds
everything active and skips it. This matters because the first pass will
sometimes fail partway, and "run it again" has to be a safe instruction.

## Theme activation

Activating a theme changes what visitors see. That is the only irreversible-
feeling action in the module, and it deserves its own rule rather than being
buried in a checklist row.

Hello Digitizer is activated automatically **only when the site is still on a
WordPress default theme** (`twenty*`). Otherwise it is installed but not
activated, and the summary says so with a direct link to Appearance → Themes.
A site that already has a live custom theme must never have it swapped out by a
tool the operator ran expecting it to install plugins.

The parent theme is never activated. Hello Elementor exists to be the parent of
Hello Digitizer; activating it would be a step backwards. It is marked
install-only in the manifest, and **present is its goal state** - reporting it
as merely installed-and-inactive would make the wizard "activate" it on every
run, so the row would never converge and would claim an activation that never
happened.

The child theme is activated only after confirming its parent is actually
installed. Manifest order is not a guarantee: checklist items are independently
selectable and a failure does not stop the run, so the parent can be absent when
the child's turn comes. Switching anyway would leave the public site with no
template at all.

## Failure handling

Every item is applied inside its own try/catch-equivalent and reports one of
four outcomes. Nothing aborts the run.

The state that needs naming is the half-applied item: install succeeds,
activation fails. It reports as `failed` with the message "installed but not
activated", because reporting it as success would leave a site missing a plugin
that the summary claimed was there.

Filesystem credentials are the most likely real-world failure. On a host without
direct filesystem write access, WordPress normally prompts for FTP details — a
form that cannot be shown from an AJAX request. `WP_Ajax_Upgrader_Skin` surfaces
this as an error rather than hanging, and the module reports it once, plainly:
this site needs `FS_METHOD` configured or the installs done manually. This is a
real limitation and is documented rather than papered over.

## Security

- `manage_options` plus the specific capability for the action —
  `install_plugins` / `install_themes` / `switch_themes`. A site with
  `DISALLOW_FILE_MODS` set has these capabilities removed by core, which the
  module respects rather than working around.
- A nonce on every AJAX request.
- The only network destinations are `api.wordpress.org`, `downloads.wordpress.org`,
  `api.github.com`, `codeload.github.com` and `objects.githubusercontent.com`.
  Nothing is derived from user input; the item id in the request is looked up in
  the manifest and anything unrecognised is rejected before any work happens.

## Module defaults: everything off

Separate change, folded into the same release. All fifteen existing modules move
to `default => '0'`, including the three that currently ship on (`cookie_banner`,
`duplicate_post`, `update_emails`).

**Onboarding is the one exception and ships enabled.** If every module were off,
a fresh site would have no wizard, and the operator would have to find and
enable a module before they could use the thing whose entire job is setting up a
fresh site. The module is inert until its button is pressed, so shipping it on
costs nothing.

Existing sites are unaffected: `DPT_Plugin::enabled_map()` falls back to a
module's default only for ids **not already present** in the saved option, so a
site that already turned the cookie banner on keeps it on. This is load-bearing
and gets a test rather than a comment.

## Testing

The stub-harness pattern this repo already uses — define stub WordPress
functions, `require` the real file, assert.

Covered:

- Manifest integrity: unique ids, every source valid, every GitHub item declares
  a `slug`, and the parent theme precedes the child.
- State resolution from a synthetic `get_plugins()` map, including the case the
  naive implementation gets wrong — a plugin whose main file is not
  `slug/slug.php`.
- The decision table: each of `missing` / `inactive` / `active` maps to the right
  action, and an already-active item is never touched.
- GitHub asset selection: prefers a `.zip` release asset, falls back to the
  zipball when a repository has no releases, and rejects a release whose assets
  are all non-ZIP.
- The source-directory rename: `repo-<sha>` becomes the declared slug, and the
  filter detaches afterwards.
- Theme activation gating: activates on a default theme, declines on a custom
  one.
- `enabled_map()` leaves saved module states alone when the defaults change.

Not covered, and stated plainly: the actual download-and-copy. That is core's
`Plugin_Upgrader` doing filesystem work, and there is no WordPress in this
environment to exercise it. The units around it are tested; the install itself
is verified by hand on a real site before release.

## Out of scope

- Improving `Digitizers/hello-digitizer`. Ben wants it, it is a different
  repository, and it is a separate piece of work after this lands.
- Remote triggering from Aura via SiteAgent. The design keeps the installer
  independent of the admin screen, so adding a second trigger later does not
  require restructuring, but nothing for it is built now.
- Configuring the installed plugins, seed content, and WordPress core settings.
- Updating or removing anything already installed.
