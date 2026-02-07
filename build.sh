#!/bin/bash
# Build a distributable zip of the Ahasend plugin

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
ZIP_NAME="ahasend-email-sender.zip"

cd "$PLUGIN_DIR"
rm -f "$ZIP_NAME"

zip -r "$ZIP_NAME" . \
  -x ".git/*" \
  -x ".claude/*" \
  -x "*.DS_Store" \
  -x ".gitignore" \
  -x "build.sh" \
  -x "BUILD.md" \
  -x "*.zip"

echo "Created $ZIP_NAME"
