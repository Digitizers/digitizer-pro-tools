# REST Bridge Module — Design

Date: 2026-08-24. Target: Digitizer Pro Tools v1.27.0, module id `rest-bridge`,
directory `modules/rest-bridge/`, class prefix `DPT_RB_`.

## Goal

Replace the standalone `digitizer-api-extensions` plugin (v1.5.2, single file,
hard-coded keys) with one DPT module that exposes JetEngine fields to the REST
API by **discovery** instead of by hard-coding, and carries over the Elementor
endpoints and the Rank Math field registration.

## Consumers and the contract they rely on

- **ContentEngine** (agent-driven) — `jet_qna` on `/wp/v2/posts/{id}` is a
  required workflow gate. The name must keep working.
- **OpenClaw + wordpress-api-pro skill** — generic scripts over `/wp/v2/*`
  with Application Passwords; they read whatever `meta`/fields the site
  exposes. Discovery serves them directly.
- The old plugin's `/digitizer/v1/elementor/{id}` routes stay as they are
  (agents know them). `faq/bulk` is dropped — no consumer in code. `faq/info`
  is replaced by a richer `/digitizer/v1/info`.

## Decisions (locked with Ben)

1. One module, not a split.
2. Discovery scope: JetEngine meta boxes for **post types and taxonomies**.
   CCTs and Options Pages are out (future version if ever needed).
3. Discovery source: the **raw option `jet_engine_meta_boxes`** (approach A).
   No dependency on `jet_engine()` runtime internals. Meta boxes registered
   in PHP by other plugins are not seen - none exist on Digitizer sites.
4. Fields are exposed under their **real names** (`qna` as `qna`), plus a
   compatibility layer (below).
5. Keep `/digitizer/v1/elementor/{id}` GET/POST; smart `/digitizer/v1/info`;
   no bulk endpoint.
6. When the old plugin is active the module **stands down**
   (`standing_down_reason()`, the 1.26.0 mechanism).

## Components

### DPT_RB_Definitions — read and normalize the option

`jet_engine_meta_boxes` is an array of rows shaped
`{ id, args: { name, object_type, allowed_post_type[], allowed_tax[], ... },
meta_fields: [ { name, title, object_type, type, repeater-fields[], ... } ] }`.

`definitions()` returns a flat list of normalized field descriptors:

```
[
  'meta_key'    => 'qna',                  // meta_fields[].name
  'title'       => 'FAQ',                  // meta_fields[].title
  'object'      => 'post' | 'taxonomy',    // args.object_type
  'targets'     => [ 'post', 'page' ],     // allowed_post_type / allowed_tax
  'type'        => 'repeater',             // meta_fields[].type
  'fields'      => [ ...sub-descriptors ], // repeater only, from repeater-fields
]
```

Defensive parsing: a row or field missing `name`/`type`, or with an unknown
`object_type`, is skipped and recorded in `skipped()` (reason strings surfaced
by the info endpoint). Entries in `meta_fields` whose `object_type` is not
`field` (tabs, accordions) are skipped silently - they are UI chrome, not data.
Fields of unknown `type` are skipped and recorded. The option is read once per
request (memoized); there is no caching layer - `get_option` is already cached
by WordPress.

### DPT_RB_Schema — JetEngine type → REST schema + sanitizer

| JetEngine type | REST schema | write sanitizer |
|---|---|---|
| text | string | `sanitize_text_field` |
| textarea | string | `sanitize_textarea_field` |
| wysiwyg | string | `wp_kses_post` |
| number | number | `floatval` (int when step absent/integer) |
| checkbox / switcher | boolean-ish (string) | `'true'`/`'false'` normalized |
| select / radio | string | `sanitize_text_field` |
| date / time / datetime-local | string | `sanitize_text_field` |
| media | integer (attachment id) | `absint` |
| repeater | array of objects; properties from sub-fields | recurse per sub-field |

A repeater value is accepted only as an array of arrays; each item is filtered
to the **known sub-field keys** (unknown keys dropped), each value sanitized by
its sub-field type. Empty array clears the meta (`delete_post_meta` /
`delete_term_meta`; a failed delete with meta still present reports failure).

### DPT_RB_Fields — registration

On `rest_api_init`:
- For each descriptor with `object === 'post'`: `register_rest_field` on each
  target post type that is REST-enabled (`show_in_rest`), under the real meta
  key name.
- For `object === 'taxonomy'`: same on each REST-enabled taxonomy.
- `get_callback` normalizes stored values: repeater values that arrive as a
  JSON string or a PHP-serialized string are decoded (the old plugin's
  behaviour); non-arrays return `[]` for repeaters, `''` for scalars.
- `update_callback` validates against the schema, sanitizes, writes. Returns
  `WP_Error` (400) on shape violations. Capability enforcement is core's:
  `/wp/v2/*` update requests already require `edit_post`/`manage_terms` on the
  target before field callbacks run.

**Compatibility layer**, registered after discovery, each item only when
discovery did not already expose the name:
- `jet_qna` on posts — alias to the `qna` descriptor when one was discovered;
  otherwise a hard-coded repeater of `{question, answer}` (old plugin
  behaviour) writing to the `qna` meta key.
- `reading_time` (posts, text), `author_description` (authors taxonomy,
  wysiwyg), `author_image` (authors, url → `esc_url_raw`), `linkedin`
  (authors, url) — the old plugin's hard-coded set, kept because consumers use
  them and they may be plain meta rather than JetEngine fields. Registered
  only if the `authors` taxonomy / target object exists.

### DPT_RB_Elementor — ported endpoints

- `GET /digitizer/v1/elementor/{post_id}` — simplified widget tree +
  `widget_count` (same response shape as the old plugin).
- `POST /digitizer/v1/elementor/{post_id}` — `{updates: [{widget_id,
  settings}]}` merge, same response shape (`updates_applied`, `not_found`).
- Both: `permission_callback` = `current_user_can( 'edit_post', $post_id )`.
- After a save: `delete_post_meta( $post_id, '_elementor_css' )` **and**, when
  Elementor is loaded, `\Elementor\Plugin::instance()->files_manager->clear_cache()`.
- Helper functions become private static methods (`tree()`, `apply()`,
  `collect_ids()`, `count_widgets()`), behaviour identical to the old
  plugin's helpers.

### DPT_RB_Rankmath

When Rank Math is active (`class_exists( 'RankMath' )`): the same 12
`rank_math_*` keys the old plugin registered, via `register_post_meta`, on
`post` **and** `page`, `show_in_rest`, `auth_callback` = `edit_posts`.
When Rank Math is absent: nothing is registered.

### DPT_RB_Info

`GET /digitizer/v1/info`, `permission_callback` = `edit_posts`:

```
{
  "module":  "Digitizer Pro Tools - REST Bridge",
  "version": DPT_VERSION,
  "fields":  { "<object>/<target>": { "<name>": <schema> } },
  "compat":  [ "jet_qna", ... ],           // what the compat layer added
  "skipped": [ "meta box X: no name", ... ],
  "routes":  [ "/digitizer/v1/elementor/{id} (GET, POST)", "/digitizer/v1/info" ],
  "rank_math": true|false
}
```

### DPT_RB_Module — glue

- `init()`: stand down when the old plugin is active — detection:
  `function_exists( 'digitizer_elementor_build_tree' )` (present in every old
  version). `standing_down_reason()`: "The Digitizer API Extensions plugin is
  active... deactivate it to let this module take over."
- Otherwise hook everything on `rest_api_init` (fields, routes). No admin
  screen, no settings, no stored options, nothing for uninstall. Front end:
  `rest_api_init` fires only on REST requests; no other hooks are added.
- JetEngine absent: discovery returns an empty list; the compat layer, the
  Elementor routes, Rank Math and info still register. The module is useful
  without JetEngine.

## Multisite

Nothing special: REST fields, meta and routes are per-site. No network
options, no `user_can_toggle` override.

## Error handling

- Malformed option rows: skipped + reported via `skipped()`; never fatal.
- Malformed stored values on read: normalized to empty (`[]`/`''`).
- Malformed write payloads: `WP_Error` 400 with a specific message.
- Elementor data invalid JSON: 500 `invalid_data` (old behaviour).

## Testing (stub harness, `tests/rest-bridge-test.php`)

1. Definitions: real-shaped option fixture (post repeater, taxonomy fields,
   tab chrome, unknown type, broken row) → descriptors + skipped list.
2. Schema: type map, repeater schema from sub-fields, sanitizers incl. kses
   pass-through stub, unknown-key dropping, "0" survives, empty array clears,
   failed delete reported.
3. Fields: registration targets (REST-enabled only), read normalization
   (array / JSON string / serialized string / garbage), alias registered only
   when missing, compat set skips discovered names.
4. Elementor: tree build, updates applied/not_found, per-post capability
   denial, cache clear called.
5. Info: shape, skipped surfaced, rank_math flag.
6. Stand-down: with the old plugin's function defined (declared in a guarded
   block), init registers nothing and the reason is non-empty.

## Global constraints

- phpcs full-run sniffs at zero (EscapeOutput, ValidatedSanitizedInput,
  NonceVerification, I18n) as in the rest of the repo.
- Hebrew catalog: new strings in `.po`/`.pot`/`.l10n.php` + `msgfmt`; key
  sets identical; count check green.
- Module ships **disabled** like all modules; registry entry + readme.txt
  section; version 1.27.0.
