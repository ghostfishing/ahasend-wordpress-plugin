#!/bin/bash
# Build a distributable zip of the Ahasend plugin

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
ZIP_NAME="ahasend-email-sender.zip"

cd "$PLUGIN_DIR"
rm -f "$ZIP_NAME"

zip -r "$ZIP_NAME" . \
  -x ".git/*" \
  -x ".github/*" \
  -x ".claude/*" \
  -x ".gitignore" \
  -x "build.sh" \
  -x "BUILD.md" \
  -x "*.zip" \
  -x "*.DS_Store" \
  -x "__MACOSX/*" \
  -x "Thumbs.db" \
  -x "ahasend-email-sender/*"

echo "Created $ZIP_NAME"
