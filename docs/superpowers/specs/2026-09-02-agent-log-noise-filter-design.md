# Agent Log: letting a site silence a chatty writer

**Date:** 2026-09-02
**Status:** proposed, not approved
**Applies to:** the `agent-log` module in this repository and the standalone
plugin `Digitizers/digitizer-ai-agent-log`, which carry the same code.

## The report

On a staging site, every WP-CLI run produced rows like:

    cli  -  updated elementor_library Default Kit  _elementor_page_settings

Three of them in a twelve-row log. Nobody asked for those changes, and they
crowd out the rows somebody did ask about.

The first suggestion was to skip `elementor_library` saves "with no field
diff".

## Why that suggestion is wrong

**There is no no-op to suppress.** `update_metadata()`
(`wp-includes/meta.php`, in the WordPress 7.1 source) already refuses an
identical write before it reaches the database:

```php
$old_value = get_metadata_raw( $meta_type, $object_id, $meta_key );
if ( is_countable( $old_value ) && count( $old_value ) === 1 ) {
    if ( $old_value[0] === $meta_value ) {
        return false;
    }
}
```

`updated_post_meta` does not fire on that path, so a comparison in
`on_post_meta()` would be dead code.

It follows that the Default Kit rows are **true**. Core compares with `===`,
which for an array is recursive and sensitive to key order and to types, and
core did perform the update — so what Elementor stored genuinely differs from
what was there. The module is reporting a real change.

The module also cannot answer this question after the fact even if it wanted
to: it deliberately stores field *names* and never values, so it holds nothing
to diff against.

**And the type is not the criterion.** Filtering `elementor_library` would fix
one plugin's habit, leave the same pattern open for every other plugin that
writes settings on load, and hide a genuine edit to an Elementor template —
which is exactly the kind of change somebody would want to see.

So the real problem is not correctness. It is that a true change can be
uninteresting, and only the site knows which.

## Proposal

One filter, applied to every entry as it is buffered:

```php
/**
 * Whether one change is worth recording.
 *
 * @param bool   $record  Whether to record it. Default true.
 * @param array  $entry   type, subtype, id, action, name, fields, channel.
 */
$record = apply_filters( 'digitizer_ai_agent_log_record', true, $entry );
```

Placed in `Buffer::record()`, before the entry is stored, so one gate covers
every hook rather than each callback growing its own.

A site silences its own noise:

```php
add_filter( 'digitizer_ai_agent_log_record', function ( $record, $entry ) {
    if ( 'elementor_library' === $entry['subtype'] && 'updated' === $entry['action'] ) {
        return false;
    }
    return $record;
}, 10, 2 );
```

**Nothing is filtered by default.** A log that quietly drops entries the
operator never asked it to drop is worse than a noisy one: the value of this
plugin is that what is not in it did not happen over an API. Every default
exclusion erodes that, and the module already refuses to guess elsewhere — it
leaves the application name empty rather than inferring one.

This follows the extension points the module already has:
`digitizer_ai_agent_log_watched_options`, `..._max_age_days`, `..._max_rows`.

## What this does not do

- It does not add a settings screen. The audience is developers, and a filter
  is where they already look.
- It does not ship a default exclusion list, for the reason above.
- It does not change what is recorded for anyone who adds no filter.

## Testing

- An entry is recorded when no filter is attached (the default path is not
  changed by the filter's existence).
- A filter returning false drops that entry and only that entry.
- The filter receives the channel, which is only known at flush time — so
  either the filter runs at flush rather than at record time, or the channel
  is absent from `$entry`. **This is the one open design question**: the
  buffer records during the request and the channel is resolved at shutdown.
  Running the gate at flush is the honest choice, and moves the filter call
  from `Buffer::record()` to `Hooks::flush()`.
- A filter returning a non-boolean is cast, not trusted.

## Open questions

1. Record-time or flush-time? Flush-time gives the filter the channel and the
   application name, which is most of what a rule would want to match on. It
   also means the entry occupies memory during the request either way. Leaning
   flush-time.
2. Does the same filter belong in the DPT module, or only in the standalone?
   They carry the same code, so both — but the module stands down whenever the
   standalone is active, so in practice only one of them ever runs.
