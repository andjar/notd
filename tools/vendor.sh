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

echo "client/vendor rebuilt:"; du -sh client/vendor/*
