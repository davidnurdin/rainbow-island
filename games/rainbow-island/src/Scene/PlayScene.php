<?php

namespace App\Scene;

use App\Arcade\ArcadeFont;
use App\Arcade\Artwork;
use App\Entity\Boss;
use App\Entity\Bub;
use App\Entity\Enemy;
use App\Entity\Item;
use App\Entity\Rainbow;
use App\Game\ScoreBoard;
use App\Game\Session;
use App\World\Round;
use App\World\Terrain;
use JumPHP\Physics\Aabb;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * One round: climb from the bottom to the goal with the help of the rainbows, before the water rises.
 *
 * Phases: 'ready' (READY !), 'play', 'dying', 'goal' (GOAL IN!), and on the 4th round of an island
 * 'boss' (the camera rises to the arena above the goal) then 'completed' (COMPLETED!).
 *
 * The view is 256 x 176 like the CPC screen: 16 lines of scores, then 160 lines of playfield.
 * $cameraY is the world y shown at the top of the playfield (world y grows downwards, 0 = bottom of the round).
 *
 * Params: round (1-28, default: the session's), demo (a bot plays: the smoke test), new (a new game),
 * arena (tests: Bub starts at the goal, on a boss round right in front of the boss).
 */
final class PlayScene extends AbstractScene
{
    private const FIELD_HEIGHT = 160;
    private const MAX_RAINBOWS = 3;
    private const READY_TIME = 2.0;
    private const DEATH_TIME = 2.6;
    private const GOAL_TIME = 4.0;
    private const HURRY_MESSAGE_TIME = 2.0;
    private const WATER_DELAY = 4.0;
    private const WATER_SPEED = 9.0;

    private Round $round;
    private Terrain $terrain;
    private mixed $background;
    private Bub $bub;
    /** @var Enemy[] */
    private array $enemies = [];
    /** @var Item[] */
    private array $items = [];
    private ?Boss $boss = null;

    private string $phase = 'ready';
    private float $phaseTime = 0.0;
    /** Time since the round (or the last life) started: the water comes after the round's hurry time. */
    private float $clock = 0.0;
    private bool $hurry = false;
    private ?float $waterY = null;
    private float $cameraY = 0.0;
    private bool $demo = false;
    private float $demoTime = 0.0;
    private int $demoMove = 1;

    public function __construct(private Artwork $artwork, private Session $session, private ScoreBoard $scoreBoard)
    {
    }

    public function enter(array $params = []): void
    {
        $this->artwork->boot();
        $this->demo = !empty($params['demo']);
        if ($this->demo || !empty($params['new'])) {
            $this->session->start((int)($params['round'] ?? 1));
        } elseif (isset($params['round'])) {
            $this->session->round = (int)$params['round'];
        }
        $this->round = $this->artwork->loadRound($this->session->round);
        $this->terrain = new Terrain($this->round);
        $this->background = $this->artwork->roundTexture($this->round);
        $this->bub = new Bub(80, -72);
        if (!empty($params['arena'])) {
            $this->bub->setXY(80, $this->round->goalY() - Bub::SIZE - 16);
            $this->bub->onGround = false;
        }
        $this->boss = null;
        $this->items = [];
        $this->enemies = [];
        foreach ($this->round->objects as $object) {
            $kind = $this->round->island->enemy($object['type']);
            $this->enemies[] = new Enemy($object['x'] * Round::TILE, $object['y'] * Round::TILE, $kind['frames'], $kind['flies'],
                $object['flip'], $this->artwork, $this->round->island->number);
        }
        if ($this->round->number % 4 === 1) {
            $this->session->diamonds = [];
        }
        $this->restartClock();
        $this->cameraY = $this->cameraTarget();
        $this->setPhase('ready');
        $this->sound()->playMusic('theme');
    }

    public function exit(): void
    {
        $this->session->saveHiScore();
    }

    private function setPhase(string $phase): void
    {
        $this->phase = $phase;
        $this->phaseTime = 0.0;
    }

    private function restartClock(): void
    {
        $this->clock = 0.0;
        $this->hurry = false;
        $this->waterY = null;
        $this->terrain->rainbows = [];
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('pause') && !$this->demo) {
            $this->scenes()->push(PauseScene::class);
            return;
        }
        $this->phaseTime += $dt;
        switch ($this->phase) {
            case 'ready':
                if ($this->phaseTime >= self::READY_TIME) {
                    $this->setPhase('play');
                }
                break;
            case 'play':
            case 'boss':
                $this->play($dt);
                break;
            case 'dying':
                $this->dying($dt);
                break;
            case 'goal':
                $this->updateEnemies($dt);
                $this->updateItems($dt);
                if ($this->phaseTime >= self::GOAL_TIME) {
                    $this->nextRound();
                }
                break;
            case 'completed':
                $this->updateItems($dt);
                if ($this->phaseTime >= self::GOAL_TIME) {
                    $this->nextRound();
                }
                break;
        }
        $this->cameraY += ($this->cameraTarget() - $this->cameraY) * min(1.0, $dt * 5);
    }

    private function play(float $dt): void
    {
        $this->clock += $dt;
        [$move, $jump, $fire] = $this->controls($dt);
        $event = $this->bub->step($dt, $move, $jump, $this->terrain);
        if ($event === 'jump') {
            $this->sound()->playSfx('jump');
        } elseif ($event instanceof Rainbow) {
            $this->breakRainbow($event);
        }
        if ($fire) {
            $this->fireRainbow();
        }

        $this->updateRainbows($dt);
        $this->updateEnemies($dt);
        $this->updateItems($dt);
        if ($this->boss !== null) {
            $this->updateBoss($dt);
        }
        $this->updateWater($dt);

        if ($this->phase === 'play' && $this->bub->onGround && $this->bub->getFloatY() <= $this->round->goalY() + 4) {
            $this->reachGoal();
        }
    }

    /** @return array{int, bool, bool} move, jump, fire */
    private function controls(float $dt): array
    {
        if (!$this->demo) {
            $actions = $this->actions();
            return [(int)$actions->axis('left', 'right'), $actions->isPressed('jump'), $actions->isPressed('fire')];
        }
        // the bot of the smoke test: walks, fires a rainbow, climbs it, jumps
        $this->demoTime += $dt;
        $x = $this->bub->getFloatX();
        if ($x <= 0 || $x >= Round::WIDTH - Bub::SIZE || fmod($this->demoTime, 5.0) < $dt) {
            $this->demoMove = $x <= 0 ? 1 : ($x >= Round::WIDTH - Bub::SIZE ? -1 : -$this->demoMove);
        }
        $beat = fmod($this->demoTime, 1.6);
        return [$beat < 0.3 ? 0 : $this->demoMove, $beat >= 1.2 && $beat - $dt < 1.2, $beat < $dt];
    }

    private function fireRainbow(): void
    {
        $bub = $this->bub;
        if ($bub->isDying()) {
            return;
        }
        $right = $bub->getFacing() === 'right';
        // like the original, the arc is aligned on the tiles, its feet level with Bub's
        $x = $right ? ceil(($bub->getFloatX() + 16) / Round::TILE) * Round::TILE : floor(($bub->getFloatX() + 8 - Rainbow::WIDTH) / Round::TILE) * Round::TILE;
        $x = max(0, min(Round::WIDTH - Rainbow::WIDTH, $x));
        $y = round(($bub->feet() - Rainbow::HEIGHT) / Round::TILE) * Round::TILE;
        $this->terrain->rainbows[] = new Rainbow($x, $y, $bub->getFacing(), $this->artwork->rainbowTiles, $this->artwork->rainbowProfile);
        if (count($this->terrain->rainbows) > self::MAX_RAINBOWS) {
            array_shift($this->terrain->rainbows);
        }
        $bub->fire();
        $this->sound()->playSfx('rainbow');
    }

    private function breakRainbow(Rainbow $rainbow): void
    {
        if (!$rainbow->isFalling()) {
            $rainbow->breakDown();
            $this->sound()->playSfx('break');
        }
    }

    private function updateRainbows(float $dt): void
    {
        $bottom = $this->cameraY + self::FIELD_HEIGHT + 32;
        foreach ($this->terrain->rainbows as $i => $rainbow) {
            $rainbow->update($dt);
            if ($rainbow->dead || $rainbow->getFloatY() > $bottom) {
                unset($this->terrain->rainbows[$i]);
                continue;
            }
            if (!$rainbow->isGrowing() && !$rainbow->isFalling()) {
                continue;
            }
            $box = $rainbow->getBoundingBox();
            foreach ($this->enemies as $enemy) {
                if ($enemy->isHarmful() && $this->isNear($enemy) && Aabb::intersects($box, $enemy->hitBox())) {
                    $this->knockOut($enemy, $rainbow);
                }
            }
            // a falling rainbow breaks the rainbows it falls on
            if ($rainbow->isFalling()) {
                foreach ($this->terrain->rainbows as $other) {
                    if ($other !== $rainbow && !$other->isFalling() && Aabb::intersects($box, $other->getBoundingBox())) {
                        $this->breakRainbow($other);
                    }
                }
            }
            if ($this->boss !== null && !$this->boss->isBeaten() && !isset($rainbow->hits['boss'])
                && Aabb::intersects($box, $this->boss->hitBox())) {
                $rainbow->hits['boss'] = true;
                $this->boss->hit();
                $this->sound()->playSfx('hit');
                $this->addScore($this->boss->isBeaten() ? 10000 : 100);
            }
        }
        $this->terrain->rainbows = array_values($this->terrain->rainbows);
    }

    /**
     * A rainbow that grows over an enemy turns it into a fruit; a falling one into a small diamond whose
     * colour is given by the column of the screen (seven bands, left to right), like the original.
     */
    private function knockOut(Enemy $enemy, Rainbow $rainbow): void
    {
        if ($rainbow->isFalling()) {
            $band = min(Item::DIAMONDS - 1, (int)floor($enemy->centerX() * Item::DIAMONDS / Round::WIDTH));
            $item = Item::FIRST_DIAMOND + $band;
        } else {
            $item = mt_rand(0, Item::FRUITS - 1);
        }
        $enemy->knockOut($rainbow->centerX(), $item);
        $this->sound()->playSfx('hit');
        $this->addScore(100);
    }

    private function updateEnemies(float $dt): void
    {
        $bub = $this->bub;
        foreach ($this->enemies as $i => $enemy) {
            if (!$this->isNear($enemy)) {
                continue;
            }
            $item = $enemy->step($dt, $this->terrain);
            if ($item !== null) {
                $this->items[] = $item;
            }
            if ($enemy->dead) {
                unset($this->enemies[$i]);
            } elseif ($enemy->isHarmful() && !$bub->isDying() && $bub->invulnerable <= 0
                && Aabb::intersects($bub->hitBox(), $enemy->hitBox())) {
                $this->loseLife();
            }
        }
    }

    /** Enemies wait until the camera comes near them. */
    private function isNear(Enemy $enemy): bool
    {
        $y = $enemy->getFloatY();
        return $y > $this->cameraY - 48 && $y < $this->cameraY + self::FIELD_HEIGHT + 48;
    }

    private function updateItems(float $dt): void
    {
        foreach ($this->items as $i => $item) {
            $item->step($dt, $this->terrain);
            if (!$item->dead && !$this->bub->isDying() && Aabb::intersects($this->bub->getBoundingBox(), $item->getBoundingBox())) {
                $item->kill();
                $this->sound()->playSfx($item->isDiamond() ? 'bonus' : 'collect');
                if ($item->isDiamond()) {
                    $this->session->diamonds[$item->kind - Item::FIRST_DIAMOND] = true;
                }
                $this->addScore($item->points());
            }
            if ($item->dead) {
                unset($this->items[$i]);
            }
        }
    }

    private function updateBoss(float $dt): void
    {
        $boss = $this->boss;
        $boss->update($dt);
        if (!$boss->isBeaten() && !$this->bub->isDying() && $this->bub->invulnerable <= 0
            && Aabb::intersects($this->bub->hitBox(), $boss->hitBox())) {
            $this->loseLife();
        }
        if ($boss->isBeaten() && $boss->getFloatY() > $this->cameraY + self::FIELD_HEIGHT) {
            $this->completeIsland();
        }
    }

    private function updateWater(float $dt): void
    {
        $hurryAt = max(20, $this->round->hurrySeconds);
        if (!$this->hurry && $this->clock >= $hurryAt && $this->boss === null) {
            $this->hurry = true;
            $this->sound()->playMusic('hurry');
        }
        if ($this->hurry && $this->clock >= $hurryAt + self::WATER_DELAY) {
            $this->waterY = ($this->waterY ?? 0.0) - self::WATER_SPEED * $dt;
            if ($this->bub->feet() - 6 > $this->waterY && !$this->bub->isDying()) {
                $this->sound()->playSfx('splash');
                $this->loseLife();
            }
        }
    }

    private function addScore(float $points): void
    {
        if ($this->session->addScore($points)) {
            $this->sound()->playSfx('extralife');
        }
    }

    private function loseLife(): void
    {
        $this->bub->die();
        $this->setPhase('dying');
        $this->sound()->playMusic('death', false);
    }

    private function dying(float $dt): void
    {
        $this->bub->step($dt, 0, false, $this->terrain);
        if ($this->phaseTime < self::DEATH_TIME) {
            return;
        }
        if ($this->session->lives <= 0) {
            $this->session->saveHiScore();
            if ($this->demo) {
                $this->enter(['demo' => true, 'round' => $this->round->number]);
                return;
            }
            $this->scenes()->push(GameOverScene::class);
            return;
        }
        $this->session->lives--;
        $this->bub->revive();
        $this->restartClock();
        $this->setPhase($this->boss !== null ? 'boss' : 'play');
        $this->sound()->playMusic($this->boss !== null ? 'boss' : 'theme');
    }

    private function reachGoal(): void
    {
        if ($this->round->isBossRound()) {
            // the arena is the 12 rows above the goal: the camera rises there and the boss comes down
            $this->setPhase('boss');
            $this->hurry = false;
            $this->waterY = null;
            $this->boss = new Boss($this->round->topY(), $this->artwork, $this->round->island);
            $this->sound()->playMusic('boss');
            return;
        }
        $this->setPhase('goal');
        $this->waterY = null;
        // the enemies still around turn into fruits
        foreach ($this->enemies as $enemy) {
            if ($enemy->isHarmful() && $this->isNear($enemy)) {
                $enemy->knockOut($enemy->centerX(), mt_rand(0, Item::FRUITS - 1));
            }
        }
        $this->sound()->playMusic('goal', false);
    }

    private function completeIsland(): void
    {
        $this->boss = null;
        $this->setPhase('completed');
        if (count($this->session->diamonds) === Item::DIAMONDS) {
            $this->session->bigDiamonds[$this->round->island->number] = true;
        }
        $this->sound()->playMusic('goal', false);
    }

    private function nextRound(): void
    {
        if ($this->demo) {
            $this->enter(['demo' => true, 'round' => $this->round->number % Session::LAST_ROUND + 1]);
            return;
        }
        if ($this->round->number >= Session::LAST_ROUND) {
            $this->scenes()->switchTo(EndingScene::class);
            return;
        }
        $this->scenes()->switchTo(PlayScene::class, ['round' => $this->round->number + 1]);
    }

    /** Where the camera wants to be: Bub a bit under the middle, the boss arena fixed at the top. */
    private function cameraTarget(): float
    {
        $top = $this->round->topY();
        if ($this->phase === 'boss' || $this->phase === 'completed') {
            return $top;
        }
        return max($top, min(-self::FIELD_HEIGHT, $this->bub->getFloatY() - 84));
    }

    public function render(Renderer $r): void
    {
        $r->clear([0, 0, 0]);
        $island = $this->round->island->number;
        $cameraY = round($this->cameraY);
        // world y -> screen y
        $viewY = $cameraY - ScoreBoard::HEIGHT;
        $r->copy($this->background, [0, $cameraY - $this->round->topY(), Round::WIDTH, self::FIELD_HEIGHT], 0, ScoreBoard::HEIGHT);
        foreach ($this->terrain->rainbows as $rainbow) {
            $rainbow->draw($r, $this->artwork, $island, $viewY);
        }
        foreach ($this->items as $item) {
            $item->draw($r, $this->artwork, $island, $viewY);
        }
        foreach ($this->enemies as $enemy) {
            if ($this->isNear($enemy)) {
                $enemy->draw($r, $viewY);
            }
        }
        $this->boss?->draw($r, $viewY);
        $this->bub->draw($r, $this->artwork, $island, $viewY);
        if ($this->waterY !== null) {
            $this->drawWater($r, $this->waterY - $viewY);
        }

        $this->scoreBoard->render($r, $this->font());
        $this->scoreBoard->renderBottom($r);
        if ($this->boss !== null) {
            $this->drawBossEnergy($r);
        }
        $this->drawMessage($r);
    }

    private function drawWater(Renderer $r, float $y): void
    {
        $y = max(ScoreBoard::HEIGHT, $y);
        $r->fillRect(0, $y, $r->getWidth(), $r->getHeight() - $y, [0, 64, 255, 150]);
        for ($x = 0; $x < $r->getWidth(); $x += 16) {
            $r->fillRect($x + fmod($this->clock * 12, 16), $y, 8, 2, [128, 255, 255]);
        }
    }

    private function drawBossEnergy(Renderer $r): void
    {
        $r->fillRect(96, ScoreBoard::HEIGHT + 2, 64, 6, [0, 0, 0]);
        $r->fillRect(97, ScoreBoard::HEIGHT + 3, 62 * $this->boss->energy / Boss::ENERGY, 4, [255, 0, 0]);
    }

    private function drawMessage(Renderer $r): void
    {
        $font = $this->font();
        $cx = $r->getWidth() / 2;
        $cy = ScoreBoard::HEIGHT + self::FIELD_HEIGHT / 2;
        switch ($this->phase) {
            case 'ready':
                if ($this->round->number % 4 === 1) {
                    $font->draw($r, $this->round->island->name, $cx, $cy - 24, 1, ArcadeFont::CYAN, 'center');
                }
                $font->draw($r, 'ROUND ' . $this->round->number, $cx, $cy - 12, 1, ArcadeFont::WHITE, 'center');
                $font->draw($r, 'READY !', $cx, $cy, 1, ArcadeFont::YELLOW, 'center');
                break;
            case 'goal':
                $font->draw($r, 'GOAL IN!', $cx, $cy, 1, ArcadeFont::YELLOW, 'center');
                break;
            case 'completed':
                $font->draw($r, 'COMPLETED!', $cx, $cy, 1, ArcadeFont::YELLOW, 'center');
                break;
        }
        if ($this->hurry && $this->clock - max(20, $this->round->hurrySeconds) < self::HURRY_MESSAGE_TIME && $this->phase === 'play') {
            $font->draw($r, 'HURRY!', $cx, $cy, 1, ArcadeFont::RED, 'center');
        }
    }
}
