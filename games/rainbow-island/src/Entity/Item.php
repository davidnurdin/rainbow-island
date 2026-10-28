<?php

namespace App\Entity;

use App\Arcade\Artwork;
use App\World\Terrain;
use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;

/**
 * Something to pick up, 16 x 16: a fruit of the island (kinds 0-7), one of the seven small diamonds
 * (8-14, its colour depends on where the enemy was knocked down), or a bonus object.
 */
final class Item extends Entity
{
    public const FRUITS = 8;
    public const FIRST_DIAMOND = 8;
    public const DIAMONDS = 7;
    private const LIFETIME = 10.0;

    private float $age = 0.0;

    public function __construct(float $x, float $y, public readonly int $kind)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = 16;
        $this->height = 16;
    }

    public function isDiamond(): bool
    {
        return $this->kind >= self::FIRST_DIAMOND && $this->kind < self::FIRST_DIAMOND + self::DIAMONDS;
    }

    public function points(): int
    {
        return $this->isDiamond() ? 1000 : ($this->kind < self::FRUITS ? 100 * ($this->kind + 1) : 2000);
    }

    public function step(float $dt, Terrain $terrain): void
    {
        $this->age += $dt;
        if ($this->age > self::LIFETIME) {
            $this->kill();
            return;
        }
        $feet = $this->y + $this->height;
        $fall = 120 * $dt;
        $ground = $terrain->surface($this->centerX(), $feet - 0.5, $feet + $fall);
        $this->y = $ground === null ? $this->y + $fall : $ground - $this->height;
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
    }

    public function draw(Renderer $r, Artwork $artwork, int $island, float $camY): void
    {
        if (self::LIFETIME - $this->age < 2 && fmod($this->age, 0.2) < 0.1) {
            return;
        }
        $artwork->drawItem($r, $island, $this->kind, $this->x, $this->y - $camY);
    }
}
