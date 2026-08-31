#!/usr/bin/env bash
#
# Build the distributable plugin zip: a clean staging copy with production
# (--no-dev) vendor, dev/test cruft stripped, ready to upload or attach to a
# GitHub Release. The single packaging entrypoint — do NOT hand-roll rsync/zip.
#
# Usage: bin/build.sh [output-dir]   (default: ./dist)
#
# NOTE: this does NOT php-scope the SDK yet (see lib/specs/php-scoper-build-spec.md).
# It produces the same artifact we've shipped, just reproducibly and in one step.
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="mbuzz-attribution"
OUTDIR="${1:-$SRC/dist}"
STAGE="$(mktemp -d)"
PLUGDIR="$STAGE/$SLUG"

VERSION="$(grep -m1 'Stable tag:' "$SRC/readme.txt" | sed -E 's/.*Stable tag: *//' | tr -d '[:space:]')"
ZIP="$OUTDIR/${SLUG}-${VERSION}.zip"

echo "→ Building $SLUG $VERSION"
mkdir -p "$PLUGDIR" "$OUTDIR"
rm -f "$ZIP"

# 1. Copy plugin sources (exclude dev-only files; vendor rebuilt fresh below).
rsync -a \
  --exclude '.git' --exclude '.github' --exclude 'tests' --exclude 'lib' \
  --exclude 'bin' --exclude 'dist' --exclude 'build' --exclude 'node_modules' \
  --exclude '.wp-env.json' --exclude '.wp-env.override.json' \
  --exclude '.wp-env.override.json.example' --exclude 'phpunit.xml' \
  --exclude '.gitignore' --exclude '.gitattributes' \
  --exclude 'README.md' --exclude 'CLAUDE.md' --exclude '.phpunit.cache' \
  --exclude 'vendor' \
  "$SRC"/ "$PLUGDIR"/

# 2. Production dependencies only.
( cd "$PLUGDIR" && composer install --no-dev --optimize-autoloader --no-interaction >/dev/null )

# 3. Strip dev artefacts bundled inside vendor (tests, CI, specs).
find "$PLUGDIR/vendor" -type d \( -name tests -o -name test -o -name docs \) -prune -exec rm -rf {} + 2>/dev/null || true
find "$PLUGDIR/vendor" -type f \( -name 'phpunit.xml*' -o -name '.gitignore' -o -name '.gitattributes' -o -name '*.dist' -o -name 'Makefile' \) -delete 2>/dev/null || true
find "$PLUGDIR/vendor" -type d -name '.github' -prune -exec rm -rf {} + 2>/dev/null || true
rm -rf "$PLUGDIR/vendor/mbuzz/mbuzz-php/lib/specs"

# 4. Zip.
( cd "$STAGE" && zip -rqX "$ZIP" "$SLUG" -x '*.DS_Store' )

# 5. Sanity checks — fail the build if essentials are missing or cruft leaked.
#    Written set -e-safe (explicit if, no && / || chains that trip pipefail).
fail() { echo "✗ BUILD FAILED: $1" >&2; rm -rf "$STAGE"; exit 1; }
listing="$(unzip -l "$ZIP")"
for needed in \
  "$SLUG/$SLUG.php" \
  "$SLUG/vendor/autoload.php" \
  "$SLUG/assets/js/mbuzz-capture.js" \
  "$SLUG/assets/admin/cf7-panel.js" \
  "$SLUG/readme.txt"; do
  if ! grep -qF " $needed" <<<"$listing"; then fail "missing from zip: $needed"; fi
done
if grep -Eq " $SLUG/(tests/|CLAUDE\.md|README\.md|phpunit\.xml|\.phpunit\.cache)" <<<"$listing"; then
  fail "dev cruft leaked into zip"
fi

rm -rf "$STAGE"
echo "✓ Built: $ZIP ($(du -h "$ZIP" | cut -f1), $(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files)"
echo "  sha256: $(shasum -a 256 "$ZIP" | cut -d' ' -f1)"
