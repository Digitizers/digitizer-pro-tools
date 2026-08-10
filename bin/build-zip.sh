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
# Development-only paths are excluded explicitly.
cd "$ROOT"
mkdir -p "$STAGE/$SLUG"
git ls-files -z \
	| grep -zv '^\.github/' \
	| grep -zv '^bin/' \
	| grep -zv '^dist/' \
	| grep -zv '^\.gitignore$' \
	| grep -zv '^WPORG\.md$' \
	| grep -zv '\.code-workspace$' \
	| while IFS= read -r -d '' f; do
		mkdir -p "$STAGE/$SLUG/$(dirname "$f")"
		cp "$f" "$STAGE/$SLUG/$f"
	done

mkdir -p "$DIST"
rm -f "$DIST/$OUT_NAME"
( cd "$STAGE" && zip -rq "$DIST/$OUT_NAME" "$SLUG" -x '*.DS_Store' )

VERSION="$(grep -m1 "^ \* Version:" "$ROOT/$SLUG.php" | tr -d ' ' | cut -d: -f2)"
echo "built  : dist/$OUT_NAME"
echo "version: $VERSION"
echo "files  : $(unzip -l "$DIST/$OUT_NAME" | tail -1 | awk '{print $2}')"
echo "size   : $(du -h "$DIST/$OUT_NAME" | cut -f1)"
