# Light/dark theme switch: source research

Date: 2026-11-14 (Asia/Jerusalem). Related: [digitizer-tasks#283](https://github.com/Digitizers/digitizer-tasks/issues/283).
DPT baseline: `b7e03fa` (v1.52.1). Research only; module implementation is a separate step.

## Recommendation

Build a small native DPT module for **explicit color-variable mappings**, with an
Elementor global-color adapter. Use SVisciano as a reference for the mapping idea
and WP Dark Mode for preference and switch behavior. Do not import either plugin
wholesale. The inspected SVisciano release has keyboard, multiple-switch, delayed
initialization and RTL defects that affect the intended use. WP Dark Mode handles
a broader automatic-conversion use case and carries a larger frontend dependency.

This recommendation assumes Digitizer controls the site's palette and can replace
fixed widget colors with global colors. It does not promise automatic conversion
of arbitrary existing sites. If that becomes the requirement, trial an existing
automatic-conversion plugin locally before expanding the DPT scope.

## Sources and reproducibility

Downloaded the public release ZIPs and inspected source locally. The versioned
archives have identical SHA-256 values to the corresponding `latest-stable` ZIPs
retrieved for this research. Downloaded headers, rather than cached search results,
establish the versions below.

| Plugin | Version | Public source archive | SHA-256 |
|---|---|---|---|
| [SVisciano](https://wordpress.org/plugins/svisciano-light-dark-theme-mode/) | 0.3.0 | [ZIP](https://downloads.wordpress.org/plugin/svisciano-light-dark-theme-mode.0.3.0.zip) | `5cba5093a5b627a98a365d3c5da1b74c0938760a0d23092e2bce5276e2c5e097` |
| [WP Dark Mode](https://wordpress.org/plugins/wp-dark-mode/) | 5.3.21 | [ZIP](https://downloads.wordpress.org/plugin/wp-dark-mode.5.3.21.zip) | `cfdbeaf9d4458080fc8e823917d96f55ec4dad0c84d9ee58ed5fac5f55f4c98b` |

Paths and line numbers below are relative to the relevant unpacked plugin root.
No third-party implementation is copied into this repository.

## Comparison from source

| Area | SVisciano 0.3.0 | WP Dark Mode 5.3.21 |
|---|---|---|
| Colors | Injects mapped custom properties on `html.<mode>` and `html.<mode> body`. | Calls bundled `DarkModeAuto.enable()`/`disable()`; scans and rewrites styles through its automatic engine. |
| Preference | `sv-light`, `sv-dark`, `sv-auto`; cookie retained for 365 days; live `matchMedia` listener. | Saved light/dark cookie wins over configured device mode; live device listener stops overriding an explicit choice. |
| Multiple switches | Fixed duplicate IDs; `getElementById` binds and updates only the first instance. | Queries all `.wp-dark-mode-switch` instances, delegates clicks and broadcasts a change event. |
| Keyboard | Default template has nonfocusable divs; alternate template has focusable roles but no Enter/Space handler. | Delegated Enter/Space handler, roles/labels and an announcement region. This is source inspection, not an accessibility certification. |
| Initialization | Footer script only registers `DOMContentLoaded`; nothing initializes when it runs after that event. | App initialization starts through `Base.init()`; color engine can wait for `body` with a `MutationObserver`. Actual loading behavior also depends on performance options. |
| Scope | CSS variable mapping, scanner and two switch templates. | Automatic conversion, switch designs, builder adapters, media handling, analytics and admin mode. |

SVisciano source anchors:

- `svisciano-light-dark-theme-mode.php:82-108`: global frontend enqueue, footer loading and localized settings.
- `assets/js/svis-light-dark.js:1-11, 47-81`: first-instance lookup, initialization, cookie choice and device changes.
- `assets/js/svis-light-dark.js:27-44`: override selectors and CSS generation.
- `templates/toggles/theme-toggle-1.php:5-21`, `theme-toggle-2.php:5-44`: control markup.
- `assets/css/theme-toggle-1.css:36-61`: absolute ball positioning and physical left-to-right translation, without `pointer-events:none`.
- `assets/js/settings-page.js:210-260`: scanner reads top-level accessible stylesheet rules. It skips rules without `style`, so nested media/layer rules are not recursively scanned; inaccessible stylesheets are silently skipped. The displayed value is the first declaration found, not the computed cascade winner. These scanner limitations were inspected, not exercised in the browser fixture.

WP Dark Mode source anchors:

- `src/common/WPDarkMode.js:59-94`, `store.js:6-25`, `app.js:107-185`: event dispatch, cookie persistence and preference precedence.
- `src/common/switch.js:267-328, 334-405`: delegated click/keyboard handling and updating all switches. The updater removes `aria-pressed`/`aria-checked` and uses an announcement region; use explicit selected-state semantics in DPT instead of copying that behavior.
- `src/common/Base.js:65-70`, `Color.js:7-23, 40-66`: immediate initialization and body/color handling.
- `includes/classes/class-assets.php:64-89`: stylesheet and script enqueues.
- `src/common/Accessibility.js:98-140`: visitor analytics path gated by `analytics_enabled`. No analytics is needed for the proposed module.

### Frontend file size, not a performance benchmark

| Plugin assets | Raw bytes |
|---|---:|
| SVisciano `assets/js/svis-light-dark.min.js` | 1,691 |
| SVisciano default `assets/css/theme-toggle-1.min.css` | 1,275 |
| WP Dark Mode `assets/js/dark-mode.js` | 122,218 |
| WP Dark Mode `assets/js/app.min.js` | 74,516 |
| WP Dark Mode `assets/css/app.min.css` | 161,054 |

These are on-disk file sizes. They exclude localized configuration, generated
styles and optional assets, and do not account for HTTP compression or caching.
They establish implementation size, not a load-time or CPU ranking. No Lighthouse
or real-site speed measurement was run.

### License metadata

SVisciano's main header and readme declare GPLv2 or later. WP Dark Mode's header
and readme also declare GPLv2 or later, while its `composer.json` declares
`GPL-3.0-or-later`. That metadata discrepancy and any bundled asset notices need
to be reconciled if copying code is later proposed. The recommendation here is
an original DPT implementation; this research does not establish licensing terms
for redistributing every bundled asset.

## Browser observations

Ran the **shipped minified SVisciano frontend script and both shipped templates and
stylesheets**, with only PHP guards removed from the templates. Settings were
injected in the same global variable that the plugin localizes. Playwright served
a synthetic origin from memory in a fresh browser context; no client site or
WordPress installation was accessed.

Browser: Chromium `157.0.8103.51`. Final run confirmed 15/15 recorded observations;
this includes reproducing defects, not 15 product acceptance passes. Raw output:
[`theme-switch/svisciano-results.json`](theme-switch/svisciano-results.json).

| Scenario | Observed |
|---|---|
| Body-level Elementor-style token | Mapped text changed from `rgb(34,34,34)` to `rgb(238,238,238)`. |
| Descendant declares the same variable | Child stayed `rgb(204,0,0)`: its own declaration wins over inheritance. |
| Fixed widget color | Stayed black: variable mapping cannot recolor a literal `#000000`. |
| First switch selects dark | Page and first switch changed; second switch did not synchronize. |
| Second switch selects dark | No change. |
| Reload after manual dark selection | Choice persisted. |
| Return to light | Base body colors restored. |
| Default template keyboard support | All three controls were unlabeled, nonfocusable divs. |
| Glass Pill keyboard support | Focus worked; Enter and Space did not activate dark mode. |
| Device preference changes in auto mode | Theme followed the device. |
| Explicit light choice then device changes | Explicit choice was preserved. |
| Dark base, alternate light mapping | Selecting light applied the alternate light palette. |
| JS injected after `DOMContentLoaded` | No initialization or root theme class. |
| RTL, default template, dark then light | After the ball's transition finished, it intercepted clicks on the light control. |
| Held `DOMContentLoaded`, dark default | Visible white base background preceded dark initialization; after release it became `rgb(17,17,17)`. This is a controlled reproduction of flash risk, not a timing benchmark. |
| Native `color-scheme` | Stayed `normal`, so browser control theming was not opted into by this script. |

Some rows come from the same recorded observation. No uncaught browser errors
occurred in the final run. The first exploratory RTL run timed out on a click;
the probe now explicitly waits for the ball transition and records the interception.
An intermediate version clicked before the transition ended and did not reproduce
it; that timing error was corrected before saving the final results.

Reproduce in an environment with Playwright and Chromium already installed:

```bash
curl --fail --location --silent --show-error \
  https://downloads.wordpress.org/plugin/svisciano-light-dark-theme-mode.0.3.0.zip \
  -o /private/tmp/dpt-svisciano-0.3.0.zip
shasum -a 256 /private/tmp/dpt-svisciano-0.3.0.zip
unzip -q /private/tmp/dpt-svisciano-0.3.0.zip -d /private/tmp/dpt-theme-research
node docs/research/theme-switch/svisciano-probe.cjs \
  /private/tmp/dpt-theme-research/svisciano-light-dark-theme-mode
```

The same exported probe function was executed through the Playwright connector
for this research. Its CLI expects `require('playwright')` to resolve in the
caller's environment; the DPT repository gains no Node dependency.

## Proposed DPT boundary

1. A `theme_switch` module, disabled by default. Existing `DPT_Module` registration
   and enabled-only `init()` fit this directly (`includes/class-dpt-module.php`,
   `includes/class-dpt-plugin.php:199-213`). Keep constructors free of frontend hooks.
2. Let the administrator choose the base palette (light or dark), the first-visit
   mode and alternate values for named CSS color variables. Discover Elementor's
   active Kit global colors when Elementor is available; retain manual mapping
   without Elementor. Read Kit data without changing saved page or Kit colors.
   Elementor documents [Kit-backed global references](https://developers.elementor.com/docs/data-structure/global-styles/)
   and the [global color control API](https://developers.elementor.com/docs/editor-controls/global-style/).
3. Preserve the site's base styling. Apply the alternate mapping at the required
   root/body scopes. Explain that fixed colors and descendant overrides need
   design changes. A single global token used for both text and background may
   need to be split into semantic tokens before it can have a useful dark value.
4. Use one global preference state: explicit light/dark choice wins; auto follows
   the device and can be selected again. Store only a validated preference, tolerate
   unavailable storage, and keep served HTML independent of visitor preference
   so full-page caches can be shared.
5. A small synchronous head initializer resolves preference before content renders;
   CSS has to be available at that point too. The later controller must handle
   both loading and already-loaded documents. Set native `color-scheme` to the
   resolved mode. Preserve base styling if JavaScript fails.
6. Start with `[dpt_theme_switch]`: three native buttons for light/dark/system,
   translated labels, explicit selection state, RTL-safe layout and synchronized
   instances. A shortcode works in Elementor's existing Shortcode widget; a
   dedicated widget can follow if it adds useful controls. Event delegation must
   also handle switches added after page load.
7. Account for optimization explicitly. The Cookie Banner module already shows
   narrow WP Rocket exclusions for head initialization, delayed JS and retained
   styles (`modules/cookie-banner/class-dpt-cb-frontend.php:22-38`). Verify the new
   module's own handles and rules locally instead of copying broad exclusions.
   Purge affected cached pages when mappings or module state change.

The first version should cover palette switching and its controls. Automatic
whole-page recoloring, admin dark mode, image analysis, analytics and scheduling
would materially expand maintenance. Logos and image backgrounds need explicit
design treatment; palette mapping alone will not create alternate artwork.

## Limits and checks before implementation ships

WP Dark Mode was inspected in source only. Neither plugin was installed in a
WordPress instance. The browser fixture approximates an Elementor body selector;
it is not an Elementor integration test. Scanner UI, alternate logos, WooCommerce,
cookie-banner interaction, real WP Rocket optimization, Safari/Firefox and screen
readers were not exercised. No production changes were made.

Before shipping a module, validate a disposable WordPress + Hello Elementor fixture
with an actual active Kit, fixed/local/global widget colors, two header/footer
switches and a dynamically inserted switch. Check first paint, keyboard selection,
RTL, refresh/navigation persistence, blocked storage, no-JS fallback and optimization.
Manual contrast review remains necessary in both palettes. This research changes
documentation and a probe only, so the PHP suite and release build were not run.
