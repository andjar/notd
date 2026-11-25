#!/bin/sh
# Rebuilds client/vendor/ from the npm registry. The vendored files are committed;
# run this only to upgrade a library on purpose, then run all the tests.
# Usage: sh tools/vendor.sh
set -eu
cd "$(dirname "$0")/.."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

fetch() { # fetch <package@version> -> $tmp/<name>/package
  mkdir -p "$tmp/$2"
  (cd "$tmp/$2" && npm pack "$1" --silent > /dev/null && tar xzf ./*.tgz)
}

# lit-html: renders panels, dialogs and menus (BSD-3-Clause)
fetch lit-html@3.3.3 lit-html
dst=client/vendor/lit-html
rm -rf "$dst" && mkdir -p "$dst/directives"
for f in lit-html.js directive.js directive-helpers.js LICENSE; do cp "$tmp/lit-html/package/$f" "$dst/"; done
for f in repeat.js live.js class-map.js unsafe-html.js; do cp "$tmp/lit-html/package/directives/$f" "$dst/directives/"; done

# marked: Markdown to HTML (MIT)
fetch marked@18.3.0 marked
mkdir -p client/vendor/marked
cp "$tmp/marked/package/lib/marked.esm.js" "$tmp/marked/package/LICENSE" client/vendor/marked/

# DOMPurify: removes anything unsafe from the HTML (Apache-2.0 or MPL-2.0)
fetch dompurify@3.5.0 dompurify
mkdir -p client/vendor/dompurify
cp "$tmp/dompurify/package/dist/purify.es.mjs" "$tmp/dompurify/package/LICENSE" client/vendor/dompurify/

echo "client/vendor rebuilt:"; du -sh client/vendor/*
