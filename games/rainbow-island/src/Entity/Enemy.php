<?php

namespace App\Entity;

use App\Arcade\Artwork;
use App\World\Round;
use App\World\Terrain;
use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;

/**
 * An enemy of the island, placed by the round's map. Walkers patrol their platform and turn back at
 * its edges; flyers drift diagonally. Hit by a rainbow, an enemy spins away and lands as an item.
 */
final class Enemy extends Entity
{
    private const WALK_SPEED = 28.0;
    private const FLY_SPEED = 26.0;
    private const GRAVITY = 600.0;
    private const SPIN_TIME = 0.6;

    private float $time = 0.0;
    private float $homeY;
    /** Spinning after a hit: the item it becomes (-1 while alive). */
    public int $dropsItem = -1;

    /** (x, y): top-left corner, like the placements of the map. @param int[] $frames its animation in the island's sprite sheet */
    public function __construct(float $x, float $y, private array $frames, private bool $flies, bool $flip, private Artwork $artwork,
                                private int $island)
    {
        [$this->width, $this->height] = $artwork->frameSize($island, $frames[0]);
        $this->x = $x;
        $this->y = $y;
        $this->homeY = $this->y;
        $this->facing = $flip ? 'left' : 'right';
        $this->vx = ($flip ? -1 : 1) * ($flies ? self::FLY_SPEED : self::WALK_SPEED);
        $this->vy = $flies ? self::FLY_SPEED : 0;
        $this->state = $flies ? 'fly' : 'walk';
    }

    public function isSpinning(): bool
    {
        return $this->state === 'spin';
    }

    /** Can hurt Bub / be hit by a rainbow. */
    public function isHarmful(): bool
    {
        return !$this->dead && !$this->isSpinning();
    }

    public function hitBox(): array
    {
        return ['x' => $this->x + 3, 'y' => $this->y + 3, 'width' => $this->width - 6, 'height' => $this->height - 4];
    }

    /** Knocked out: it flies away from the rainbow, then becomes item $item. */
    public function knockOut(float $fromX, int $item): void
    {
        $this->state = 'spin';
        $this->time = 0.0;
        $this->dropsItem = $item;
        $this->vx = ($this->centerX() < $fromX ? -1 : 1) * 70;
        $this->vy = -180;
    }

    /** Returns the Item it turned into when its spin ends. */
    public function step(float $dt, Terrain $terrain): ?Item
    {
        $this->time += $dt;
        if ($this->isSpinning()) {
            $this->vy += self::GRAVITY * $dt;
            $this->x = max(0.0, min(Round::WIDTH - $this->width, $this->x + $this->vx * $dt));
            $feet = $this->y + $this->height;
            $ground = $this->vy > 0 ? $terrain->surface($this->centerX(), $feet, $feet + $this->vy * $dt, false) : null;
            $this->y += $this->vy * $dt;
            if ($this->time > self::SPIN_TIME && ($ground !== null || $this->time > 3)) {
                $this->kill();
                return new Item($this->centerX() - 8, ($ground ?? $this->y + $this->height) - 16, $this->dropsItem);
            }
            return null;
        }
        if ($this->flies) {
            $this->fly($dt);
        } else {
            $this->walk($dt, $terrain);
        }
        return null;
    }

    private function fly(float $dt): void
    {
        $this->x += $this->vx * $dt;
        $this->y += $this->vy * $dt;
        if ($this->x < 0 || $this->x > Round::WIDTH - $this->width) {
            $this->x = max(0.0, min(Round::WIDTH - $this->width, $this->x));
            $this->vx = -$this->vx;
        }
        if (abs($this->y - $this->homeY) > 40) {
            $this->y = $this->homeY + ($this->y > $this->homeY ? 40 : -40);
            $this->vy = -$this->vy;
        }
        $this->facing = $this->vx < 0 ? 'left' : 'right';
    }

    private function walk(float $dt, Terrain $terrain): void
    {
        $feet = $this->y + $this->height;
        if ($this->state === 'fall') {
            $this->vy = min(240.0, $this->vy + self::GRAVITY * $dt);
            $ground = $terrain->surface($this->centerX(), $feet - 0.5, $feet + $this->vy * $dt, false);
            if ($ground === null) {
                $this->y += $this->vy * $dt;
                return;
            }
            $this->y = $ground - $this->height;
            $this->vy = 0;
            $this->state = 'walk';
        }
        $x = $this->x + $this->vx * $dt;
        $front = $this->vx > 0 ? $x + $this->width : $x;
        $edge = $terrain->surface($front, $feet - 4.5, $feet + 4, false) === null;
        if ($x < 0 || $x > Round::WIDTH - $this->width || $edge || $terrain->isWall($front, $feet)) {
            $this->vx = -$this->vx;
        } else {
            $this->x = $x;
        }
        $this->facing = $this->vx < 0 ? 'left' : 'right';
        if ($terrain->surface($this->centerX(), $feet - 4.5, $feet + 4, false) === null) {
            $this->state = 'fall';
        }
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
    }

    public function draw(Renderer $r, float $camY): void
    {
        if ($this->isSpinning()) {
            $frame = Artwork::FRAMES_SPIN[(int)($this->time * 16) % 4];
        } else {
            $frame = $this->frames[(int)($this->time * 6) % count($this->frames)];
        }
        $this->artwork->drawFrame($r, $this->island, $frame, $this->x, $this->y - $camY, $this->facing === 'left');
    }
}
