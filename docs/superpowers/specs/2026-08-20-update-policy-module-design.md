# Update Policy module — design

Date: 2026-08-20
Status: approved in chat
Target version: 1.24.0

## Problem

On 2026-08-20 every Digitizer site running WP Rocket broke at once. The
plugin had not changed; WordPress had. WordPress 7.1 turned a latent type error
in WP Rocket's Cloudflare integration into a fatal:

```
Uncaught TypeError: substr(): Argument #1 ($string) must be of type string, int given
inc/ThirdParty/Plugins/CDN/Cloudflare.php:562
```

WP Media shipped the fix in WP Rocket 3.23.2.2 within days. A site that had
taken 7.1 a month later would never have seen it.

The failure was not a caching failure and not a plugin failure. It was a
timing failure: client sites took a major WordPress release on the day it
appeared, before the ecosystem around them had caught up. Nothing on those
sites expressed an opinion about when a major release should be installed,
because WordPress has no such setting — it has a switch for *automatic* major
updates, and nothing at all for the button a human presses.

## What this module does

Holds a major WordPress release back for a fixed window after the site first
sees it, then gets out of the way.

- **Minor and security releases are untouched.** They are what keeps a site
  alive, WordPress installs them automatically, and that stays exactly as it
  is. This module never looks at them.
- **A held major is removed from the update offer** for the length of the
  window, so it does not appear on Dashboard → Updates and cannot be installed
  by pressing a button that is not there.
- **The hold is visible, dated and overridable.** An admin notice on the
  updates screen says which version is held, when the site first saw it, when
  it unlocks, and offers a control that releases it immediately.
- **When the window passes, the offer reappears as WordPress's own.** Nothing
  is installed automatically. Major updates stay a deliberate act, run through
  Aura where SiteAgent's safe-update engine and per-plugin rollback live.

## Decisions taken

Settled with Ben before design:

| Question | Decision |
|---|---|
| Hold until someone decides, or hold for a fixed window? | **Fixed window.** A decision that must be made per site, forever, is one that rots; a window ends by itself. |
| What happens when the window passes? | **The offer reappears, and nothing more.** Automatic major updates across a fleet of client sites are not a default this plugin will set. |
| Scope | **Core only.** The same first-seen mechanism would extend to plugins later; doing both at once doubles the surface for no extra proof. |

### Why removing the offer, rather than a warning

`allow_major_auto_core_updates` governs the unattended path only. It does not
stop an administrator — or a host's dashboard — pressing "Update now", which is
the more likely way a client site reached 7.1 on release day. A policy that
only speaks to automation would have changed nothing about what happened.

Removing the offer during the window is therefore the mechanism, and the notice
is what keeps that honest: a hold nobody can see is indistinguishable from a
site whose updates are broken.

## The window is counted from first sight, not from release

WordPress's update transient carries no release date, and asking an external
service for one would put a network dependency in the middle of an update
check for no gain.

Instead, the first time the site sees a major offered, the module records the
version and the timestamp. The hold runs from that stamp. On a site that checks
for updates at all — which is every site, twice a day — first sight is within
twelve hours of release, and the difference between "30 days from release" and
"30 days from first sight" is under half a day.

Two consequences, both stated rather than hidden:

- A site that enables this module for the first time while a major is already
  offered will hold that major for the full window, even if the release is
  months old. The stamp does not exist yet, so the module cannot know it is
  late. The override control is the answer, and the notice says so.
- Restoring a site from a backup that predates the stamp restarts the window.
  The cost of getting this wrong in that direction is a delayed update, which
  is the safe direction.

## Components

Four units, each independently testable.

**`DPT_UP_Version`** — pure version reasoning, no WordPress.
`is_major( $installed, $offered )` compares the first two segments: 7.0.4 → 7.1
is major, 7.0.4 → 7.0.5 is not. `held_until( $stamp, $days )` and
`is_held( $stamp, $days, $now )` answer the window. Every date in this module
is a Unix timestamp; nothing formats a date except the notice.

**`DPT_UP_Offers`** — pure transient reasoning.
`filter( $updates, $installed, $stamps, $released, $days, $now )` takes the
array of offers WordPress holds and returns it with held majors removed. Also
`majors( $updates, $installed )`, which is what the stamping pass and the
notice both read. It never mutates its input.

**`DPT_UP_Policy`** — the WordPress wiring, and the only place with side
effects. Filters `site_transient_update_core` to apply the hold on read, and
hooks `set_site_transient_update_core` to record first sightings after a check
is stored. Also returns false from `allow_major_auto_core_updates` while a
major is held, so the unattended path agrees with the visible one.

**`DPT_UP_Admin`** — the settings screen (one field: the window in days) and
the notice, including the release control and its nonce.

### Reading and writing are kept apart, deliberately

Applying the hold is a **read** filter and writes nothing. Recording a first
sighting happens on the **action after** WordPress stores an update check.

This is the shape PR #28 arrived at the hard way, over seven review rounds: a
read filter that writes, or that reaches the network, turns every page load
into a side effect; and state persisted into WordPress's own stored value
outlives the module that put it there. Disabling this module must restore
WordPress's behaviour exactly, in the same request, and it does — the stamps
remain in the option, harmless, and are picked up again if it is re-enabled.

## Storage

One option, `dpt_update_policy`, holding:

- `hold_days` — integer, default 30.
- `seen` — map of major branch (`7.1`) to the timestamp it was first offered.
- `released` — map of major branch to `1`, written by the override control.

Keyed by branch rather than by full version, because 7.1, 7.1.1 and 7.1.2 are
the same decision: a site that released the hold on 7.1 does not want to be
asked again when 7.1.1 appears eight days later.

The option is registered in `uninstall.php` like every other module's.

## Failure handling

The module's failure mode is that it holds something it should not, so every
uncertain case resolves towards *not* holding:

- An offer whose version cannot be read is left alone.
- An installed version that cannot be read means no comparison, so nothing is
  held.
- A `hold_days` of zero, or a value that is not a positive integer, disables
  the hold rather than holding forever.
- The override is per branch and permanent for that branch.

## Security

- The settings screen and the override both require `update_core`, which is
  the capability WordPress itself requires to install a core update, and which
  core removes on multisite for anyone but a network administrator.
- The override is a nonce-checked POST, not a link with a version in the query
  string.
- Nothing in this module makes a network request, reads user input beyond its
  own two fields, or writes anything outside its own option.

## Multisite

The core update is network-wide, and so is this policy: the option is written
with `update_site_option()`, and changing it requires `manage_network_options`.
A subsite administrator has no `update_core` capability on multisite in the
first place, so nothing changes for them.

The module's own on/off switch is per blog, because that is how every module in
this plugin is stored, and a core update is not a per-blog thing. So on a
network the module **acts only on the main site**, where core updates are
administered. Switching it on for a subsite alone would hold nothing that the
network Updates screen or the update cron could see, while looking like
protection — the switch has to govern the whole network or govern nothing.

What this does not solve: a network administrator who switches the module on
from a subsite's Modules screen gets no warning that it will do nothing there.
Making module enablement network-wide would fix that properly, and it would
change the stored meaning of every other module's switch on every existing
multisite install, which is not a change to make in passing.

## Testing

The stub-harness pattern the repository already uses.

Covered:

- `is_major()` across the shapes WordPress produces: 7.0.4 → 7.1, 7.0 → 7.0.1,
  6.9 → 7.0, equal versions, and unreadable input on either side.
- The window: held on day 1, held on the last day, released on the day after,
  and never held when `hold_days` is 0 or nonsense.
- Offer filtering: a held major disappears while the minor in the same list
  survives; an already-released branch is not filtered; an offer for a version
  older than what is installed is left alone.
- Stamping: a first sighting is recorded once and not moved by later checks,
  which is what makes the window a window rather than a treadmill.
- That the read filter writes nothing at all — asserted against the option,
  not by inspection.
- That disabling the module leaves WordPress's transient byte-for-byte as it
  was.

Not covered, and stated: the actual core upgrade. That is WordPress installing
WordPress, and there is no WordPress in this environment.

## Out of scope

- Holding plugin or theme updates. Same mechanism, later, if it earns its way.
- Automatically installing a major once the window passes.
- Anything about WP Rocket specifically. The plugin that broke is not the
  problem this solves, and a rule about one vendor would not have helped the
  next one.
- Reporting across sites. Aura and SiteAgent already answer "what is installed
  where"; this module decides what one site does, and says so on that site.
