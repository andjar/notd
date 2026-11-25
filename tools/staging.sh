#!/bin/sh
# Runs notd 4 beside notd 3 on a copy of your notes, for trying it in daily use.
#
#   1. In notd 3, export your notes as Markdown (a Logseq-style folder).
#   2. sh tools/staging.sh /path/to/that/export [port]
#   3. Open http://127.0.0.1:<port>/ (default 8090).
#
# The copy lives in staging-data/ next to this script's folder, so notd 3 and its
# database are never touched. Run it again with --fresh to start over.
set -eu
cd "$(dirname "$0")/.."
src=${1:?Usage: sh tools/staging.sh <notd 3 export folder> [port] [--fresh]}
port=${2:-8090}
data="$(pwd)/staging-data"
if [ "${3:-}" = "--fresh" ] || [ "${2:-}" = "--fresh" ]; then rm -rf "$data"; [ "${2:-}" = "--fresh" ] && port=8090; fi
export NOTD_DATA_DIR="$data"
if [ ! -f "$data/notd.sqlite" ]; then
  mkdir -p "$data"
  echo "Importing $src into $data ..."
  php tools/import.php "$src"
fi
echo "notd 4 (staging) on http://127.0.0.1:$port/  (Ctrl+C stops it)"
exec php -S "127.0.0.1:$port" index.php
