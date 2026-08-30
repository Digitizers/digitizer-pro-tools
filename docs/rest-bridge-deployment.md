# Deploying REST Bridge to a site

REST Bridge replaces the standalone `digitizer-api-extensions` plugin. Doing
that on a site means deactivating something that works and switching on a
module whose output nobody has seen — the fields are discovered from the
site's own JetEngine definitions, so they are not knowable until something
reads them.

Since 1.32.0 they can be read first. This is the order to do it in.

## One site first

Not all of them, then find out. The whole value of the preview is knowing
before, and deploying everywhere at once gives exactly that up.

## 1. Update to 1.32.0 or later

Update Digitizer Pro Tools through the updates screen. **Leave
`digitizer-api-extensions` active.**

## 2. Run the preview

**Digitizer Pro Tools → REST Bridge Preview → Run the preview**

The screen works while the old plugin is still active, which is the point of
it. It changes nothing: it runs the module's discovery and registration with
one call suppressed — `register_rest_field()` — and reports what it found.

Four sections:

- **Fields** — every field that would be exposed, with its target and type. A
  name marked `(compatibility name)` is one the module assigns, not one the
  site's own definition gave it.
- **Rank Math** — the twelve keys, if Rank Math is active. They are registered
  as post meta rather than REST fields, so they are deliberately absent from
  the Fields table.
- **Not exposed** — what was skipped and why. **Read this one.** The reasons
  are in English and untranslated on purpose: they quote core's own names and
  are meant for whoever is doing the deployment.
- **Routes** — the endpoints.

## 3. Compare — the step everything else exists for

Compare the **fields** the screen reports against the fields the site exposes
today through the old plugin.

| What you see | What it means | What to do |
|---|---|---|
| The same | Safe | Go to step 4 |
| The module exposes **more** | Usually right — it discovers fields the old plugin never knew about, and covers pages as well as posts for Rank Math | Read the additions and check none of them is an internal field that should not leave the site |
| The module exposes **less** | **Stop.** The reason is in Not exposed | Do not deactivate the old plugin until you understand it |

The routes are **not** compared this way, because they are known to differ and
the difference is deliberate:

| Old plugin | Module |
|---|---|
| `digitizer/v1/faq/bulk` | **Dropped.** No consumer in any of our code |
| `digitizer/v1/faq/info` | Replaced by `digitizer/v1/info`, which reports far more |
| `digitizer/v1/elementor/{post_id}` | Kept, GET and POST |

Reading the Routes section as a like-for-like comparison would land every
deployment in the "exposes less" row above, on a difference that was designed.
If something outside our code calls `faq/bulk`, that is the one thing to find
before deactivating — and it is worth grepping for, not assuming.

## 4. Swap

Only once step 3 is clean, and in this order:

1. Switch on **REST Bridge** on the Modules screen. It does nothing yet: the
   module stands down while the old plugin is present, and says so on the card.
2. Deactivate `digitizer-api-extensions`.

That order leaves no gap. The reverse order does: the module ships disabled, so
between deactivating the old plugin and saving the switch the site has neither,
and requests arriving in that window get 404s or responses with fields missing.
`boot()` re-asks whether the old plugin is active on every REST request rather
than trusting what `init()` decided, so the module takes over on the first
request after the deactivation.

## 5. Verify

- `GET /wp-json/digitizer/v1/info` should report what the preview showed.
  The route requires `edit_posts`, so authenticate — an application password,
  or a REST nonce from a logged-in browser. Called anonymously it answers 401,
  and that looks exactly like a deployment that failed.
- Exercise the consumer that actually uses these fields before moving to the
  next site

## If something breaks

Reactivate `digitizer-api-extensions`. The module stands down on its own while
that plugin is present — `legacy_plugin_active()` checks for
`digitizer_elementor_build_tree` — so there is nothing to switch off. The check
runs again in `boot()` and not only in `init()`, because a REST request takes a
different path through the plugin than an admin page load.
