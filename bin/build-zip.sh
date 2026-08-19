#!/usr/bin/env bash
#
# Build an installable plugin ZIP from the current working tree.
#
# The plugin lives at the repository root, so the files are staged into a
# digitizer-pro-tools/ directory first - WordPress requires the archive to
# contain exactly one folder named after the plugin.
#
# Usage:
#   bin/build-zip.sh                 # -> dist/digitizer-pro-tools.zip
#   bin/build-zip.sh my-name.zip     # -> dist/my-name.zip
#
set -euo pipefail

SLUG="digitizer-pro-tools"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_NAME="${1:-$SLUG.zip}"
DIST="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Ship only what git tracks: no stray local files, no dist/, no build output.
# Development-only paths are skipped by the case below - deliberately filtered
# in bash rather than with `grep -z`, whose --null-data flag is a GNU extension
# missing from older macOS and busybox builds.
cd "$ROOT"
mkdir -p "$STAGE/$SLUG"
while IFS= read -r -d '' f; do
	case "$f" in
		.github/*|bin/*|dist/*|docs/*|tests/*|.gitignore|WPORG.md|*.code-workspace) continue ;;
	esac
	mkdir -p "$STAGE/$SLUG/$(dirname "$f")"
	cp "$f" "$STAGE/$SLUG/$f"
done < <( git ls-files -z )

mkdir -p "$DIST"
rm -f "$DIST/$OUT_NAME"
( cd "$STAGE" && zip -rq "$DIST/$OUT_NAME" "$SLUG" -x '*.DS_Store' )

VERSION="$(grep -m1 "^ \* Version:" "$ROOT/$SLUG.php" | tr -d ' ' | cut -d: -f2)"
echo "built  : dist/$OUT_NAME"
echo "version: $VERSION"
echo "files  : $(unzip -l "$DIST/$OUT_NAME" | tail -1 | awk '{print $2}')"
echo "size   : $(du -h "$DIST/$OUT_NAME" | cut -f1)"
