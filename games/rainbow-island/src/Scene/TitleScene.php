<?php

namespace App\Scene;

use App\Arcade\ArcadeFont;
use App\Arcade\Artwork;
use App\Game\ScoreBoard;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;

/**
 * The title screen of the original (same texts, same places), with the menu in the middle.
 * Left alone, it shows the credits page from time to time, like the original's attract mode.
 */
final class TitleScene extends MenuScene
{
    private const CREDITS_EVERY = 12.0;
    private const CREDITS_TIME = 5.0;
    private const CREDITS = [
        ['RAINBOW PROJECT', ArcadeFont::YELLOW],
        ['', null],
        ['PROGRAM BY', ArcadeFont::CYAN], ["DAVID O'CONNOR", ArcadeFont::WHITE],
        ['GRAPHICS BY', ArcadeFont::CYAN], ['JOHN CUMMING', ArcadeFont::WHITE],
        ['SOUND BY', ArcadeFont::CYAN], ['STEVE TURNER', ArcadeFont::WHITE],
        ['PRODUCED BY', ArcadeFont::CYAN], ['GRAFTGOLD LTD', ArcadeFont::WHITE],
    ];

    protected float $menuTop = 0.41;
    private float $idle = 0.0;

    public function __construct(private Artwork $artwork, private ScoreBoard $scoreBoard)
    {
    }

    public function enter(array $params = []): void
    {
        $this->artwork->boot();
        parent::enter($params);
        $this->menu->spacing = 4;
        $this->idle = 0.0;
        $this->sound()->stopMusic();
    }

    protected function items(): array
    {
        $items = [
            ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class, ['new' => true])],
            ['OPTIONS', null, fn () => $this->scenes()->push(OptionsScene::class)],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['QUIT', null, fn () => $this->engine->quit()];
        }
        return $items;
    }

    public function update(float $dt): void
    {
        $this->idle += $dt;
        if ($this->showsCredits()) {
            // any key brings the menu back
            foreach (['confirm', 'back', 'up', 'down', 'fire', 'jump'] as $action) {
                if ($this->actions()->isPressed($action)) {
                    $this->idle = 0.0;
                }
            }
            return;
        }
        foreach (['up', 'down', 'confirm', 'left', 'right'] as $action) {
            if ($this->actions()->isPressed($action)) {
                $this->idle = 0.0;
            }
        }
        parent::update($dt);
    }

    private function showsCredits(): bool
    {
        return fmod($this->idle, self::CREDITS_EVERY + self::CREDITS_TIME) >= self::CREDITS_EVERY;
    }

    public function render(Renderer $r): void
    {
        $r->clear([0, 0, 0]);
        $this->scoreBoard->render($r, $this->font());
        if ($this->showsCredits()) {
            $this->renderCredits($r);
            return;
        }
        parent::render($r);
    }

    protected function renderBackground(Renderer $r): void
    {
        $font = $this->font();
        $cx = $this->width() / 2;
        if ($font instanceof ArcadeFont) {
            $font->drawTall($r, 'RAINBOW ISLANDS', $cx, 32, ArcadeFont::YELLOW);
            $font->drawTall($r, 'TAITO', $cx, 108, ArcadeFont::RED);
        }
        $font->draw($r, '- THE STORY OF BUBBLE BOBBLE 2 -', $cx, 56, 1, ArcadeFont::CYAN, 'center');
        $font->draw($r, '@ TAITO CORPORATION 1987', 32, 132, 1, ArcadeFont::WHITE);
        $font->draw($r, '@ GRAFTGOLD LTD 1989', 32, 148, 1, ArcadeFont::WHITE);
    }

    private function renderCredits(Renderer $r): void
    {
        $y = 40;
        foreach (self::CREDITS as [$text, $colour]) {
            if ($colour !== null) {
                $this->font()->draw($r, $text, $this->width() / 2, $y, 1, $colour, 'center');
            }
            $y += $text === '' || $colour === ArcadeFont::WHITE ? 14 : 10;
        }
    }
}
