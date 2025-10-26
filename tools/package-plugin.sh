#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="dmg-read-more"
ZIP_NAME="${PLUGIN_SLUG}.zip"
DIST_DIR="tools/plugin-dist"

echo "📦 Packaging plugin: $PLUGIN_SLUG → $DIST_DIR/$ZIP_NAME"

# Clean and create dist/
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

# Build blocks
npm run build:blocks

# Exclude dev files and directories
zip -r "$DIST_DIR/$ZIP_NAME" . \
  -x "node_modules/*" \
  -x "vendor/*" \
  -x ".*" \
  -x "tests/*" \
  -x "wp-env.json" \
  -x "package-lock.json" \
  -x "package.json" \
  -x "tools/*" \
  -x "wordpress-src/*" \
  -x "dist/*"

echo "✅ Done! $DIST_DIR/$ZIP_NAME created."