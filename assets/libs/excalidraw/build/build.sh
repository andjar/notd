#!/bin/sh
# Rebuilds assets/libs/excalidraw from the npm packages. Not part of using
# notd (the result is committed); needed only to update Excalidraw.
#
#   sh assets/libs/excalidraw/build/build.sh 0.18.0
set -e
VERSION=${1:-0.18.0}
HERE=$(cd "$(dirname "$0")" && pwd)
OUT=$(cd "$HERE/.." && pwd)
WORK=$(mktemp -d)
cp "$HERE/entry.js" "$HERE/mermaid-stub.js" "$WORK/"
cd "$WORK"
npm init -y >/dev/null
npm install --ignore-scripts --no-audit --no-fund "@excalidraw/excalidraw@$VERSION" react@18.3.1 react-dom@18.3.1 esbuild@0.24.0
./node_modules/.bin/esbuild entry.js --bundle --splitting --format=esm --minify --target=es2020 --legal-comments=external \
  --define:process.env.NODE_ENV='"production"' --alias:@excalidraw/mermaid-to-excalidraw=./mermaid-stub.js \
  --entry-names=excalidraw --chunk-names='chunks/[name]-[hash]' --outdir=dist
rm -rf "$OUT/chunks" "$OUT/fonts"
cp dist/excalidraw.js "$OUT/"
cp -r dist/chunks "$OUT/"
cp node_modules/@excalidraw/excalidraw/dist/prod/index.css "$OUT/excalidraw.css"
mkdir -p "$OUT/fonts"
# Every font but Xiaolai (13 MB of Chinese and Japanese glyphs; such text falls back to a system font).
for f in node_modules/@excalidraw/excalidraw/dist/prod/fonts/*; do
  [ "$(basename "$f")" = Xiaolai ] || cp -r "$f" "$OUT/fonts/"
done
echo "Done. Update LICENSES.txt if the bundled packages changed."
