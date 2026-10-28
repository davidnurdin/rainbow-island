<?php

namespace App\Arcade;

use JumPHP\Renderer\BitmapFont;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Util\Color;

/**
 * The font of the original game (assets/fonts/font.png, 8x8 glyphs extracted from the disc).
 *
 * The CPC version prints its texts in five colour schemes (each glyph has 2-3 shades); the sheet has
 * one row per scheme, and draw() picks the scheme closest to the colour asked for. Characters the
 * original font does not have (lower case is upper-cased, '<' '>' of the menus...) fall back to the
 * engine's built-in font.
 */
final class ArcadeFont implements TextDrawer
{
    public const RED = [255, 0, 0];
    public const GREEN = [0, 255, 0];
    public const CYAN = [0, 255, 255];
    public const YELLOW = [255, 255, 128];
    public const WHITE = [255, 255, 255];

    /** Glyph order of the sheet; '@' is the copyright sign. */
    private const CHARS = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ !\"'+,-.?@()";
    private const SCHEMES = [self::RED, self::GREEN, self::CYAN, self::YELLOW, self::WHITE];
    private const ACCENTS = ['À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E',
        'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', '©' => '@'];
    private const SIZE = 8;

    private BitmapFont $fallback;

    public function __construct(private Sdl $sdl)
    {
        if (!$sdl->hasTexture('font')) {
            $sdl->loadTexture('font', 'assets/fonts/font.png');
        }
        $this->fallback = new BitmapFont($sdl);
    }

    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = self::WHITE,
                         string $align = 'left', Color|array|string|null $shadow = null): void
    {
        $text = $this->normalize($text);
        $texture = $this->sdl->getTexture('font')['texture'];
        $row = $this->scheme(Color::of($color));
        $size = self::SIZE * $scale;
        foreach (explode("\n", $text) as $n => $line) {
            $left = $x;
            if ($align !== 'left') {
                $left -= $align === 'center' ? $this->width($line, $scale) / 2 : $this->width($line, $scale);
            }
            $top = $y + $n * $this->lineHeight($scale);
            for ($i = 0, $len = strlen($line); $i < $len; $i++) {
                $char = $line[$i];
                if ($char === ' ') {
                    continue;
                }
                $glyph = strpos(self::CHARS, $char);
                if ($glyph === false) {
                    $this->fallback->draw($r, $char, $left + $i * $size + $scale, $top + $scale, $scale, $color);
                    continue;
                }
                $r->copy($texture, [$glyph * self::SIZE, $row * self::SIZE, self::SIZE, self::SIZE], $left + $i * $size, $top, $size, $size);
            }
        }
    }

    /** The big titles of the original: glyphs stretched to twice their height, one space between letters. */
    public function drawTall(Renderer $r, string $text, float $x, float $y, Color|array|string $color, string $align = 'center'): void
    {
        $text = implode(' ', str_split($this->normalize($text)));
        $texture = $this->sdl->getTexture('font')['texture'];
        $row = $this->scheme(Color::of($color));
        $left = $align === 'center' ? $x - $this->width($text) / 2 : $x;
        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $glyph = strpos(self::CHARS, $text[$i]);
            if ($text[$i] !== ' ' && $glyph !== false) {
                $r->copy($texture, [$glyph * self::SIZE, $row * self::SIZE, self::SIZE, self::SIZE], $left + $i * self::SIZE, $y, self::SIZE, self::SIZE * 2);
            }
        }
    }

    public function width(string $text, float $scale = 1): int
    {
        $lines = explode("\n", $this->normalize($text));
        return (int)round(max(array_map('strlen', $lines)) * self::SIZE * $scale);
    }

    public function height(float $scale = 1): int
    {
        return (int)round(self::SIZE * $scale);
    }

    public function lineHeight(float $scale = 1): int
    {
        return (int)round(self::SIZE * $scale);
    }

    private function normalize(string $text): string
    {
        return strtoupper(strtr(mb_strtoupper($text, 'UTF-8'), self::ACCENTS));
    }

    /** The row of the sheet whose colour is the nearest to $color. */
    private function scheme(Color $color): int
    {
        $best = 0;
        $bestDistance = PHP_INT_MAX;
        foreach (self::SCHEMES as $row => [$red, $green, $blue]) {
            $distance = ($color->r - $red) ** 2 + ($color->g - $green) ** 2 + ($color->b - $blue) ** 2;
            if ($distance < $bestDistance) {
                $best = $row;
                $bestDistance = $distance;
            }
        }
        return $best;
    }
}
