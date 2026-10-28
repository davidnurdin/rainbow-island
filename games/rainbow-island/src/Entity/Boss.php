<?php

namespace App\Entity;

use App\Arcade\Artwork;
use App\World\Island;
use App\World\Round;
use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;

/**
 * The boss at the top of the 4th round of each island. It floats around its arena; every rainbow
 * that touches it takes one point of energy. Out of energy, it spins down and the island is completed.
 */
final class Boss extends Entity
{
    public const ENERGY = 10;
    private const SPEED = 40.0;

    public int $energy = self::ENERGY;
    private float $time = 0.0;
    private float $flash = 0.0;

    public function __construct(private float $arenaY, private Artwork $artwork, private Island $island)
    {
        [$this->width, $this->height] = $artwork->bossSize($island);
        $this->x = (Round::WIDTH - $this->width) / 2;
        $this->y = $arenaY + 24;
        $this->vx = self::SPEED;
        $this->state = 'fight';
    }

    public function isBeaten(): bool
    {
        return $this->energy <= 0;
    }

    public function hitBox(): array
    {
        return ['x' => $this->x + 6, 'y' => $this->y + 4, 'width' => $this->width - 12, 'height' => $this->height - 8];
    }

    public function hit(): void
    {
        if ($this->energy > 0) {
            $this->energy--;
            $this->flash = 0.3;
            if ($this->energy === 0) {
                $this->vy = -120;
            }
        }
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $this->flash = max(0.0, $this->flash - $dt);
        if ($this->isBeaten()) {
            $this->vy += 400 * $dt;
            $this->y += $this->vy * $dt;
            return;
        }
        // faster as it weakens
        $speed = 1 + (self::ENERGY - $this->energy) * 0.12;
        $this->x += $this->vx * $speed * $dt;
        if ($this->x < 0 || $this->x > Round::WIDTH - $this->width) {
            $this->x = max(0.0, min(Round::WIDTH - $this->width, $this->x));
            $this->vx = -$this->vx;
        }
        $this->y = $this->arenaY + 28 + sin($this->time * 1.7) * 22;
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
    }

    public function draw(Renderer $r, float $camY): void
    {
        if ($this->flash > 0 && fmod($this->flash, 0.1) < 0.05) {
            return;
        }
        $this->artwork->drawBoss($r, $this->island, $this->x, $this->y - $camY);
    }
}
