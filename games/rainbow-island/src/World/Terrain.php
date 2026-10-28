<?php

namespace App\World;

use App\Entity\Rainbow;

/**
 * What can be stood on: the tops of the solid tiles of the round, and the rainbows.
 * Like in the original, platforms are crossed from below and from the sides; only their top edge holds.
 */
final class Terrain
{
    /** @var Rainbow[] */
    public array $rainbows = [];
    /** The rainbow found by the last surface() call, null when it was a tile. */
    public ?Rainbow $lastRainbow = null;

    public function __construct(public readonly Round $round)
    {
    }

    /**
     * The highest surface under column $x whose height is in ($from, $to] (world y), or null.
     * Each probe of an entity's feet calls it; $withRainbows is false for the enemies.
     * $rainbowFrom (default $from) lets a rainbow be found higher than the tiles.
     */
    public function surface(float $x, float $from, float $to, bool $withRainbows = true, ?float $rainbowFrom = null): ?float
    {
        $best = null;
        $this->lastRainbow = null;
        $column = (int)floor($x / Round::TILE);
        for ($row = (int)floor($from / Round::TILE) + 1, $last = (int)floor($to / Round::TILE); $row <= $last; $row++) {
            if ($this->round->isGround($column, $row)) {
                $best = $row * Round::TILE;
                break;
            }
        }
        if ($withRainbows) {
            foreach ($this->rainbows as $rainbow) {
                $y = $rainbow->surfaceAt($x);
                if ($y !== null && $y > ($rainbowFrom ?? $from) && $y <= $to && ($best === null || $y < $best)) {
                    $best = $y;
                    $this->lastRainbow = $rainbow;
                }
            }
        }
        return $best;
    }

    /** A wall at least two tiles high in front of something standing with its feet at $feet. */
    public function isWall(float $x, float $feet): bool
    {
        $column = (int)floor($x / Round::TILE);
        $row = (int)floor(($feet - 1) / Round::TILE);
        return $this->round->isSolid($column, $row) && $this->round->isSolid($column, $row - 1);
    }
}
