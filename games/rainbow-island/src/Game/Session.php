<?php

namespace App\Game;

use JumPHP\Kernel\Engine;

/**
 * The player's game in progress: score, lives, round, the seven small diamonds of the current island
 * and the big diamonds won. Shared by the scenes (one service); the hi score is saved in var/.
 */
final class Session
{
    public const LAST_ROUND = 28;
    private const START_LIVES = 3;
    private const EXTRA_LIFE_EVERY = 100000;

    public float $score = 0;
    public float $hiScore = 100000;
    public int $lives = self::START_LIVES;
    public int $round = 1;
    public int $continues = 0;
    /** @var array<int, bool> colours (0-6) of the small diamonds picked up on this island */
    public array $diamonds = [];
    /** @var array<int, bool> islands whose big diamond was won */
    public array $bigDiamonds = [];
    private float $nextExtraLife = self::EXTRA_LIFE_EVERY;

    public function __construct(private Engine $engine)
    {
        $this->hiScore = max($this->hiScore, (float)$engine->storage->readJson('hiscore.json', 0));
    }

    public function start(int $round = 1): void
    {
        $this->score = 0;
        $this->lives = self::START_LIVES;
        $this->round = $round;
        $this->continues = 0;
        $this->diamonds = [];
        $this->bigDiamonds = [];
        $this->nextExtraLife = self::EXTRA_LIFE_EVERY;
    }

    /** CONTINUE: the score starts again from 0, on the same round. */
    public function continueGame(): void
    {
        $this->score = 0;
        $this->lives = self::START_LIVES;
        $this->continues++;
        $this->nextExtraLife = self::EXTRA_LIFE_EVERY;
    }

    /** Adds points; returns true when they gave an extra life. */
    public function addScore(float $points): bool
    {
        $this->score += $points;
        if ($this->score > $this->hiScore) {
            $this->hiScore = $this->score;
        }
        if ($this->score >= $this->nextExtraLife) {
            $this->nextExtraLife += self::EXTRA_LIFE_EVERY;
            $this->lives++;
            return true;
        }
        return false;
    }

    public function island(): int
    {
        return intdiv($this->round - 1, 4) + 1;
    }

    public function saveHiScore(): void
    {
        $this->engine->storage->writeJson('hiscore.json', $this->hiScore);
    }
}
