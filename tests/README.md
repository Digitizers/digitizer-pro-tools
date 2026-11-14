# Tests

Plain PHP scripts, no PHPUnit. Each defines the slice of WordPress its subject
touches (see `bootstrap.php`) and requires the real module file.

Run one:

    php tests/onb-manifest-test.php

Run all:

    for f in tests/*-test.php; do php "$f" || exit 1; done

A script exits non-zero if any assertion failed, so the loop stops on the first
failing file.

These files are excluded from the built ZIP by `bin/build-zip.sh`.

Theme Switch also has optional real-browser checks against a disposable local
WordPress/Elementor installation. Setup and limits are documented in
[`docs/theme-switch-validation.md`](../docs/theme-switch-validation.md). They do
not add dependencies to the PHP test suite or the shipped plugin.
