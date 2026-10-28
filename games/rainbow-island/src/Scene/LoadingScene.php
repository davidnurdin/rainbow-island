<?php

namespace App\Scene;

use App\Arcade\Artwork;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * The loading screen of the disc (320 x 200 on the CPC, shown at 4/5 to fit the 256 x 176 view),
 * for a few seconds or until a key is pressed.
 */
final class LoadingScene extends AbstractScene
{
    private const DURATION = 4.0;

    private float $time = 0.0;

    public function __construct(private Artwork $artwork)
    {
    }

    public function enter(array $params = []): void
    {
        $this->artwork->boot();
        $this->time = 0.0;
        if (!$this->sdl()->hasTexture('loading')) {
            $this->sdl()->loadTexture('loading', 'assets/sprites/loading.png');
        }
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $actions = $this->actions();
        if ($this->time >= self::DURATION || $actions->isPressed('confirm') || $actions->isPressed('fire') || $actions->isPressed('back')) {
            $this->scenes()->switchTo(TitleScene::class);
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([0, 0, 0]);
        $r->copy($this->sdl()->getTexture('loading')['texture'], null, 0, 8, 256, 160);
    }
}
