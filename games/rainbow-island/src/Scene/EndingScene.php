<?php

namespace App\Scene;

use App\Arcade\ArcadeFont;
use App\Game\ScoreBoard;
use App\Game\Session;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * After the 28th round: the story texts of the original, page by page (fire skips a page).
 * With the 7 big diamonds the people of the islands are saved; without, they ask Bub to come back.
 */
final class EndingScene extends AbstractScene
{
    private const PAGE_TIME = 9.0;
    private const STORY = [
        'THE ADVENTURE ON THE RAINBOW', 'ISLANDS IS OVER! AND YOU', 'HAVE SUCCEEDED IN GETTING A',
        'LOT OF TREASURES!', 'THE PURPOSE HAS BEEN', 'ACCOMPLISHED...',
    ];
    private const BAD_ENDING = [
        ['BUT, JUST THEN THE HEAP OF', 'TREASURES BEGINS TO BREAK', 'DOWN!, "WHAT\'S THIS?"', 'IT IS A LETTER FROM THE',
            'PEOPLE IN THE RAINBOW ISLANDS', 'ASKING FOR HELP!'],
        ['WE WERE CAPTURED BY', '"THE BOSS OF SHADOW"', 'WHO TURNED MANY PEOPLE', 'INTO THE BUBBLE DRAGONS!',
            'IF YOU GET 7 BIG DIAMONDS', 'OF THE 7 ISLANDS,', 'YOU WILL SAVE US!!', '', 'PLEASE! PLEASE! HELP US!!'],
    ];
    private const GOOD_ENDING = [
        ['THANK YOU VERY MUCH, BUBBY!!', 'AT LAST, THE PEACE CAME', 'BACK TO THIS WORLD, THANKS TO', 'YOUR BRAVE ACT!!',
            'WE SHALL NEVER FORGET YOUR', 'ACHIEVEMENT, EVER!', '', 'THANK YOU!!'],
        ['BONUS   1,000,000 POINTS'],
    ];
    private const STAFF = ["GRAFTGOLD 88'- 89'", '', 'STEVE TURNER', 'ANDREW BRAYBROOK', "DAVID O'CONNOR", 'JOHN CUMMING',
        'DOMINIC ROBINSON', 'GARY J. FOREMAN', 'JASON PAGE'];

    /** @var string[][] */
    private array $pages = [];
    private int $page = 0;
    private float $time = 0.0;

    public function __construct(private Session $session, private ScoreBoard $scoreBoard)
    {
    }

    public function enter(array $params = []): void
    {
        $won = count($this->session->bigDiamonds) >= 7;
        $this->pages = array_merge([self::STORY], $won ? self::GOOD_ENDING : self::BAD_ENDING, [self::STAFF]);
        if ($won) {
            $this->session->addScore(1000000);
        }
        $this->session->saveHiScore();
        $this->page = 0;
        $this->time = 0.0;
        $this->sound()->playMusic('theme');
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $actions = $this->actions();
        if ($this->time >= self::PAGE_TIME || ($this->time > 0.5 && ($actions->isPressed('fire') || $actions->isPressed('confirm')))) {
            $this->time = 0.0;
            if (++$this->page >= count($this->pages)) {
                $this->scenes()->switchTo(TitleScene::class);
            }
        }
        if ($actions->isPressed('back')) {
            $this->scenes()->switchTo(TitleScene::class);
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([0, 0, 0]);
        $this->scoreBoard->render($r, $this->font());
        $lines = $this->pages[min($this->page, count($this->pages) - 1)];
        $y = 96 - count($lines) * 6;
        foreach ($lines as $i => $line) {
            $colour = $this->page === count($this->pages) - 1 && $i === 0 ? ArcadeFont::YELLOW : ArcadeFont::WHITE;
            $this->font()->draw($r, $line, $this->width() / 2, $y + $i * 12, 1, $colour, 'center');
        }
    }
}
