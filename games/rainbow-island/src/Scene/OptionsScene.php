<?php

namespace App\Scene;

use App\Arcade\ArcadeFont;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;

/**
 * Settings, saved in var/options.json (IndexedDB in the browser) by the engine's Options service.
 * Pushed over the title screen or the pause menu: back() pops it.
 */
final class OptionsScene extends MenuScene
{
    protected string $title = 'OPTIONS';
    protected float $titleScale = 2.0;
    protected array $titleColor = ArcadeFont::CYAN;
    private bool $overlay = false;

    public function enter(array $params = []): void
    {
        $this->overlay = !empty($params['overlay']);
        parent::enter($params);
    }

    public function isOverlay(): bool
    {
        return $this->overlay;
    }

    protected function items(): array
    {
        $options = $this->engine->options;
        $sound = $this->sound();
        $items = [
            ['SOUND', fn () => round($sound->getSfxVolume() * 100) . '%', null, function (int $dir) use ($sound, $options): void {
                $sound->setSfxVolume(round($sound->getSfxVolume() * 10 + $dir) / 10);
                $options->set('sfx_volume', $sound->getSfxVolume());
                $sound->playSfx('menu_move');
            }],
            ['MUSIC', fn () => round($sound->getMusicVolume() * 100) . '%', null, function (int $dir) use ($sound, $options): void {
                $sound->setMusicVolume(round($sound->getMusicVolume() * 10 + $dir) / 10);
                $options->set('music_volume', $sound->getMusicVolume());
            }],
            ['LANGUAGE', fn () => strtoupper($this->engine->lang->getLang()), null, function (int $dir) use ($options): void {
                $langs = $this->engine->lang->available();
                $i = array_search($this->engine->lang->getLang(), $langs, true);
                $lang = $langs[((int)$i + $dir + count($langs)) % count($langs)];
                $this->engine->lang->setLang($lang);
                $options->set('lang', $lang);
            }],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['FULLSCREEN', fn () => $options->get('fullscreen') ? 'ON' : 'OFF', null, fn () => $this->engine->toggleFullscreen()];
        }
        $items[] = ['BACK', null, fn () => $this->back()];
        return $items;
    }

    protected function renderBackground(Renderer $r): void
    {
        $r->fillRect(0, 0, $this->width(), $this->height(), $this->overlay ? [0, 0, 0, 220] : [0, 0, 0]);
    }

    protected function back(): void
    {
        $this->scenes()->pop();
    }
}
