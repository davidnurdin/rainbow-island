<?php

namespace App\Scene;

use App\Arcade\ArcadeFont;
use JumPHP\Ui\MenuScene;

/**
 * Pause menu, pushed over the round (P / Escape / Start, or when the window loses the focus).
 */
final class PauseScene extends MenuScene
{
    protected string $title = 'PAUSE';
    protected float $titleScale = 2.0;
    protected array $titleColor = ArcadeFont::YELLOW;
    protected float $menuTop = 0.5;

    public function isOverlay(): bool
    {
        return true;
    }

    protected function items(): array
    {
        return [
            ['RESUME', null, fn () => $this->scenes()->pop()],
            ['OPTIONS', null, fn () => $this->scenes()->push(OptionsScene::class, ['overlay' => true])],
            ['TITLE SCREEN', null, fn () => $this->scenes()->switchTo(TitleScene::class)],
        ];
    }

    protected function back(): void
    {
        $this->scenes()->pop();
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('pause') && !$this->actions()->isPressed('back')) {
            $this->scenes()->pop();
            return;
        }
        parent::update($dt);
    }
}
