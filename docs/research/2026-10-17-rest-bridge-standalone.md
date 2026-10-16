# Should REST Bridge be a standalone WordPress.org plugin?

**Date:** 2026-10-17
**Goal set by Ben:** visibility and leads for Digitizer (see
[2026-08-30-wporg-standalone-candidates.md](2026-08-30-wporg-standalone-candidates.md)).
**Method:** `api.wordpress.org/plugins/info/1.2/` searches, plus a read of
`modules/rest-bridge/`.

## Where the standalones stand (2026-10-17)

| Plugin | Version | Live since | Active installs (wp.org bucket) |
|---|---|---|---|
| digitizer-update-hold | 1.0.1 | 2026-08-30 | under 10 |
| digitizer-ai-agent-log | 1.2.1 | 2026-09-09 | under 10 |
| digitizer-shabbat-keeper | 1.0.0 | 2026-10-14 | under 10 |
| digitizer-site-worker | 2.23.1 | (not extracted from DPT) | 30 |

The Hebrew PTE request for all of them, Polyglots post 73531, has been published but has no reply yet. No project has a translation editor.

## What REST Bridge is

It puts a site's own JetEngine fields on the REST API, found from JetEngine's stored definitions, with repeaters and their sub-fields described. It also does:

- Rank Math's SEO keys as post meta;
- `digitizer/v1/elementor/{id}` GET and POST to read and edit Elementor content;
- `digitizer/v1/info` for agents;
- a preview screen.

It is about 4,400 lines in total.

## The market

| Search term | Results | A plugin that does this? |
|---|---|---|
| jetengine rest api | 58 | No. The top hits are general tools (admin-site-enhancements, wp-ultimate-csv-importer) and MCP connectors. |
| jetengine api | 84 | Same. |
| rank math rest api | 731 | SEO suites. Rank Math itself dominates the results. |
| elementor rest api | 1,649 | Elementor and addon packs. |

The only gap is JetEngine. No plugin on WordPress.org is dedicated to putting JetEngine fields on REST. Rank Math and Elementor are crowded searches with nothing to stand out with.

## Verdict: not as it is; maybe later, narrowed to JetEngine

**For:**
- **Lead profile.** The people who need JetEngine fields over REST are agencies wiring Crocoblock sites to automations and AI agents. That is the profile ContentEngine and SiteAgent sell to.
- **Permissions are already where a review wants them.**
  - Fields are edit-only unless the site opts them in (`public_read` comes from the `view` context).
  - Writes go through `edit_post_meta` via `map_meta_cap`.
  - The Elementor POST checks `edit_post` and Elementor's own `User::is_current_user_can_edit()`.

**Against, now:**
1. **It is shaped by a migration.** It replaces `digitizer-api-extensions`:
   - its compatibility names (the old plugin's keys, `rank_math_og_*`);
   - its `digitizer/v1` routes;
   - its preview screen, which exists to compare against the old plugin.

   A public 1.0 would either carry that API surface forever or drop it, and the module's own sites need it.
2. **It has not run on one real site yet.** The first deployment is still open (digitizer-cmo#129, digitizer-tasks#58). A standalone should follow field proof, not come before it.
3. **The review bar is higher than for the other standalones.** It writes post and term meta and `_elementor_data` over REST. The permissions are sound, but a reviewer will read every path.
4. **Three integrations in one plugin, two of them in crowded searches.** A plugin page about JetEngine is findable. A plugin page about "JetEngine, Rank Math and Elementor" competes with suites.
5. **Unverified:** whether JetEngine's own meta-box settings already offer a "show in REST" option per field. If they do, the standalone's value narrows to auto-discovery and the repeater schemas, and the plugin page has to say so. Check this before any decision.

**Trademark note:** the name may not start with a third-party mark. "REST Bridge for JetEngine" is the allowed shape; "JetEngine REST Bridge" is not.

## Recommended order

1. Deploy the REST Bridge module on one client site through the preview (`docs/rest-bridge-deployment.md`).
2. The next standalone remains the Hebrew Cookie Banner, the runner-up in the 2026-08-30 research. Rounds #55 to #62 just brought it to parity with Complianz.
3. Revisit REST Bridge once it runs on real sites, as a **JetEngine-only** standalone:
   - fields and repeaters, with no compatibility names;
   - its own namespace;
   - Elementor and Rank Math left to the DPT module.
