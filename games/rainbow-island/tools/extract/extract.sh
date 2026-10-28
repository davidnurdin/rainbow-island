#!/usr/bin/env bash
# Rebuilds the graphics and the maps of assets/ from the original disc image.
#   tools/extract/extract.sh /path/to/rainbow.dsk [work dir]
# Needs a C compiler, python3 and Pillow on the machine (an offline tool: the game never runs it).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GAME="$(cd "$HERE/../.." && pwd)"
DSK="$1"
WORK="${2:-$(mktemp -d)}"
make -C "$HERE/emulator" >/dev/null
for round in $(seq 0 27); do
  python3 -I "$HERE/extract_round.py" "$HERE/emulator/cpc" "$DSK" "$WORK/rounds" "$round"
done
python3 -I "$HERE/export.py" "$DSK" "$WORK/rounds" "$GAME"
