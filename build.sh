#!/usr/bin/env bash
set -euo pipefail

# Builds a distribution zip of the plugin, ready to upload via WP admin.
# Run from the plugin root: ./build.sh (or: npm run zip)

PLUGIN_SLUG="gallop"
ROOT="$(cd "$(dirname "$0")" && pwd)"
STAGING="$(mktemp -d)"
OUTPUT="$ROOT/$PLUGIN_SLUG.zip"

trap 'rm -rf "$STAGING"' EXIT

if command -v composer >/dev/null 2>&1; then
    (cd "$ROOT" && composer install --no-dev --optimize-autoloader --quiet)
fi

mkdir -p "$STAGING/$PLUGIN_SLUG"

rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.DS_Store' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='build.sh' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='*.zip' \
    --exclude='composer.lock' \
    "$ROOT/" "$STAGING/$PLUGIN_SLUG/"

rm -f "$OUTPUT"
(cd "$STAGING" && zip -rq "$OUTPUT" "$PLUGIN_SLUG")

echo "Built $OUTPUT ($(du -h "$OUTPUT" | cut -f1))"
