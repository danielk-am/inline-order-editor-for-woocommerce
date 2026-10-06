#!/usr/bin/env bash
# Builds the files that ship into build/inline-order-editor-for-woocommerce, and zips them.
# Usage: bin/build.sh          (folder only, for the test store)
#        bin/build.sh --zip    (folder and build/inline-order-editor-for-woocommerce.zip)
set -euo pipefail

SLUG="inline-order-editor-for-woocommerce"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

mkdir -p "$ROOT/build/$SLUG"
rsync -a --delete --exclude-from="$ROOT/.distignore" "$ROOT/" "$ROOT/build/$SLUG/"

if [ "${1:-}" = "--zip" ]; then
	rm -f "$ROOT/build/$SLUG.zip"
	(cd "$ROOT/build" && zip -rqX "$SLUG.zip" "$SLUG" -x '*.DS_Store')
	echo "build/$SLUG.zip"
else
	echo "build/$SLUG"
fi
