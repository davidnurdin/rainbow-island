<?php

namespace App\Game;

use App\Arcade\ArcadeFont;
use App\Arcade\Artwork;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;

/**
 * The two lines at the top of the screen (1UP / HI SCORE) and the lives at the bottom,
 * placed like on the CPC screen (256 x 176: 16 lines of scores, then the playfield).
 */
final class ScoreBoard
{
    public const HEIGHT = 16;

    /** The small diamond colours, in the order of the seven columns of the playfield. */
    public const DIAMOND_COLOURS = ['#ff0000', '#ff8000', '#ffff80', '#00ff00', '#0080ff', '#000080', '#8000ff'];

    public function __construct(private Session $session, private Artwork $artwork)
    {
    }

    public function render(Renderer $r, TextDrawer $font): void
    {
        $r->fillRect(0, 0, $r->getWidth(), self::HEIGHT, [0, 0, 0]);
        $font->draw($r, '1UP', 16, 0, 1, ArcadeFont::GREEN);
        $font->draw($r, self::points($this->session->score), 64, 8, 1, ArcadeFont::WHITE, 'right');
        $font->draw($r, 'HI SCORE', $r->getWidth(), 0, 1, ArcadeFont::RED, 'right');
        $font->draw($r, self::points($this->session->hiScore), $r->getWidth(), 8, 1, ArcadeFont::WHITE, 'right');
    }

    /** Lives (rainbow arcs) and the diamonds picked up on this island, over the bottom of the playfield. */
    public function renderBottom(Renderer $r): void
    {
        $y = $r->getHeight() - 7;
        for ($i = 0; $i < min($this->session->lives, 8); $i++) {
            $this->artwork->drawLifeIcon($r, 1 + $i * 9, $y);
        }
        foreach (self::DIAMOND_COLOURS as $i => $colour) {
            if (!empty($this->session->diamonds[$i])) {
                $r->fillRect($r->getWidth() - 60 + $i * 8, $y + 1, 5, 5, $colour);
            }
        }
    }

    /** Scores are shown like the original: "00" for nothing. */
    public static function points(float $score): string
    {
        return $score < 1 ? '00' : number_format($score, 0, '', '');
    }
}
