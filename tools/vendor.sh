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
dst=client/vendor/marked
rm -rf "$dst" && mkdir -p "$dst"
cp "$tmp/marked/package/lib/marked.esm.js" "$tmp/marked/package/LICENSE" "$dst/"

# DOMPurify: removes anything unsafe from the HTML (Apache-2.0 or MPL-2.0).
# Saved as .js: some web servers (phpdesktop's, older Apache) send .mjs files with
# a MIME type the browser refuses for a module.
fetch dompurify@3.5.0 dompurify
dst=client/vendor/dompurify
rm -rf "$dst" && mkdir -p "$dst"
cp "$tmp/dompurify/package/dist/purify.es.mjs" "$dst/purify.js"
cp "$tmp/dompurify/package/LICENSE" "$dst/"

# Newsreader: the serif for titles and headings (SIL Open Font License 1.1). The
# variable font with optical sizes, in two parts: Latin (with Norwegian), and the
# rest of Latin, which the browser only loads when a title needs it.
fetch @fontsource-variable/newsreader@5.3.0 newsreader
dst=client/vendor/newsreader
rm -rf "$dst" && mkdir -p "$dst"
cp "$tmp/newsreader/package/files/newsreader-latin-opsz-normal.woff2" \
   "$tmp/newsreader/package/files/newsreader-latin-ext-opsz-normal.woff2" \
   "$tmp/newsreader/package/LICENSE" "$dst/"

# The source maps are not vendored, so drop the comments that point to them (DevTools
# would ask the server for each one and log a 404).
find client/vendor -name '*.js' | while read -r f; do
  grep -v '^//# sourceMappingURL=' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
done

# The core and the plugins import these by relative path (client/lib/html.js and the
# like), never by a bare name, so a library must not import another by a bare name either.
if grep -rlE "(from|import)[[:space:]]*['\"][^./'\"]" client/vendor --include='*.js'; then
  echo "a vendored file imports by a bare name (above); notd has no import map" >&2; exit 1
fi

echo "client/vendor rebuilt:"; du -sh client/vendor/*
