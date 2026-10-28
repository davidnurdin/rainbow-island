<?php

namespace App\World;

/**
 * One of the seven islands (assets/data/islands.json): 4 rounds each, the 4th one ends with a boss.
 * Each island has its own background tiles, overlay tiles (rainbows, items) and sprite sheet.
 */
final class Island
{
    /**
     * @param int $solidFrom a background tile is solid when (tile & 0x7F) >= this value
     * @param array<int, array{frames: int[], flies: bool}> $enemies enemy kinds by map object type
     */
    public function __construct(
        public readonly int $number,
        public readonly string $name,
        public readonly int $solidFrom,
        public readonly array $enemies,
        public readonly int $bossFrame,
        /** The boss frame is its left half only (the right half is the same picture mirrored). */
        public readonly bool $bossMirrored,
    ) {
    }

    public static function fromArray(int $number, array $data): self
    {
        $enemies = [];
        foreach ($data['enemies'] as $type => $kind) {
            $enemies[(int)$type] = ['frames' => $kind['frames'], 'flies' => (bool)$kind['flies']];
        }
        return new self($number, $data['name'], $data['solidFrom'], $enemies, $data['bossFrame'], (bool)$data['bossMirrored']);
    }

    /** The enemy kind of a map object type (types the island does not know become its first kind). */
    public function enemy(int $type): array
    {
        return $this->enemies[$type] ?? $this->enemies[array_key_first($this->enemies)];
    }
}
