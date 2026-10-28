<?php

namespace App\Entity;

use App\Arcade\Artwork;
use App\World\Round;
use App\World\Terrain;
use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;

/**
 * Bub, the hero: walks, jumps through the platforms from below, fires rainbows.
 * (x, y) is the top-left corner of his 24 x 24 sprite; he stands on two probes under his feet.
 */
final class Bub extends Entity
{
    public const SIZE = 24;
    private const WALK_SPEED = 64.0;
    private const JUMP_SPEED = -250.0;
    private const GRAVITY = 640.0;
    private const MAX_FALL_SPEED = 240.0;
    /** How high a step (or a rainbow ramp) he walks up without jumping. */
    private const STEP_UP = 8.0;
    /** A rainbow that appears around his feet: he gets on it rather than walking through. */
    private const RAINBOW_STEP_UP = 22.0;
    private const PROBES = [8, 16];

    public bool $onGround = true;
    /** In the air because of a jump: landing on a rainbow then breaks it. */
    public bool $jumping = false;
    public float $invulnerable = 0.0;
    private float $walkTime = 0.0;
    private float $fireTime = 0.0;
    private float $deathTime = 0.0;
    /** Where he last stood on a tile: he comes back there after losing a life. */
    public array $safeSpot;

    public function __construct(float $x, float $y)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = self::SIZE;
        $this->height = self::SIZE;
        $this->state = 'alive';
        $this->safeSpot = [$x, $y];
    }

    public function feet(): float
    {
        return $this->y + self::SIZE;
    }

    /** The part of him that touches enemies (smaller than the picture, like the original). */
    public function hitBox(): array
    {
        return ['x' => $this->x + 6, 'y' => $this->y + 6, 'width' => 12, 'height' => 16];
    }

    public function isDying(): bool
    {
        return $this->state === 'dying';
    }

    /**
     * One step of control and physics. $move: -1, 0, 1. Returns what happened this frame:
     * 'jump', 'land' or the Rainbow he landed on from a jump (the scene breaks it), or null.
     */
    public function step(float $dt, int $move, bool $jump, Terrain $terrain): string|Rainbow|null
    {
        $this->invulnerable = max(0.0, $this->invulnerable - $dt);
        $this->fireTime = max(0.0, $this->fireTime - $dt);
        if ($this->isDying()) {
            $this->deathTime += $dt;
            return null;
        }
        $event = null;
        $this->vx = $move * self::WALK_SPEED;
        if ($move !== 0) {
            $this->facing = $move > 0 ? 'right' : 'left';
            $this->walkTime += $dt;
        }
        if ($jump && $this->onGround) {
            $this->vy = self::JUMP_SPEED;
            $this->onGround = false;
            $this->jumping = true;
            $event = 'jump';
        }

        // across: the edges of the playfield, and walls when he walks on the ground
        $x = max(0.0, min(Round::WIDTH - self::SIZE, $this->x + $this->vx * $dt));
        $front = $move > 0 ? $x + self::PROBES[1] + 2 : $x + self::PROBES[0] - 2;
        if (!($this->onGround && $move !== 0 && $terrain->isWall($front, $this->feet()))) {
            $this->x = $x;
        }

        // up and down
        if ($this->onGround) {
            $ground = $this->surface($terrain, $this->feet() - self::STEP_UP - 0.5, $this->feet() + 4, $this->feet() - self::RAINBOW_STEP_UP);
            if ($ground === null) {
                $this->onGround = false;
                $this->vy = 0;
            } else {
                $this->y = $ground - self::SIZE;
            }
        } else {
            $this->vy = min(self::MAX_FALL_SPEED, $this->vy + self::GRAVITY * $dt);
            $fall = $this->vy * $dt;
            $ground = $fall > 0 ? $this->surface($terrain, $this->feet() - 0.5, $this->feet() + $fall) : null;
            if ($ground !== null && $terrain->lastRainbow !== null && $this->jumping) {
                $this->y += $fall;
                return $terrain->lastRainbow;
            }
            if ($ground !== null) {
                $this->y = $ground - self::SIZE;
                $this->vy = 0;
                $this->onGround = true;
                $this->jumping = false;
                $event ??= 'land';
            } else {
                $this->y += $fall;
            }
        }
        if ($this->onGround && $terrain->lastRainbow === null) {
            $this->safeSpot = [$this->x, $this->y];
        }
        return $event;
    }

    /** The highest surface under one of his two probes. */
    private function surface(Terrain $terrain, float $from, float $to, ?float $rainbowFrom = null): ?float
    {
        $best = null;
        $rainbow = null;
        foreach (self::PROBES as $probe) {
            $y = $terrain->surface($this->x + $probe, $from, $to, true, $rainbowFrom);
            if ($y !== null && ($best === null || $y < $best)) {
                $best = $y;
                $rainbow = $terrain->lastRainbow;
            }
        }
        $terrain->lastRainbow = $rainbow;
        return $best;
    }

    public function fire(): void
    {
        $this->fireTime = 0.2;
    }

    public function die(): void
    {
        $this->state = 'dying';
        $this->deathTime = 0.0;
        $this->vx = $this->vy = 0;
    }

    public function deathTime(): float
    {
        return $this->deathTime;
    }

    /** Back on his feet where he last stood safely, blinking (invulnerable) for a while. */
    public function revive(): void
    {
        [$this->x, $this->y] = $this->safeSpot;
        $this->state = 'alive';
        $this->onGround = true;
        $this->jumping = false;
        $this->vy = 0;
        $this->invulnerable = 2.5;
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
    }

    public function draw(Renderer $r, Artwork $artwork, int $island, float $camY): void
    {
        if ($this->invulnerable > 0 && fmod($this->invulnerable, 0.16) < 0.08) {
            return;
        }
        if ($this->isDying()) {
            // the surprised face, then he spins away
            $frame = $this->deathTime < 0.8 ? Artwork::FRAME_DEATH : 6 + (int)($this->deathTime * 8) % 2;
        } elseif ($this->fireTime > 0) {
            $frame = Artwork::FRAME_FIRE;
        } elseif (!$this->onGround) {
            $frame = 1;
        } else {
            $frame = $this->vx != 0 ? (int)($this->walkTime * 8) % 4 : 0;
        }
        $artwork->drawFrame($r, $island, $frame, $this->x, $this->y - $camY, $this->facing === 'left');
    }
}
