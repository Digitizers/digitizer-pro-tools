# WordPress.org submission branch

This branch is the build submitted to the WordPress.org plugin directory. It is
`main` minus the GitHub self-updater, which directory guideline 8 forbids:

> Plugins may not send updates or install code from outside of WordPress.org.

## What differs from `main`

| | `main` (private distribution) | `wp-org` (this branch) |
|---|---|---|
| `includes/class-dpt-updater.php` | present | removed |
| `vendor/plugin-update-checker/` | bundled | removed |
| Updater wiring in `digitizer-pro-tools.php` | `DPT_Updater::init()` | replaced by a comment |
| readme "External services" | Resend + GitHub + Embed | Resend + Embed |
| Updates reach sites via | tagged GitHub Releases | WordPress.org |

Nothing else diverges. Every feature, module and fix is identical.

## Keeping the branches in sync

After each release on `main`:

    git checkout wp-org
    git merge main            # resolve the two known conflicts below
    # keep THIS branch's versions of:
    #   digitizer-pro-tools.php  (no updater require/init)
    #   readme.txt               (no GitHub external-service entry)
    # and make sure vendor/ + includes/class-dpt-updater.php stay deleted

## Still to do before an actual submission

- `Contributors:` in readme.txt must be a real WordPress.org username.
- Screenshots for the directory listing (`assets/screenshot-1.png` … in SVN, not in the plugin zip).
- Run the official Plugin Check plugin against a zip of this branch.
