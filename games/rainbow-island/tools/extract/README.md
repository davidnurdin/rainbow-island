# Extraction of the original game

These tools grew into **[dsk2jumphp](https://github.com/davidnurdin/dsk2jumphp)**: the same emulator made
generic (ROM boot, EXTENDED images, typed text...), more tools, the method documented, and this extraction as
its example `examples/rainbow-islands/` (it gives the same assets byte for byte). Use dsk2jumphp for another
game; this folder keeps the version that built this game.

Everything in `assets/` comes from the Amstrad CPC disc of the original (not stored in this repository),
through a small CPC emulator written for this purpose: the game is really run, and its memory is read
once it has built each round.

```bash
tools/extract/extract.sh /path/to/rainbow.dsk      # needs cc, python3, Pillow (offline tool)
```

| File | Role |
|---|---|
| `emulator/cpc.c`, `z80.c`, `fdc.inc` | the emulator: Z80, gate array, CRTC, keyboard, µPD765 disc controller (the game has its own loader and disc driver). Options: `-k` keys at given frames, `-d` memory dumps, `-w` write watches, `-p` / `-c` pokes, `-S` screenshots, PSG register log (`psg.bin`) |
| `emulator/ay.c` | renders a PSG register log (50 Hz frames) to a WAV, like the CPC's AY-3-8912 at 1 MHz |
| `dsk.py`, `cpc.py` | disc image (CPCEMU format) and CPC helpers: hardware palette, mode 0 pixels |
| `island.py` | the 7 island files: run-length packed (3 equal bytes, then a count), one block per round |
| `extract_round.py` | runs the emulator on one round (the round number is poked when the game picks it, the camera is let up to the top), dumps the memory and logs the writes into the map ring buffer |
| `mapbuild.py` | rebuilds the full map of a round from that log (the game keeps only 64 rows in a ring at `#0800`) |
| `sprite.py`, `frameset.py` | sprite frames (table at `#4BFC`; the blitter goes zig-zag, so odd lines are stored mirrored) and their sizes |
| `export.py` | writes the PNGs and JSON files of `assets/` |
| `z80dis.py` | a Z80 disassembler used to study the code |

Useful addresses of the original: round `#FEE0`, lives `#FEE1`, goal row `#FEE4`, camera `#FEFB`,
solid tile threshold `#FEC8`, objects `#47A7` (32 bytes each), font `#1A30`, overlay tiles (rainbow,
items) `#1000`, background tiles unpacked at `#7300`, sound effect request `#614F`, music request `#6152`.

The music and the sound effects were recorded from the emulator's PSG log and rendered with `ay`
(game patched so nothing else plays at the same time: `#3CC3=#7E` no death, `#6186=#AF` no effects),
then converted to Ogg. That step was done by hand: it is not part of `extract.sh`.
