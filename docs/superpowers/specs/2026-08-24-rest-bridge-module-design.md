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
| switcher | boolean | stored as `'true'`/`'false'`, read back as a boolean |
| checkbox | object of option-key => `'true'`/`'false'` | keys `sanitize_key`, values normalized |
| select (single) | string, or object for a list storage still holds | `sanitize_text_field`, per member for a list |
| select (multiple) | array of strings; also string/object for what storage still holds | `sanitize_text_field` per option |
| radio | string | `sanitize_text_field` |
| date / time / datetime-local | string | `sanitize_text_field` |
| media | integer, string or object - id, URL, or `{id, url}` | by the shape received: `absint` / `esc_url_raw`; an array member by member, **by the member's key** |
| repeater | array of objects; properties from sub-fields | recurse per sub-field |

A media field is advertised as **all three of the formats JetEngine can store
it in**, because the format is a per-field setting - `value_format`, written
beside the type in the same option row, one of `id`, `url` or `both` (the
array `{ id, url }`). Discovery carries it onto the descriptor, including for
a media column inside a repeater, where JetEngine offers the same setting.
But nothing depends on it being right: the setting belongs to JetEngine, so a
field can predate it, an editor can change it between two requests, and a
future version can spell it differently. Advertising the id alone - which is
what this did - made a URL site's own data a `400` on write and `0` on read,
`absint()` having destroyed the value on the way out. So the type is the union
`[integer, string, object]`, `sanitize()` cleans the shape in hand (an id
stays an id, a URL goes through `esc_url_raw()`, an array is cleaned member by
member and any member this bridge has no format for is kept verbatim), and
`normalize_read()` returns the shape storage holds. The setting decides one
thing only: what an *unset* field reads back as, where there is no value in
hand to read a shape off - `0` for the id format, which consumers already
rely on, and `''` for the other two, about which `0` would be a lie.

Inside the array format the member's **key** decides its treatment, not the
member's shape. `url` goes through `esc_url_raw()` because a template prints it
into a `src`; an `id` this module can read as one becomes that integer, on the
write and on the read alike, because meta storage hands a number back as the
string it kept. Every other member - the `alt`, the caption, the size JetEngine
or a site puts in that array - is kept exactly as found, the rule the repeater
path follows for its unmapped columns. Cleaning by shape instead, which is what
this did, meant "not an id, so a URL": `esc_url_raw()` ran over every member,
and core makes an address of a string that names no protocol, so an alt text of
`A hero` was stored as `http://Ahero` the first time a client wrote back what it
had just read - a 200 that destroyed data, the same failure `absint()` over a
stored URL was. The property this is held to is the round trip: what a read
hands out has to write back as itself, whole.

A union rather than a single narrowed type on purpose, and the member order is
the order core resolves it in: `rest_get_best_type_for_value()` walks the
union and falls back to `string`, which has no check of its own. `array` is
deliberately not named beside `string` anywhere - `rest_is_array()` accepts
any scalar and `wp_parse_list()` then splits it on whitespace and commas, so a
URL or a select value with a space in it would be shredded before this class
saw it. `object` is the container type core passes through untouched, which is
why it is the one a string-first field names.

A select is advertised by the same rule, from the second setting discovery
carries: `is_multiple`, JetEngine's Multiple toggle, which makes a select
store and submit an **array** where a plain one stores a string. Modelled as a
string, a multi-select read its list back as `''` and had a client's real list
refused by validation before the sanitizer ran. So a multiple select is
`[array, string, object]` with `items: string` and a single one is
`[string, object]` - each naming the other's shape, because the toggle can be
turned on between two requests and a list already in storage must still read
back with every option in it. `sanitize()` cleans the shape in hand, option by
option for a list; `normalize_read()` hands a list back as a list on a
multiple field and as an object elsewhere, so the options survive whatever the
toggle says.

One shape does not round-trip byte for byte: a single string still in storage
from before the toggle was turned on comes back in as a one-item list, because
core makes a list of a scalar written to an array-typed field before this
module sees it. The option itself is not lost, and the test says so out loud.

A switcher is advertised as a **boolean**, not as the string JetEngine stores.
Core validates a registered field against the advertised type *before* the
field's own sanitize callback runs, so a switcher advertised as `string` made
`{"featured": true}` a 400 and left the sanitizer's boolean branch unreachable -
generosity nobody could get to. `boolean` is the type that takes both spellings,
because core's own `rest_is_boolean()` accepts `true`, `false`, `'true'`,
`'false'`, `'1'`, `'0'`, `1` and `0`; the value is still **written** as
JetEngine's `'true'`/`'false'` string so JetEngine's admin keeps reading it, and
`normalize_read()` turns whatever storage holds - those strings, an older
`1`/`0`, or the empty string of a switch nobody ever touched - back into a real
boolean. Advertised type, sanitized value and read value agree, which is the
rule this class exists for, and the sub-schema of a switcher inside a repeater
follows the same three steps.

A repeater value is accepted only as an array of arrays. Each item's **known
sub-field keys** are sanitized by their sub-field type; **keys the module does
not know are kept verbatim**. This is a correction to an earlier draft of this
spec, which said unknown keys were dropped: discovery already drops sub-fields
whose JetEngine type is outside the thirteen mapped here - an icon picker, a
gallery, a nested repeater - so dropping unknown keys on write meant a read,
edit and write round trip silently deleted those columns from every row, while
answering 200. A key this module cannot shape is not a key it may delete. For
the same reason, a repeater with no mappable sub-field at all is not registered
and the reason is recorded in `skipped()`, rather than being advertised as a
field whose every item would collapse to an empty object.

Empty array clears the meta (`delete_post_meta` / `delete_term_meta`; a failed
delete with meta still present reports failure).

### Read context

A discovered field is registered in the **`edit` context only**. The module
cannot know whether a field a site defined holds public content or internal
notes, and `view` context means `GET /wp/v2/posts` returns it to anyone with no
authentication at all. The replaced plugin exposed five named fields; discovery
exposes every field a site has, so the safe default is the one that cannot
publish a client's data by accident. Authenticated consumers read them with
`context=edit`.

The compatibility fields keep `view` and `edit`, **on the target the replaced
plugin published them on and nowhere else**: `reading_time` and the
`qna`/`jet_qna` FAQ on `post`, `author_description`, `author_image` and
`linkedin` on the `authors` taxonomy. The replaced plugin already published
these, and each is public display content by nature, so keeping them public is
what stops this from being a regression for anything already running.

The match is on object *and target* and meta key, not on the object kind
alone: `object` is the broad kind (`post`, `taxonomy`) that every post-type
meta box carries, so matching on it would publish a site's own `reading_time`
on a private custom post type, or its own `author_description` on an unrelated
taxonomy, to anonymous `GET` requests. Those were never public, and a name
collision is not a reason to make them so. A schema asked for with no target
gets `edit` only, the safe answer.

A site can opt a discovered field back into public read with the
`dpt_rb_field_context` filter, which receives the context array, the descriptor
and the target. A filter rather than a setting: this module stores nothing.

### URL fields, whatever the site calls them

`author_image` and `linkedin` on the `authors` taxonomy are sanitized with
`esc_url_raw()` **even where discovery owns the name** - the one exception to
the discovery-wins rule below, and the reason it is written here rather than
only in the code.

The compatibility layer registers a legacy field only where discovery did not
already produce that name, on the sound principle that a site's own definition
knows more than a hard-coded list. But a real site - and this repository's own
fixture - defines both of these on `authors` as JetEngine **text** fields. So
discovery won the name, the `url` descriptor was dropped, and the write went
through `sanitize_text_field()`, which leaves `javascript:alert(1)` intact in
metadata this module publishes to anonymous readers and the documented theme
usage puts straight into an `href` or a `src`. The rule stepped over the
sanitizer that exists to close exactly that.

So `DPT_RB_Schema` keeps a second list of object/target/meta-key triples -
`taxonomy/authors/author_image` and `taxonomy/authors/linkedin` - whose
treatment is URL regardless of the type JetEngine gives them, and
`resolve_descriptor()` applies it once at registration, handing the schema,
the write sanitizer and the read path the same descriptor so the three cannot
disagree. The pair is the key, not the name: a `linkedin` on some other
taxonomy is whatever the site defined it as.

The trade is deliberate. A site that defined `linkedin` on `authors` as a text
field and stores something that is not a URL there will have that value emptied
by `esc_url_raw()`. That is a real cost, and it is the smaller one: the field is
named `linkedin` on a taxonomy of authors, the replaced plugin has always
treated it as a URL, this module publishes it anonymously, and the failure on
the other side is stored XSS in an `href`.

It is a second list rather than `$public_legacy` serving both, though these two
are a subset of it and the two concerns are nearly the same one seen twice -
what this module publishes to the world is what it must insist on sanitizing.
The three names left behind are why they cannot be one list: `reading_time` is
a text field, the bio is wysiwyg and the FAQ is a repeater, and `esc_url_raw()`
over any of those would destroy the value rather than protect it. For the same
reason only a field the type map already calls a plain string is forced: a
`media` field of that name already keeps its URL half out of `javascript:`, and
a repeater, checkbox or select is not a string at all, so forcing one would
answer `''` for the whole value - deleting a shape this module could not read
instead of cleaning it.

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
- The write itself goes through `wp_slash()`. `update_metadata()` unslashes
  whatever it is handed before storing it, so a sanitized value passed
  straight in reached the database with its literal backslashes removed - a
  Windows path, a regular expression, an unknown repeater column carrying
  either - and the endpoint answered 200 over a value it had quietly changed,
  because a successful write returns before the read-back comparison ever
  runs. Same rule as the unmapped repeater column and the media member: a
  value this module cannot shape is not a value it may destroy, and a write
  that changed the caller's data is not a success. The read-back comparison
  keeps comparing storage against the *unslashed* `$clean`, which is the side
  storage now holds, so the two still describe the same value - and a repeat
  write of a backslashed value, which used to be reported as a 500 by that
  same mismatch, reads as the success it is. `DPT_RB_Elementor` already
  slashed its JSON for this reason.

**Compatibility layer**, registered after discovery, each item only when
discovery did not already expose the name:
- `jet_qna` on post types — an alias of every discovered `qna` repeater, on
  that descriptor's own targets. Whether the `post` post type also needs the
  hard-coded repeater of `{question, answer}` (old plugin behaviour) is
  decided by the **`post` target**, never by the `post` object kind: a
  descriptor carries `object === 'post'` for any post type at all, pages
  included. So a `qna` repeater defined only on `page` is aliased on `page`,
  and `post` still receives the fallback, because nothing owns the `qna` meta
  key there. Only a `qna` descriptor whose targets include `post` counts as
  the owner of the post FAQ - a non-repeater owner declines the fallback and
  records why in `skipped()`. Reading ownership off the object kind instead
  would leave `/wp/v2/posts/{id}` with no `jet_qna` at all, which is
  ContentEngine's required workflow gate.
  `compat()` is a list of names, not a tally: `jet_qna` is reported once
  however many passes registered it, and `registered()` carries the
  per-target detail.
- `reading_time` (posts, text), `author_description` (authors taxonomy,
  wysiwyg), `author_image` (authors, url → `esc_url_raw`), `linkedin`
  (authors, url) — the old plugin's hard-coded set, kept because consumers use
  them and they may be plain meta rather than JetEngine fields. Registered
  only if the `authors` taxonomy / target object exists. The two URL fields
  are the exception to "each item only when discovery did not already expose
  the name": their *registration* still stands down for the site's own
  definition, but their URL *treatment* does not — see "URL fields, whatever
  the site calls them" above.

### DPT_RB_Elementor — ported endpoints

- `GET /digitizer/v1/elementor/{post_id}` — simplified widget tree +
  `widget_count` (same response shape as the old plugin).
- `POST /digitizer/v1/elementor/{post_id}` — `{updates: [{widget_id,
  settings}]}` merge, same response shape (`updates_applied`, `not_found`).
- Both: `permission_callback` = `current_user_can( 'edit_post', $post_id )`.
- Both refuse a **revision id** with a 400 `revision_not_supported`, carrying
  the parent id in the error data. One check, on the path both routes take, so
  the read and the write can never disagree about which post they mean.
  `update_post_meta()` redirects a revision id to its parent and
  `get_post_meta()` does not, so a `POST` naming a revision would otherwise
  read the revision's layout, merge the caller's changes into it and write the
  result over the **live parent page**, while reporting the revision id as the
  object it had updated - someone amending an old revision republishing it
  without being told. The permission check cannot catch this: WordPress maps
  `edit_post` on a revision to the parent's capability. Refusing rather than
  resolving to the parent, because a coherent pair cannot read one post and
  write another, so resolving would mean a `GET` for a revision quietly
  handing back the live page's layout instead - a different page than the
  caller asked for. A 400 that names the parent lets the caller choose.
- The save is checked before anything is reported. `update_post_meta()`
  returns `false` both for a refused write - a database error, a metadata
  filter that says no - and for a write that asked for the value already
  stored, so the two are told apart the way `DPT_RB_Fields::write()` tells
  them apart: by reading storage back and comparing it against the encoded
  JSON that was asked for. A refusal is a `WP_Error` with a 500 status; an
  unchanged re-write is a success.
- After a save that is known to have landed:
  `delete_post_meta( $post_id, '_elementor_css' )` **and**, when Elementor is
  loaded, `\Elementor\Plugin::instance()->files_manager->clear_cache()`.
  Neither runs on a refused write: the rendered CSS still describes the layout
  that is still stored.
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
   pass-through stub, unknown keys preserved verbatim, "0" survives, empty array clears,
   failed delete reported. A media field is exercised in each of its three
   formats - discovery carrying `value_format`, every shape accepted by the
   schema, read back as itself and unchanged by a read, write, read round
   trip through the meta store, and a stored URL specifically not read back
   as `0`. The array format is exercised member by member: the `url` member
   kept out of `javascript:` and out of `http://1`, an `id` read as an integer
   from either spelling and left alone when it is not one this module can read,
   and a pair carrying an `alt` and a nested `meta` written, read and written
   back unchanged - through the meta store as well as through the schema
   class. A select is exercised single and multiple, with discovery carrying
   `is_multiple` in each spelling JetEngine writes it, a list read back as a
   list rather than as `''`, a list still readable and writable on a field
   whose toggle this module did not see, and a plain value with a space in it
   surviving core's gate whole. A switcher round-trips from a JSON boolean *and*
   from the string form, through a model of the validation core runs before
   any of these sanitizers, and what `normalize_read()` gives back is checked
   against the schema `for_descriptor()` advertised.
3. Fields: registration targets (REST-enabled only), read normalization
   (array / JSON string / serialized string / garbage), alias registered only
   when missing, compat set skips discovered names. A value carrying literal
   backslashes round-trips through a write and a read unchanged - at the top
   level, on both meta stores, and inside a repeater item's unmapped column -
   and writing the same such value twice is still a success. The harness's
   `update_post_meta`/`update_term_meta` stubs unslash what they are handed
   the way `update_metadata()` does, and `wp_slash`/`wp_unslash` are a real
   recursive pair, so that assertion fails when the write is not slashed
   rather than passing either way. `linkedin` and
   `author_image` discovered as JetEngine **text** fields on `authors` still
   refuse `javascript:alert(1)` through the registered update callback - in
   the obfuscated spelling as well as the plain one - while a real URL round
   trips unchanged and the advertised schema still says `string`; a `linkedin`
   on another taxonomy is left as the text field the site defined, and a
   `media` field of the same name on `authors` keeps its own shape. The
   harness's `esc_url_raw` stub models what core really does: it strips every
   character a URL may not contain before reading the protocol, so an
   obfuscated spelling cannot pass a check core would refuse, and it makes an
   address of a string that names no protocol at all. The second half matters
   as much as the first - a stub that left a non-URL string alone hid a real
   defect in the media path through a whole round of review.
4. Elementor: tree build, updates applied/not_found, per-post capability
   denial, cache clear called (the harness stubs `\Elementor\Plugin` so that
   call is really made), a refused write reported as an error with the cache
   left alone, and an unchanged re-write reported as a success. A revision id
   refused identically on both routes, with the parent's stored layout, its
   rendered CSS and Elementor's cache all untouched - the harness models
   `update_post_meta()`'s redirect to the parent, so it can reproduce the
   republish this refusal prevents.
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
