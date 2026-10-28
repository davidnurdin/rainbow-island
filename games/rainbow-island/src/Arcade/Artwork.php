<?php

namespace App\Arcade;

use App\World\Island;
use App\World\Round;
use JumPHP\Kernel\Engine;
use JumPHP\Renderer\Renderer;

/**
 * Every picture of the game, all extracted from the original disc (see tools/extract):
 * per island a background tile sheet, an overlay tile sheet (rainbows, items) and a sprite sheet,
 * plus the font, the loading screen and the rainbow's shape.
 *
 * Sprite frames are numbered like in the original: 0-3 Bub walking, 4 Bub firing, 5-7 Bub dying,
 * 11 a star, 12-15 the spin of a hit enemy, 16-28 the enemies and the boss of the island.
 */
final class Artwork
{
    public const FRAME_FIRE = 4;
    public const FRAME_DEATH = 5;
    public const FRAMES_SPIN = [12, 13, 14, 15];
    /** Overlay tile of the first item; item k is the 2x2 block of tiles FIRST_ITEM_TILE + 4k (TL, TR, BL, BR). */
    private const FIRST_ITEM_TILE = 0x13;

    private bool $booted = false;
    /** @var Island[] */
    private array $islands = [];
    /** @var array<int, array<int, ?array{x: int, y: int, w: int, h: int}>> sprite rectangles by island */
    private array $frames = [];
    /** @var int[][] the rainbow: overlay tiles, 4 rows of 6 */
    public array $rainbowTiles = [];
    /** @var int[] top of the rainbow (pixels from its top) in each of its 48 columns */
    public array $rainbowProfile = [];

    public function __construct(private Engine $engine)
    {
    }

    /** Loads the shared assets once: every scene calls it in enter() (the smoke test may start anywhere). */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $config = $this->engine->config;
        $this->engine->setFont(new ArcadeFont($this->engine->sdl));
        foreach (json_decode((string)file_get_contents($config->path('assets/data/islands.json')), true) as $i => $data) {
            $this->islands[$i + 1] = Island::fromArray($i + 1, $data);
        }
        $rainbow = json_decode((string)file_get_contents($config->path('assets/data/rainbow.json')), true);
        $this->rainbowTiles = $rainbow['tiles'];
        $this->rainbowProfile = $rainbow['profile'];

        $sound = $this->engine->sound;
        foreach (['jump', 'rainbow', 'bonus', 'collect', 'break', 'credit', 'tally', 'splash', 'hit', 'extralife'] as $sfx) {
            $sound->loadSfx($sfx, "assets/sfx/$sfx.ogg");
        }
        foreach (['theme', 'boss', 'hurry', 'goal', 'gameover', 'death'] as $music) {
            $sound->loadMusic($music, "assets/music/$music.ogg");
        }
        // the menus use the original "credit" and "rainbow" effects
        $sound->loadSfx('menu_move', 'assets/sfx/credit.ogg');
        $sound->loadSfx('menu_confirm', 'assets/sfx/rainbow.ogg');
    }

    public function island(int $number): Island
    {
        return $this->islands[$number];
    }

    public function loadRound(int $number): Round
    {
        $island = $this->island(intdiv($number - 1, 4) + 1);
        $this->loadIsland($island->number);
        return Round::load($this->engine->config->path(sprintf('assets/maps/round-%02d.json', $number)), $island);
    }

    /** Textures of an island (only one island is kept in memory). */
    public function loadIsland(int $number): void
    {
        $sdl = $this->engine->sdl;
        if (isset($this->frames[$number])) {
            return;
        }
        foreach (array_keys($this->frames) as $old) {
            foreach (["island-$old-tiles", "island-$old-overlay", "island-$old-sprites"] as $name) {
                $sdl->releaseTexture($name);
            }
        }
        $this->frames = [];
        $sdl->loadTexture("island-$number-tiles", "assets/tiles/island-$number.png");
        $sdl->loadTexture("island-$number-overlay", "assets/tiles/overlay-$number.png");
        $sdl->loadTexture("island-$number-sprites", "assets/sprites/island-$number.png");
        $this->frames[$number] = json_decode((string)file_get_contents(
            $this->engine->config->path("assets/sprites/island-$number.json")), true);
    }

    /** Size [w, h] of a sprite frame. */
    public function frameSize(int $island, int $frame): array
    {
        $rect = $this->frames[$island][$frame] ?? null;
        return $rect === null ? [0, 0] : [$rect['w'], $rect['h']];
    }

    /** Draws a sprite frame with its top-left corner at (x, y); sprites look to the right, $flip mirrors them. */
    public function drawFrame(Renderer $r, int $island, int $frame, float $x, float $y, bool $flip = false): void
    {
        $rect = $this->frames[$island][$frame] ?? null;
        if ($rect === null) {
            return;
        }
        $texture = $this->engine->sdl->getTexture("island-$island-sprites")['texture'];
        $r->copy($texture, [$rect['x'], $rect['y'], $rect['w'], $rect['h']], $x, $y, null, null, $flip);
    }

    /**
     * Size [w, h] of the boss of an island as drawn: the big bosses (islands 1 and 7) are stored as their
     * left half; the CPC version keeps no big boss picture in the sprite table of the other islands,
     * so their biggest sprite stands in for it, drawn twice as big.
     */
    public function bossSize(Island $island): array
    {
        [$w, $h] = $this->frameSize($island->number, $island->bossFrame);
        return $island->bossMirrored ? [$w * 2, $h] : [$w * 2, $h * 2];
    }

    public function drawBoss(Renderer $r, Island $island, float $x, float $y): void
    {
        $rect = $this->frames[$island->number][$island->bossFrame] ?? null;
        if ($rect === null) {
            return;
        }
        $texture = $this->engine->sdl->getTexture("island-{$island->number}-sprites")['texture'];
        $src = [$rect['x'], $rect['y'], $rect['w'], $rect['h']];
        if ($island->bossMirrored) {
            $r->copy($texture, $src, $x, $y);
            $r->copy($texture, $src, $x + $rect['w'], $y, null, null, true);
        } else {
            $r->copy($texture, $src, $x, $y, $rect['w'] * 2, $rect['h'] * 2);
        }
    }

    public function drawOverlayTile(Renderer $r, int $island, int $tile, float $x, float $y): void
    {
        $texture = $this->engine->sdl->getTexture("island-$island-overlay")['texture'];
        $r->copy($texture, [($tile % 16) * Round::TILE, intdiv($tile, 16) * Round::TILE, Round::TILE, Round::TILE], $x, $y);
    }

    /** Item $kind (0-7 fruits of the island, 8-14 the seven small diamonds, then bonus objects), 16x16. */
    public function drawItem(Renderer $r, int $island, int $kind, float $x, float $y): void
    {
        $tile = self::FIRST_ITEM_TILE + $kind * 4;
        $this->drawOverlayTile($r, $island, $tile, $x, $y);
        $this->drawOverlayTile($r, $island, $tile + 1, $x + 8, $y);
        $this->drawOverlayTile($r, $island, $tile + 2, $x, $y + 8);
        $this->drawOverlayTile($r, $island, $tile + 3, $x + 8, $y + 8);
    }

    /**
     * The whole background of a round in one texture (drawn once with GD from the tile sheet),
     * so a frame draws it with a single copy.
     */
    public function roundTexture(Round $round): mixed
    {
        $sdl = $this->engine->sdl;
        $name = 'round-background';
        if ($sdl->hasTexture($name)) {
            $sdl->releaseTexture($name);
        }
        $number = $round->island->number;
        $sheet = imagecreatefrompng($this->engine->config->path("assets/tiles/island-$number.png"));
        $mirrored = imagecreatefrompng($this->engine->config->path("assets/tiles/island-$number.png"));
        imageflip($mirrored, IMG_FLIP_HORIZONTAL);
        $rows = $round->rows();
        $image = imagecreatetruecolor(Round::WIDTH, count($rows) * Round::TILE);
        foreach ($rows as $y => $row) {
            foreach ($row as $x => $tile) {
                $index = $tile & 0x7f;
                $column = $index % 16;
                if ($tile & 0x80) {
                    imagecopy($image, $mirrored, $x * 8, $y * 8, (15 - $column) * 8, intdiv($index, 16) * 8, 8, 8);
                } else {
                    imagecopy($image, $sheet, $x * 8, $y * 8, $column * 8, intdiv($index, 16) * 8, 8, 8);
                }
            }
        }
        return $sdl->createTextureFromGd($name, $image)[0]['texture'];
    }

    /** The small rainbow arcs that count the lives at the bottom of the screen. */
    public function drawLifeIcon(Renderer $r, float $x, float $y): void
    {
        $sdl = $this->engine->sdl;
        if (!$sdl->hasTexture('life')) {
            $sdl->createTextureFromPixels('life', [
                '..oooo..',
                '.oyyyyo.',
                'oyo..oyo',
                'yo....oy',
                'y......y',
                'y......y',
            ], ['o' => '#ff8000', 'y' => '#ffff80']);
        }
        $r->copy($sdl->getTexture('life')['texture'], null, $x, $y);
    }
}
