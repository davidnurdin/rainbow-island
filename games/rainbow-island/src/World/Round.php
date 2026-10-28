<?php

namespace App\World;

/**
 * The map of one round (assets/maps/round-NN.json), extracted from the original game.
 *
 * The playfield is 32 tiles of 8x8 pixels wide. Rows are numbered from the bottom: the lowest row is
 * -1, the top of the round is $top (e.g. -87), so world y = row * 8 and y = 0 is the bottom edge.
 * A tile byte is a background tile index; bit 7 draws it mirrored.
 */
final class Round
{
    public const TILE = 8;
    public const COLUMNS = 32;
    public const WIDTH = self::COLUMNS * self::TILE;

    /**
     * @param int[][] $rows tile bytes, top row first
     * @param int[] $signs rows of the distance boards (drawn in the map tiles; the last one is the goal)
     * @param array<int, array{type: int, x: int, y: int, flip: bool}> $objects enemies (tile column, row)
     */
    private function __construct(
        public readonly int $number,
        public readonly Island $island,
        public readonly int $top,
        public readonly int $goalRow,
        public readonly int $hurrySeconds,
        public readonly array $signs,
        public readonly array $objects,
        private array $rows,
    ) {
    }

    public static function load(string $file, Island $island): self
    {
        $data = json_decode((string)file_get_contents($file), true);
        $rows = [];
        foreach ($data['rows'] as $hex) {
            $rows[] = array_values(unpack('C*', (string)hex2bin($hex)));
        }
        return new self($data['round'], $island, $data['top'], $data['goalRow'], $data['hurry'],
            $data['signs'], $data['objects'], $rows);
    }

    /** The last round of an island: a boss waits above the goal. */
    public function isBossRound(): bool
    {
        return $this->number % 4 === 0;
    }

    public function topY(): int
    {
        return $this->top * self::TILE;
    }

    public function goalY(): int
    {
        return $this->goalRow * self::TILE;
    }

    /** The tile byte at a column and an (absolute, negative) row; 0 outside the map. */
    public function tile(int $column, int $row): int
    {
        return $this->rows[$row - $this->top][$column] ?? 0;
    }

    public function isSolid(int $column, int $row): bool
    {
        if ($column < 0 || $column >= self::COLUMNS || $row < $this->top || $row >= 0) {
            return false;
        }
        return ($this->tile($column, $row) & 0x7f) >= $this->island->solidFrom;
    }

    /** True when the top edge of this tile can be stood on: platforms are crossed from below. */
    public function isGround(int $column, int $row): bool
    {
        return $this->isSolid($column, $row) && !$this->isSolid($column, $row - 1);
    }

    /** @return int[][] */
    public function rows(): array
    {
        return $this->rows;
    }
}
