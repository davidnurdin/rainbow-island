<?php

namespace App\Scene;

use App\Arcade\ArcadeFont;
use App\Arcade\Artwork;
use App\Game\Session;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * GAME OVER, then the original's continue screen: fire to play on from the same round (the score
 * starts again from 0), left or right to give up; nothing for 10 seconds gives up too.
 */
final class GameOverScene extends AbstractScene
{
    private const GAME_OVER_TIME = 3.0;
    private const COUNTDOWN = 10;

    private float $time = 0.0;

    public function __construct(private Session $session, private Artwork $artwork)
    {
    }

    public function isOverlay(): bool
    {
        return true;
    }

    public function enter(array $params = []): void
    {
        $this->artwork->boot();
        $this->time = 0.0;
        $this->sound()->playMusic('gameover', false);
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        if ($this->time < self::GAME_OVER_TIME) {
            return;
        }
        $actions = $this->actions();
        if ($actions->isPressed('fire') || $actions->isPressed('jump') || $actions->isPressed('confirm')) {
            $this->sound()->playSfx('credit');
            $this->session->continueGame();
            $this->scenes()->switchTo(PlayScene::class, ['round' => $this->session->round]);
        } elseif ($actions->isPressed('left') || $actions->isPressed('right') || $actions->isPressed('back')
            || $this->secondsLeft() < 0) {
            $this->scenes()->switchTo(TitleScene::class);
        }
    }

    private function secondsLeft(): int
    {
        return self::COUNTDOWN - 1 - (int)floor($this->time - self::GAME_OVER_TIME);
    }

    public function render(Renderer $r): void
    {
        $font = $this->font();
        $cx = $this->width() / 2;
        $r->fillRect(0, 16, $this->width(), $this->height() - 16, [0, 0, 0, 200]);
        $font->draw($r, 'GAME OVER', $cx, 60, 1, ArcadeFont::RED, 'center');
        if ($this->time >= self::GAME_OVER_TIME) {
            $font->draw($r, 'CONTINUE ? ' . max(0, $this->secondsLeft()), $cx, 92, 1, ArcadeFont::YELLOW, 'center');
            $font->draw($r, '(CANCEL ... LEFT OR RIGHT)', $cx, 112, 1, ArcadeFont::CYAN, 'center');
        }
    }
}
