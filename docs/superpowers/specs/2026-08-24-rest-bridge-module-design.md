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

A field's `meta_key` is the name JetEngine stored, **verbatim**. JetEngine
uses it as the meta key with nothing in between — `cherry-x-post-meta`'s save
loop is `update_post_meta( $post_id, $key, $value )` with `$key` taken
straight off this definition — and it never touches a repeater column's name
or a checkbox option's key on the server side at all, so any key this module
derives rather than reads is a key nothing else on the site uses.
`sanitize_key()`, which is what this ran, keeps only `[a-z0-9_-]`: a wholly
Hebrew name reduced to `''` and was then reported as *"a field with no name"*,
which was never true of it; `שדה_price` reduced to `_price` and was refused as
protected, a second borrowed reason; and `précio` reduced to `prcio`, a valid
but **wrong** key, so reads came back empty, writes created a row no template
reads, and the API answered 200 over both. Which of JetEngine's keys
WordPress will actually carry is a separate question, asked in
`DPT_RB_Fields` — see "Metadata capabilities" below.

A meta box's `object_type` is read the way JetEngine reads it, which is not
the same as reading it literally. `Jet_Engine_Meta_Boxes_Manager::register_instances()`
opens its switch with `isset( $args['object_type'] ) ? esc_attr( … ) : 'post'`,
so an **absent** key means `post` rather than "unknown" — a row saved before
that key existed, or written by an import, a migration or WP-CLI rather than
by the admin screen, is registered by JetEngine on post types and read by
every template on the site. Skipping the whole meta box cost it every field
it held, with a diagnostic saying the object type was unknown when JetEngine's
answer for it is not unknown at all. The same switch is `case 'tax': case
'taxonomy':`, so `tax` is JetEngine's older spelling and is normalized to the
one spelling the rest of this module uses. A value that is **present but not a
string** is still refused, and now with its own sentence: JetEngine hands one
to `esc_attr()`, which raises a notice on an array, and this parse runs on
`rest_api_init`.

Defensive parsing: a row or field missing `name`/`type`, or with an
`object_type` this module does not expose, is skipped and recorded in
`skipped()` (reason strings surfaced
by the info endpoint). A **name that is not a string** — a field's, a repeater
sub-field's, or a member of the list of targets — is skipped and recorded on
the same footing rather than cast: this parse runs on `rest_api_init`, and a
notice raised there corrupts the JSON of every REST response the site is
building. The list of **targets** is kept verbatim for the same reason the
keys are, and `sanitize_key()` is gone from it too. `register_post_type()`
does sanitize_key its own name, so sanitizing a post type agreed with the
registry — but `register_taxonomy()` does not: it checks the length and keys
`$wp_taxonomies` by exactly the name it was handed. A taxonomy registered as
`Authors` was therefore reduced to `authors`, matched nothing in the registry,
and took every field of its meta box with it — with no diagnostic at all,
because a dropped target was not a refusal anything recorded. That is the
meta-key defect one level up, on the target rather than on the key, and it
gets the same answer: use the name that was stored, and ask the registry in
`DPT_RB_Fields` what the site really has under it. A member of the list that
is not a string is still dropped before anything is done with it. The option belongs to another
vendor's plugin across every version it has ever had, so nothing in it may be
able to stop this module from registering everything else. Entries in `meta_fields` whose `object_type` is not
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
| checkbox | object of option-key => `'true'`/`'false'`, or list of the checked keys, by `is_array` | keys verbatim, values normalized; a list member by member |
| select (single) | string, or object for a list storage still holds | `sanitize_text_field`, per member for a list |
| select (multiple) | array of strings; also string/object for what storage still holds | `sanitize_text_field` per option |
| radio | string | `sanitize_text_field` |
| time | string | `sanitize_text_field` |
| date / datetime-local | string, or `[integer, string]` when `is_timestamp` is on | `sanitize_text_field`, or the Unix integer JetEngine's own save stores |
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
load-bearing. `rest_get_best_type_for_value()` walks the union **in the order
the schema writes it** — there is no fixed precedence and no fallback: it
takes the first member whose check says yes, and answers with the empty string
when none does. (The one exception is the empty string itself, which prefers
`string` before the walk begins.) `string` is last of the checks only in the
sense that `is_string()` is the one test that is not a coercion; it wins
wherever it is named first.

So **`string` is always named before `array`**. `rest_is_array()` says yes to
any scalar — it runs one through `wp_parse_list()` first, which is
`preg_split( '/[\s,]+/', … )` — and `rest_sanitize_array()` then returns that
split list, all of it done from the schema by
`rest_sanitize_request_arg()` before the update callback is reached. A union
that named `array` first therefore turned the option `New York` into
`["New","York"]`, and `Tel Aviv,Jaffa` into three, before this class saw
anything. Naming `string` first costs the list nothing: a real JSON array is
not a string and still resolves to `array`. Where a field has no `array`
member at all — a media field, a single select — `object` is the container it
names instead, because `rest_is_object()` says no to a non-empty scalar.

A select is advertised by the same rule, from the second setting discovery
carries: `is_multiple`, JetEngine's Multiple toggle, which makes a select
store and submit an **array** where a plain one stores a string. Modelled as a
string, a multi-select read its list back as `''` and had a client's real list
refused by validation before the sanitizer ran. So a multiple select is
`[string, array, object]` with `items: string` and a single one is
`[string, object]` - each naming the other's shape, because the toggle can be
turned on between two requests and a list already in storage must still read
back with every option in it. `sanitize()` cleans the shape in hand, option by
option for a list; `normalize_read()` hands a list back as a list on a
multiple field and as an object elsewhere, so the options survive whatever the
toggle says.

`string` ahead of `array` on the multiple variant for the reason above, and it
is the shape that variant exists for that it protects: a field whose toggle
has just been turned on while yesterday's plain string is still in storage.
With `array` named first, that string was the one shape here that did not
round-trip — `New York` read back whole and wrote back as `["New","York"]`,
a value with a comma in it split too, and both landed in storage before
`sanitize_select()` was reached. It reads back as itself and writes back as
itself now, and the list a multi-select really holds is unaffected, because a
JSON array is not a string.

A checkbox is advertised by the third such setting, `is_array`: with it on
JetEngine stores a **plain list of the checked option keys** where a plain
checkbox stores a map of every option to `'true'` or `'false'`. Modelled as
the map alone, `["red","blue"]` read back as `{"0":"red","1":"blue"}`, and
writing that object back stored `{"0":"true","1":"true"}`, which JetEngine
then reads as the two options `0` and `1` — every selection destroyed by the
read, modify and write this module documents as its contract, over a `200`.
So the list form is `[array, object]` with `items: string` and the map form is
`[object, array]`, each naming the other's shape for the reason a select does,
and `sanitize()` and `normalize_read()` both answer **the shape in hand**: a
stored list goes out as a list and writes back as a list, a stored map as a
map, and neither is ever converted into the other. Naming `array` beside
`object` is free where naming it beside `string` was not: `rest_is_array()`
ends at `wp_is_numeric_array()`, so a map resolves to `object` whichever order
they are in, and there is no string in the union for `wp_parse_list()` to
split. An option key is kept **verbatim** in both forms — as a key in the map
and as a member in the list, because it is the same piece of JetEngine's data
in two positions.

One value the shape genuinely cannot decide, and the code says so rather than
pretending otherwise: a map whose option keys happen to be `0, 1, 2 …` is a
plain list once JSON has been decoded — PHP has no other way to hold
`{"0":"true"}` — and its values are the switch strings a map holds, so nothing
distinguishes it from a list of options literally called `true` and `false`.
There, and only there, the field's own `is_array` breaks the tie. That is the
right use for a setting this module can only read: not to overrule a shape,
but to choose between two readings of the same one.

A date field is advertised by the fourth and last of those settings,
`is_timestamp`. With it on, JetEngine does not store a date at all:
`save_meta_option()` stores `strtotime( $posted )` — a Unix integer — and the
admin screen turns that integer back into `Y-m-d` or `Y-m-d\TH:i` for display,
so nothing on the site sees a date string. Advertised as a string, which is
what every date type was, a read handed back `"1767225600"` where the schema
promised a date and a write of `"2026-01-01"` put that literal string over a
value JetEngine, the admin and every `date_i18n()` template read as a number.
So the timestamp form is `[integer, string]` and `sanitize()` writes the
integer JetEngine's own save would have written: a value already timestamp-
shaped is kept as the integer it is, a date string is converted the way
`save_meta_option()` converts it, and a value nothing can make a date of is
kept exactly as it arrived rather than blanked — JetEngine's own save stores
the `false` `strtotime()` returned and empties the field, and this module does
not destroy what it cannot shape.

Two narrowings, both JetEngine's own. `to_timestamp()` refuses any
`input_type` outside `date` and `datetime-local`, so a **`time`** field with
the toggle on still stores the string it posted and carries no such setting
here. And `prepare_repeater_fields()` never puts `is_timestamp` on a repeater
column, whose value goes through `sanitize_meta()` — which has no timestamp
branch at all — so a date column **inside a repeater** is a string whatever
its row says. Discovery reads the setting as `false` there rather than leaving
it absent, so the descriptor records what this module concluded rather than
what it failed to find.

The plain form keeps the bare `string` it always had, and that asymmetry is
deliberate: it is the one place a two-form field here does **not** name the
other shape. A checkbox's two forms are a list and a map — different shapes,
told apart by looking. A date's two forms are both scalars, and "looks
numeric" is not a reliable way to tell a timestamp from a date; a site that
writes its dates as `20260101` would have its own strings turned into JSON
numbers by a union that named `integer` on the plain form. So the field's own
setting decides which form it is, and only the timestamp form names the other
shape, for a value left behind by the toggle being turned over between two
requests. The member order is the one core resolves in:
`rest_is_integer()` says yes to a canonical integer string, which is the only
form meta storage can hand back, so `integer` has to come first for a stored
timestamp to resolve to it.

`is_timestamp` is the last of these settings, and the list is closed rather
than merely "the four found so far". The storage layer acts on exactly what it
reads off a prepared control, and `cherry-x-post-meta.php` reads three
settings: `is_array` in `sanitize_meta()`, `is_timestamp` through
`to_timestamp()` in `save_meta_option()`, and the `sanitize_callback` the
control builder derives from `value_format => 'both'`. `is_multiple` is
settled one level up, in the builder. Everything else the builder reads off a
field row — `options`, `options_callback`, `allow_custom`, `max_length`,
`min_value`, `max_value`, `step_value`, `check_radio_layout`, `default_val`,
`placeholder`, `width`, `is_required`, `conditional_logic`, `quick_editable`,
`search_post_type`, and a repeater's `title_field` and `collapsed` — shapes
the editing screen and not the value.

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
plugin published them on and nowhere else**: `reading_time`, the
`qna`/`jet_qna` FAQ and the FAQ's own heading (meta key `title`, exposed as
`jet_faq_title`) on `post`, `author_description`, `author_image` and
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

The registered context is also what decides whether a read is gated by the
per-key metadata capability — see "Metadata capabilities" above.

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
  key name — unless that name is one the target's controller already
  defines, in which case see "Names core already owns" below.
- For `object === 'taxonomy'`: same on each REST-enabled taxonomy.
- A target of a **discovered** field that the site does not expose is
  recorded in `skipped()`, with the two answers told apart: no post type or
  taxonomy of that name is registered here, or one is and it was registered
  with `show_in_rest` off. Until this the loop simply moved on, so a site
  whose own meta box produced nothing at the endpoint found nothing in the
  diagnostics either. The **compatibility** layer stays quiet about the same
  thing on purpose: it offers names on targets most sites simply do not have,
  and the absence of an `authors` taxonomy nobody asked for is not a gap
  worth reporting.
- `get_callback` normalizes stored values: repeater values that arrive as a
  JSON string or a PHP-serialized string are decoded (the old plugin's
  behaviour); non-arrays return `[]` for repeaters, `''` for scalars.
- `update_callback` checks the per-key metadata capability (below), then
  validates against the schema, sanitizes, writes. Returns `WP_Error` (400)
  on shape violations.
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

### Metadata capabilities

Core's post and term controllers establish that the request may edit the
**object** before a field callback runs. They do **not** apply the **per-key**
metadata capability to an arbitrary field registered with
`register_rest_field()` — `WP_REST_Meta_Fields` does that for registered meta,
and this is not registered meta. So a discovered field whose key is protected,
or one on a site that installs an `auth_post_meta_*` / `auth_term_meta_*`
restriction, was readable and writable through this module by anyone who may
edit the containing object. WordPress's own meta endpoints refuse exactly
that.

Both callbacks now ask the capability WordPress uses for metadata rather than
deriving one: `current_user_can( 'edit_post_meta', $post_id, $key )` for
posts and `current_user_can( 'edit_term_meta', $term_id, $key )` for terms.
`map_meta_cap()` resolves those in three steps — the containing object's own
edit capability, a flat refusal for a protected key, then the site's
`auth_{$type}_meta_{$key}` filter — and only the first has been settled by the
controller. There is no read-side metadata capability in core, and
`edit_*_meta` is the only per-key one there is; it is the right gate here
because every gated field is `edit` context only, so its read is already an
edit-level read.

- A refused **write** is a `WP_Error` in the style of the other error paths,
  with `rest_authorization_required_code()` as the status — 401 where there is
  no user, 403 where there is one who may not. It is refused before the value
  is sanitized.
- A refused **read** returns `normalize_read( $descriptor, null )`: the
  schema-honest empty for that field's own type — `0` for a number, `[]` for
  a repeater, `''` for a string — so a refusal looks like a field with nothing
  in it rather than a field of a different shape.

**The public legacy reads survive it.** A small set of keys is published
anonymously on purpose, on exactly the targets the replaced plugin published
them on, and a capability check run with no user would silently un-publish
every one of them. The read gate therefore follows the **context the field was
really registered with**, not a second list: a field readable in `view` is one
this site publishes to anyone — the legacy keys, and anything a site has opted
in with `dpt_rb_field_context` — and its read is not gated at all. Everything
else is `edit` only and its read is gated by the same capability its write is.
That is what keeps "this reader is not allowed" and "there is no reader" two
different answers: the first returns the empty, the second still returns a
published key's value.

Writes are never in that set. Publishing a key anonymously says nothing about
who may change it.

**What the read gate costs.** `get_callback` runs for every item in a
collection response, before core filters that response by context, so a
gated field asks `current_user_can()` once per item per field —
`map_meta_cap()` resolving `edit_post` against a post the query has already
put in the cache. Core's own meta fields avoid this by settling the question
at `register_meta()` time, which is not available to a field registered with
`register_rest_field()`. The published keys, which are the ones an anonymous
collection request actually wants, cost nothing: they are not gated. This is
accepted rather than optimized; a per-item capability call on cached objects
is the price of not handing a restricted key to whoever asks.

**Keys WordPress will not carry are refused at registration**, not merely at
read and write, and each is refused with its own reason: a diagnostic naming
the wrong one sends whoever reads it looking for a problem the site does not
have. There are three, and there are only three, because a meta key is almost
unconstrained — `update_metadata()` puts it in a text column with no character
rule of any kind, which is why discovery hands the key over verbatim.

- **Protected.** `map_meta_cap()` denies the per-key capability for one to
  every user there is, administrators included, so a field on it could only
  ever read empty and refuse every write. Asked of `is_protected_meta()`
  rather than tested for a leading underscore: core strips everything outside
  printable ASCII and the Unicode letters before it looks, so a name written
  in Hebrew ahead of an underscore really is protected, and the answer is
  filterable besides — Rank Math protects every `rank_math_*` key that way.
- **The key `"0"`.** `update_metadata()` opens with `! $meta_key`, and PHP
  reads that string as empty, so no write can ever land.
- **Longer than 255 characters.** `meta_key` is `varchar(255)`, so a longer
  key is a different key by the time it reaches storage — the same silent
  substitution `sanitize_key()` was making. Counted in **characters**, which
  is what that column counts on a utf8mb4 table: 200 Hebrew characters is 400
  bytes and fits, and counting bytes would refuse a field for a limit
  WordPress does not have.

Refused in `DPT_RB_Fields` rather than in discovery, because discovery
describes what JetEngine defines (which the info endpoint reports) rather than
what this module may expose, and because `is_protected_meta()` takes the
object type, which is known here and not there. The per-user half of the
protection question — the `auth_*_meta_*` filters — cannot be answered at
registration at all and stays in the callbacks. Every refusal is recorded in
`skipped()`, naming the key.

### Names core already owns

`register_rest_field()` does not add a property beside an existing one. For a
name the target controller already defines — `title`, `content`, `id`,
`status`, `meta`, `slug`, `date`, `excerpt`, `author`, `link` and the rest —
it **replaces** that property's schema and its response value. A JetEngine
field called `title` on posts therefore makes `/wp/v2/posts/{id}` return the
meta string where core's title object belongs, lets the edit-only context
above strip the real title out of `view` responses entirely, and lands a
write in two places at once. That is not one field degraded; it is the site's
REST API broken for that post type, for every consumer, the block editor
included.

It is not hypothetical: the replaced plugin registered `jet_faq_title` as a
REST field whose callbacks read and write the meta key **`title`**. It
renamed the field precisely because exposing it as `title` would collide, so
a JetEngine field with that meta key exists on the sites this module is for
and discovery finds it under its real name.

So a discovered name is checked against the reserved set for its target
before registration, and **never registered over one**.

**How the reserved set is decided.** Per target, and only what that target's
own controller defines — three sources, because the answer is genuinely three
answers:

- a written list per object kind: `WP_REST_Posts_Controller`, which is every
  post type's controller, and `WP_REST_Terms_Controller`, whose nine
  properties are the same on every taxonomy;
- a written list per named post type, for the gates core makes on the post
  type's *name* rather than on a support or a registry flag: `sticky`, which
  the posts controller adds for `post` alone, and the fifteen properties
  `WP_REST_Attachments_Controller` adds on top of its parent's schema, which
  reach `attachment` and nothing else;
- the part that genuinely differs per site: a post type's controller turns
  every REST-enabled taxonomy attached to it into a property named by that
  taxonomy's `rest_base`, read from the taxonomy registry with
  `get_object_taxonomies( $target, 'objects' )` — an in-memory read with no
  query behind it, done once per target per request and kept. `categories`
  and `tags` are that read's answer on core's `post`, not a written pair, and
  are not duplicated into the post list.

One set applied to every target would be a rename list for collisions that
cannot happen, and a needless rename costs the module its whole promise: a
JetEngine field called `description` on a **page** collides with nothing on
`/wp/v2/pages`, so renaming it to `jet_description` hides a field the site
asked to see under the name the site gave it, and an automation looking for
`description` does not find it. Inside each set the list is still the
controller's *surface* rather than the schema one target would really build —
most post properties are switched on by a post type support, a runtime fact
any plugin may change after the list is consulted, so over-reserving there is
the cheap side (one renamed field, recorded in `skipped()`) and
under-reserving is the expensive one (core's own property handed a meta
value). The same trade leaves `password` reserved on `attachment`, where the
attachments controller in fact unsets it.

A written list rather than a question put to the controller, deliberately.
`WP_REST_Controller::add_additional_fields_schema()` folds every field
registered so far into the schema a controller hands back, so during
`rest_api_init` "core's own properties" is an order-dependent answer that
would include this module's own registrations. Asking also costs a controller
instantiation and a full schema build per target, inside that hook, running
whatever third-party code has filtered `rest_{$type}_item_schema` —
re-entrancy this module cannot bound. And `WP_Post_Type::get_rest_controller()`
is WP 5.9 and later and answers `null` for a type whose
`rest_controller_class` is not a `WP_REST_Controller`. Against that, the list
is core's published API surface: renaming one of these properties would break
`/wp/v2` for everyone, which is not a change core makes. The residual gap is
a custom controller that adds properties of its own, and fields another
plugin registers later in the hook; both are recorded here as known and
neither is reachable by any cheaper means.

**The aliasing rule.** A field the site defined does not vanish because its
name is taken. A colliding name is renamed, predictably:

- a triple with a legacy name gets that name — `post/post/title` is
  `jet_faq_title`, which is what the replaced plugin published and what
  automations written against it send today;
- **anything else gets its own name with a `jet_` prefix** — `excerpt` on
  posts becomes `jet_excerpt`, `description` on a taxonomy becomes
  `jet_description`. Same prefix the legacy names use, and no core property
  begins with it.

Either way the absence of the real name is recorded in `skipped()`, naming
the field, the target and the name it answers to instead, so the info
endpoint can explain a name an automation asked for and did not get. If the
alias is not free either — reserved as well, defined by the site, or already
registered — the field is left off entirely rather than laid over somebody
else's name, and that is recorded too. A renamed field is reported in
`compat()`: the name is owed to this rule, not to the definition.

The check against "the site defines this name here" is made against the set
of meta keys discovery found, frozen before anything is registered, so the
answer does not depend on which of two definitions discovery reached first.

**Compatibility layer**, registered after discovery, each item only when
discovery did not already expose the name (and a target it declines is
recorded in `skipped()`, for a consumer that has been reading that name since
the replaced plugin):
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
  The alias is withheld from any target where the site defines a field of its
  own literally named `jet_qna`, decided per target the way the legacy list's
  collision check is: a post type can define both a `qna` repeater and its own
  `jet_qna`, and discovery registering the real one first meant the alias was
  laid straight over it - callbacks and schema replaced by ones pointing at
  the `qna` meta key, so reads and writes under `jet_qna` operated on the
  wrong metadata and the site's own field was unreachable through the API
  entirely. Same family as the URL-field collision above and the same answer:
  the site's own definition wins, compatibility fills a gap and never takes a
  name that is already someone's. The withheld target is recorded in
  `skipped()`, because an automation that expects `jet_qna` to mean the FAQ
  needs to be able to learn that on this site it does not. The check is made
  against what discovery registered, frozen before the compatibility layer
  runs, so "the site defines its own `jet_qna` here" stays a different
  question from "an earlier pass already aliased `jet_qna` here".
- `jet_faq_title` on `post` — the meta key `title`, under the only name it
  can have. Its own name is a core property, so this entry is not "a legacy
  name kept for old automations" but the sole route to a field the site
  really has. Registered unconditionally, as the replaced plugin registered
  it, because the FAQ title may be plain meta rather than a JetEngine field;
  it stands down where the site defines its own `jet_faq_title`, and where
  discovery has already renamed its `title` field into that name.
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
- The caller's settings go through **the rule Elementor applies to its own**:
  `map_deep( $settings, 'wp_kses_post' )` — booleans and nulls handed through
  — whenever `! current_user_can( 'unfiltered_html' )`, which is
  `Document::save()` copied rather than paraphrased. Elementor prints widget
  settings raw (the HTML widget through `print_unescaped_setting()`, the text
  editor by echoing its content), so without this the endpoint was a weaker
  door into the same data than the editor beside it: a Contributor, an Author,
  or an Editor on multisite or under `DISALLOW_UNFILTERED_HTML` could `POST` a
  script tag that Elementor's own editor would have stripped and have it
  printed on the front end. A numeric setting comes back a string, on this
  endpoint as in Elementor's save; that is what matching means.
  Applied to the caller's map and **not** to the stored layout: Elementor
  kses's the whole document because the editor posts the whole document, and
  running it over settings nobody wrote to would rewrite years-old content on
  the strength of one unrelated widget edit — the same rule the field side is
  held to. `get_elements_raw_data()` is deliberately not copied: passing every
  setting through its widget's registered controls is right for a document the
  editor composed and wrong for a settings merge, needing Elementor loaded and
  every widget's plugin active, throwing on data it cannot instantiate, and
  silently deleting settings another plugin put on a widget. The escaping is
  the half that has to match; the shape is not.
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
`post` **and** `page`, `show_in_rest`, `auth_callback` scoped to the post.
When Rank Math is absent: nothing is registered.

`show_in_rest` is always `array( 'schema' => array( 'context' =>
array( 'edit' ), … ) )`, never a bare `true`. A per-key `context` is the only
mechanism there is for keeping a registered meta key off an anonymous read,
and without it these twelve were on one: the `auth_callback` gates writes and
nothing else, because `WP_REST_Meta_Fields::get_value()` performs no
capability check of any kind — unlike `update_meta_value()` and
`delete_meta_value()`, which both do — the `meta` container's schema is
`view, edit`, and `rest_filter_response_by_context()` leaves a property alone
when its schema names no context. So `GET /wp/v2/posts` returned the focus
keyword, the SEO score and the canonical URL of every post and page to
callers with no authentication at all. Rank Math has said what it thinks of
that in its own code: `Common::hide_rank_math_meta()` filters
`is_protected_meta()` to `true` for every `rank_math_*` key, unconditionally.
Edit is where core's own gate is — the posts controller checks the post's
update capability before a field is assembled in that context — and it is the
same answer this module already gives every field it discovers, on the same
reasoning.

**The `auth_callback` and the seed it is there to replace.** `map_meta_cap()`
seeds `$allowed` with `! is_protected_meta( $meta_key, 'post' )` and then runs
every callback hooked to the key. Rank Math's own filter makes that seed
`false` for all twelve — so on the only sites where these keys are registered
at all, a callback that simply ANDed `$allowed` refused every write to every
one of them, to every user there is, administrators included, and the Rank
Math capability was dead.

That seed is exactly what an `auth_callback` on `register_post_meta()` exists
to replace: a key registered with one is a key whose protected default the
registration means to override, and core offers no other way of saying so. A
refusal a **site** made — its own `auth_post_meta_rank_math_*` filter at this
priority or earlier — is a decision that must survive, and it arrives as the
same `false`.

They are told apart by recomputing the seed and comparing it with what
arrived. **Equal** means nothing intervened and the decision is this
callback's, so it answers with its own per-post capability check. **Different**
means somebody decided, and what they decided stands — a denial refused, a
grant honoured. Honouring a grant grants nothing by itself: `map_meta_cap()`
has already put `edit_post`'s mapping into `$caps` before any callback runs,
and a `true` only declines to add the blocking capability on top of it.

One case this cannot see, and the code says so rather than implying otherwise:
where the seed is already `false`, a site filter that also denies is
byte-identical to nothing having spoken, and no rule reading `$allowed` alone
can distinguish them. A site needing a denial to hold on a key Rank Math has
protected hooks **after** priority 10, where its `false` is the last word.

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
   tab chrome, unknown type, broken row) → descriptors + skipped list. Plus a
   fixture holding an array where a string belongs in a field's `name`, in a
   repeater sub-field's `name` and in the list of targets: each is recorded
   with a reason, nothing throws, and the well-formed field in the same meta
   box still arrives - which is the property that matters, one bad row not
   costing the rest. The harness's `sanitize_key` stub throws on an array or
   an object the way PHP 8's `strtolower()` does, so those assertions are not
   vacuous. Plus a Hebrew meta box: a wholly Hebrew field name, an accented
   one and a mixed one all arrive under the names JetEngine stored, a Hebrew
   repeater column keeps its own name, and none of them is reported as a
   field with no name. Plus a meta box with **no** `object_type` and one
   saved under the older spelling `tax`: both are read the way JetEngine
   reads them - `post` and `taxonomy` - with their fields present and
   nothing said about them, while an `object_type` that is not a string is
   still refused with its own sentence and a `user` meta box keeps the
   sentence it always had.
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
   surviving core's gate whole — on the **multiple** variant as well as the
   single one, with a comma-bearing value beside it, and with the shredding a
   union naming `array` first really does asserted directly so the order is
   known to be what protects them. The harness's model of core's union walk
   answers `string` **in its place in the union** rather than only as a
   fallback, the way core's own `$checks` map does; without that, every union
   naming both resolved a plain string to `array` whatever order it was
   written in and no assertion about the order could have failed. A switcher round-trips from a JSON boolean *and*
   from the string form, through a model of the validation core runs before
   any of these sanitizers, and what `normalize_read()` gives back is checked
   against the schema `for_descriptor()` advertised. A checkbox is exercised
   in both of its forms, with discovery carrying `is_array` in each spelling
   JetEngine writes it: each form reads back as the shape storage holds and
   writes back as itself, a value in the form its toggle does not name is
   neither converted nor lost, the ambiguous map of numeric option keys stays
   a map and a list of options called `true` and `false` stays a list, and a
   checkbox column inside a repeater says the same thing about itself as one
   outside it. The harness's model of core's own gate ends `array` at
   `wp_is_numeric_array()`, the way `rest_is_array()` does, so a union naming
   both shapes cannot resolve a map to its list member and the assertions
   about the two are not vacuous. A date field is exercised in both of its
   forms, with discovery carrying `is_timestamp` in each spelling JetEngine
   writes it: a `time` field with the toggle on carries no such setting and a
   date column inside a repeater reads it as `false`, because JetEngine acts
   on it in neither place; the timestamp form is advertised as
   `[integer, string]` and the plain form as the bare string; a stored
   timestamp reads back as an integer and writes back as itself, from the
   number and from the string meta storage hands back; a date string sent to
   a timestamp field is converted the way JetEngine's own save converts it,
   while a value nothing can make a date of is kept rather than blanked; and a
   plain date field whose value happens to look like a number keeps it a
   string, which is why only the timestamp form names `integer`. The harness's
   model of core's union resolution ends `integer` at `rest_is_integer()`
   rather than at `is_int()`, so a stored timestamp resolves to the integer
   member the way it really does and those assertions are not measuring the
   stub.
3. Fields: the per-key metadata capability is enforced on both sides - a key
   an auth filter refuses reads back as its own type's empty (`0` for a
   number, not `''`) and refuses a write with a 403, on posts and on terms,
   leaving storage exactly as it was; a protected key is never registered and
   is named in the diagnostics; and with **no user at all** the published
   legacy keys - the reading time, the FAQ, the FAQ heading, the author bio
   and link - still read, while a discovered field reads as nothing and a
   write is refused with a 401. A field a site opted into public read with
   `dpt_rb_field_context` still reads with no user, because the gate follows
   the registered context rather than a list. The harness models
   `map_meta_cap()`'s three steps and keeps a `WP_Error`'s data, so the
   status codes are assertable at all, and its `is_protected_meta` stub is
   core's own regex rather than a leading-underscore test, so a Hebrew name
   ahead of an underscore is protected in the harness for the reason it is
   protected on a site.
   Also: a Hebrew field registers under its own name and round-trips a write
   and a read on the meta key JetEngine stored, a checkbox beside it keeps its
   Hebrew option keys through read, modify and write, and the three keys
   WordPress will not carry - a protected one, `"0"`, and one over 255
   characters - are each refused with their own reason in the diagnostics,
   while a 200-character Hebrew key, 400 bytes and well inside the column,
   registers.
   Also: a name core's controller already owns is never registered over -
   core's own `title` and `excerpt` on posts and `description` on a taxonomy
   survive registration untouched, the harness modelling
   `add_additional_fields_schema()` so an additional field really does
   replace a core property of the same name and the assertion is not vacuous.
   The site's `title` field is reachable as `jet_faq_title` and writes the
   `title` meta key; a colliding field with no legacy name is reachable under
   the `jet_` prefix; a field named after a taxonomy the site attached to
   posts is renamed too, which is the half of the reserved set no written
   list could hold; each of those is reserved **only on the target whose
   controller defines it** - the same `description` keeps its own name on
   `page`, `sticky` is reserved on `post` and not on `page`, an
   attachment-only property is reserved on `attachment` and not on `post`,
   and a `categories` field is renamed only where that taxonomy is really
   attached; the alias is withheld where the site defines it itself,
   and every one of those is explained in `skipped()`.
   Also: a meta box attached to a taxonomy the site registered as `Authors`
   registers its field on `Authors` and on no lowercased name, and the two
   targets it names that this site does not expose - one absent from the
   registry, one registered with `show_in_rest` off - are each explained with
   their own reason, while the compatibility layer says nothing about an
   `authors` taxonomy the site does not have. The harness keeps "registered
   but off the REST API" as a state of its own, so those two reasons are
   distinguishable at all.
   Also: registration targets (REST-enabled only), read normalization
   (array / JSON string / serialized string / garbage), alias registered only
   when missing - including the target-by-target case, where a post type
   defining both `qna` and its own `jet_qna` keeps its own field bound to the
   `jet_qna` meta key, with the alias absent, a reason recorded and nothing
   claimed in `compat()`, while a sibling target with no such field is aliased
   as usual - compat set skips discovered names. A value carrying literal
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
   Also: a caller without `unfiltered_html` cannot store a script tag or an
   event handler, at the top level of a setting or nested inside one, while a
   boolean and a null are handed through and the markup kses allows is kept;
   a caller with the capability stores exactly what Elementor would let them
   store; and a stored setting nobody wrote to comes out of an unrelated
   widget edit byte for byte. The harness's `wp_kses_post` really strips -
   dropping a `<script>` with its content, removing a disallowed element and
   keeping its text, and taking an `on*` handler off one that is allowed -
   and `map_deep` is core's own walk, so none of that is vacuous.
5. Rank Math: the twelve keys on posts and pages, their types checked against
   Rank Math's own reads, and none of the twelve on an unauthenticated read
   while an edit-context read still returns every one with its value. The auth
   callback in all four of its cases, with Rank Math's `is_protected_meta`
   filter really hooked so the seed is the one a site produces: protected by
   Rank Math and nothing else hooked, allowed for a user who may edit the post
   and refused for one who may not; a denial another filter made refused even
   for a user who may edit; and a grant another filter made where the seed
   would have refused surviving intact.
   The harness assembles the `meta` object the way `WP_REST_Meta_Fields` does
   (no capability check on the read, per-key schema merged over the defaults)
   and filters it the way `rest_filter_response_by_context()` does (a property
   with no context of its own survives), so the leak is reproducible in the
   harness before it is fixed.
6. Info: shape, skipped surfaced, rank_math flag.
7. Stand-down: with the old plugin's function defined (declared in a guarded
   block), init registers nothing and the reason is non-empty.

## Global constraints

- phpcs full-run sniffs at zero (EscapeOutput, ValidatedSanitizedInput,
  NonceVerification, I18n) as in the rest of the repo.
- Hebrew catalog: new strings in `.po`/`.pot`/`.l10n.php` + `msgfmt`; key
  sets identical; count check green.
- Module ships **disabled** like all modules; registry entry + readme.txt
  section; version 1.27.0.
