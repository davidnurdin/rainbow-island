# rainbow-island — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API).

## Checklist

```bash
bin/jumphp test rainbow-island                 # lint + phpstan + headless smoke test (must pass)
bin/jumphp shot rainbow-island 60              # loading screen -> games/rainbow-island/var/screenshots/shot.png
JUMPHP_INPUT='[[5,"confirm"],[60,"confirm"]]' bin/jumphp shot rainbow-island 240 var/screenshots/play.png
JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS='{"round":4,"arena":true}' bin/jumphp shot rainbow-island 200   # a boss
bin/jumphp build rainbow-island && bin/jumphp webshot rainbow-island 13 '[[3,"Enter"],[6,"Enter"],[8,"Enter"]]'
```

## This game

- **What it is**: a remake of the Amstrad CPC version of Rainbow Islands (Graftgold 1989): 7 islands × 4 rounds.
  Every asset (maps, tiles, sprites, font, loading screen, music, sound effects) was extracted from the
  original disc with `tools/extract` (see its README; the disc itself is not in the repository).
- **Screen**: 256 × 176 like the CPC (128 mode-0 pixels drawn 2 wide): 16 lines of scores, 160 lines of playfield.
- **World**: `World\Round` (map: 32 columns of 8 px, rows numbered from the bottom -1 up to `top`, y = row × 8,
  bit 7 of a tile = mirrored), `World\Island` (`assets/data/islands.json`), `World\Terrain` (what can be stood
  on: tops of solid tiles, crossed from below, and the rainbows).
- **Scenes**: `LoadingScene` → `TitleScene` (menu; credits page when idle) → `PlayScene` (phases ready / play /
  dying / goal / boss / completed) → next round, `EndingScene` after round 28. Overlays: `PauseScene`,
  `GameOverScene` (continue countdown), `OptionsScene` (pushed).
- **Entities**: `Bub`, `Rainbow` (grows 0.25 s and knocks out enemies; walkable; landing on it from a jump breaks
  it and it falls, turning enemies into diamonds), `Enemy` (walkers / flyers), `Item`, `Boss`.
- **Services**: `Arcade\Artwork` (all textures, sounds; call `boot()` in every scene's `enter()`), `Arcade\ArcadeFont`
  (the original font, 5 colour schemes), `Game\Session` (score, lives, round, diamonds), `Game\ScoreBoard`.
- **Controls** (`config/input.yaml`): arrows walk, Up / Z / W jump, Space / X / Ctrl rainbow, P / Escape pause.
- **Smoke test**: `PlayScene` with `demo: true` (a bot walks, fires, jumps; restarts on game over).
  Test params: `round` (1-28), `arena` (start at the goal: on a 4th round, in front of the boss).
- **Non-obvious**:
  - The distance signs (1, 2, 3, G) are part of the map tiles: do not draw them.
  - Enemy kinds by object type and which ones fly come partly from observation in the emulator, partly
    guessed from the frame order (`ENEMY_FRAMES` / `FLYERS` in `tools/extract/export.py`).
  - Only islands 1 and 7 have a big boss picture in their sprite table (stored as a left half, mirrored);
    for the other islands the biggest sprite of the island stands in for the boss, drawn 2×.
    How the original draws those bosses has not been worked out yet.
  - The round's `hurry` byte is used as seconds before HURRY! (min 20); the water then rises.
