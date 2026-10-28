<?php

namespace App\Entity;

use App\Arcade\Artwork;
use App\World\Round;
use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;

/**
 * A rainbow fired by Bub: a 48 x 32 arc of overlay tiles. While it grows it knocks out the enemies it
 * sweeps; once grown it is a bridge to walk on. Landing on it from a jump breaks it: it falls and
 * knocks out everything below. It fades away after a few seconds.
 */
final class Rainbow extends Entity
{
    public const WIDTH = 48;
    public const HEIGHT = 32;
    private const GROW_TIME = 0.25;
    private const LIFETIME = 6.0;
    private const BLINK_TIME = 1.5;
    private const GRAVITY = 500.0;

    private float $age = 0.0;
    /** Enemies (and the boss) already hit by this rainbow. */
    public array $hits = [];

    /**
     * @param int[][] $tiles overlay tiles, 4 rows of 6
     * @param int[] $profile height of the arc (from its top) in each of the 48 columns
     */
    public function __construct(float $x, float $y, string $facing, private array $tiles, private array $profile)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = self::WIDTH;
        $this->height = self::HEIGHT;
        $this->facing = $facing;
        $this->state = 'growing';
    }

    public function update(float $dt): void
    {
        $this->age += $dt;
        if ($this->state === 'growing' && $this->age >= self::GROW_TIME) {
            $this->state = 'solid';
        }
        if ($this->state === 'falling') {
            $this->vy += self::GRAVITY * $dt;
            $this->y += $this->vy * $dt;
        } elseif ($this->age >= self::LIFETIME) {
            $this->kill();
        }
    }

    public function isGrowing(): bool
    {
        return $this->state === 'growing';
    }

    public function isFalling(): bool
    {
        return $this->state === 'falling';
    }

    /** Bub landed on it from a jump (or a falling rainbow hit it). */
    public function breakDown(): void
    {
        if ($this->state !== 'falling') {
            $this->state = 'falling';
            $this->vy = 0;
        }
    }

    /**
     * The walkable height (world y) of the arc at world column $x, null where there is nothing to stand on.
     * Its two feet are ramps so Bub can walk up from the ground.
     */
    public function surfaceAt(float $x): ?float
    {
        if ($this->state !== 'solid') {
            return null;
        }
        $local = (int)floor($x - $this->x);
        if ($local < 0 || $local >= self::WIDTH) {
            return null;
        }
        $ramp = 8;
        $edge = min($local, self::WIDTH - 1 - $local);
        if ($edge < $ramp) {
            $bottom = self::HEIGHT - 1;
            return $this->y + $bottom - ($bottom - $this->profile[$ramp]) * $edge / $ramp;
        }
        return $this->y + $this->profile[$local];
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
    }

    public function draw(Renderer $r, Artwork $artwork, int $island, float $camY): void
    {
        $left = self::LIFETIME - $this->age;
        if ($this->state !== 'falling' && $left < self::BLINK_TIME && fmod($left, 0.2) < 0.08) {
            return;
        }
        // while it grows, the arc appears column by column from Bub's side
        $columns = $this->state === 'growing' ? (int)ceil($this->age / self::GROW_TIME * 6) : 6;
        foreach ($this->tiles as $row => $line) {
            foreach ($line as $column => $tile) {
                $shown = $this->facing === 'right' ? $column < $columns : $column >= 6 - $columns;
                if ($tile && $shown) {
                    $artwork->drawOverlayTile($r, $island, $tile, $this->x + $column * Round::TILE, $this->y - $camY + $row * Round::TILE);
                }
            }
        }
    }
}
