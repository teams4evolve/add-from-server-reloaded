#!/usr/bin/env bash
# Build a wordpress.org-safe zip of Free.
# Output folder inside the zip is always "add-from-server-reloaded" (plugin slug),
# even if the working copy directory is named *-free.
#
# Usage (from plugin root or anywhere):
#   bash bin/build-org-zip.sh
#   bash bin/build-org-zip.sh /path/to/output-dir

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="add-from-server-reloaded"
DISTIGNORE="$PLUGIN_DIR/.distignore"
OUT_DIR="${1:-$PLUGIN_DIR/../}"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"

VERSION="$(
	grep -E '^\s*\*\s*Version:' "$PLUGIN_DIR/add-from-server-reloaded.php" \
		| head -1 \
		| sed -E 's/.*Version:[[:space:]]*([0-9.]+).*/\1/'
)"
if [[ -z "$VERSION" ]]; then
	echo "ERROR: could not read Version from plugin header" >&2
	exit 1
fi

ZIP_NAME="${SLUG}.${VERSION}.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG"

# Prefer rsync + .distignore (gitignore syntax). Fallback: find exclude list.
if command -v rsync >/dev/null 2>&1 && [[ -f "$DISTIGNORE" ]]; then
	rsync -a \
		--exclude-from="$DISTIGNORE" \
		--exclude="$ZIP_NAME" \
		"$PLUGIN_DIR/" "$STAGE/$SLUG/"
else
	echo "WARN: rsync or .distignore missing; using basic excludes" >&2
	rsync -a \
		--exclude='.git' \
		--exclude='vendor' \
		--exclude='tests' \
		--exclude='bin' \
		--exclude='phpcs.xml.dist' \
		--exclude='phpunit.xml.dist' \
		--exclude='.phpunit.result.cache' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='PROJECT-STATUS.md' \
		--exclude='README.md' \
		--exclude='CHANGELOG.md' \
		--exclude='.gitignore' \
		--exclude='.distignore' \
		"$PLUGIN_DIR/" "$STAGE/$SLUG/"
fi

# Hard fail if banned paths leaked into the stage.
BANNED=(
	"$STAGE/$SLUG/.git"
	"$STAGE/$SLUG/vendor"
	"$STAGE/$SLUG/tests"
	"$STAGE/$SLUG/bin"
	"$STAGE/$SLUG/phpcs.xml.dist"
	"$STAGE/$SLUG/phpunit.xml.dist"
	"$STAGE/$SLUG/.phpunit.result.cache"
	"$STAGE/$SLUG/composer.json"
	"$STAGE/$SLUG/PROJECT-STATUS.md"
)
for path in "${BANNED[@]}"; do
	if [[ -e "$path" ]]; then
		echo "ERROR: banned path present in zip stage: $path" >&2
		exit 1
	fi
done

if grep -R -n 'fonts.googleapis.com\|fonts.gstatic.com' "$STAGE/$SLUG" --include='*.php' --include='*.css' --include='*.js' >/dev/null 2>&1; then
	echo "ERROR: Google Fonts CDN URL still present in staged files" >&2
	grep -R -n 'fonts.googleapis.com\|fonts.gstatic.com' "$STAGE/$SLUG" --include='*.php' --include='*.css' --include='*.js' >&2 || true
	exit 1
fi

(
	cd "$STAGE"
	rm -f "$OUT_DIR/$ZIP_NAME"
	zip -rq "$OUT_DIR/$ZIP_NAME" "$SLUG"
)

echo "Built: $OUT_DIR/$ZIP_NAME"
echo "Root folder in zip: $SLUG/"
unzip -l "$OUT_DIR/$ZIP_NAME" | head -40
