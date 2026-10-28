#!/bin/sh
# The screenshots of the web site, taken headless (var/site/*.png), then the pictures (site/images/).
# Run from the root of the repository: games/rainbow-island/tools/site/shots.sh
set -eu
cd "$(dirname "$0")/../../../.."
game=rainbow-island
shot() { name="$1" frames="$2"; shift 2; env "$@" bin/jumphp shot "$game" "$frames" "var/site/$name.png" >/dev/null; }

mkdir -p "games/$game/var/site"
shot title 120 JUMPHP_INPUT='[[5,"confirm"]]'
# one round per island, the demo bot playing (round 1: island 1, round 6: island 2...)
for r in 1 6 10 15 19 26; do
    shot "round-$r" 420 JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS="{\"round\":$r,\"demo\":true}"
done
for r in 4 28; do
    shot "boss-$r" 200 JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS="{\"round\":$r,\"arena\":true}"
done
python3 -I "games/$game/tools/site/make-images.py" "games/$game"
