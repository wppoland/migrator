<?php

declare(strict_types=1);

namespace Migrator\Engine\Archive;

defined('ABSPATH') || exit;

/**
 * Immutable description of one item inside an archive: its relative path, byte
 * size, modification time, kind and an optional content checksum. This is the
 * decoded form of the per-entry header the {@see Writer} and {@see Reader}
 * exchange.
 *
 * The checksum (crc32b, hex) lets the reader detect a truncated or corrupted
 * entry before it is written over a live site. It is optional: entries written
 * by the resumable path may omit it, and a reader treats a missing checksum as
 * "not verifiable" rather than an error.
 */
final class Entry
{
    public const TYPE_FILE     = 'file';
    public const TYPE_MANIFEST = 'manifest';

    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly int $mtime,
        public readonly string $type = self::TYPE_FILE,
        public readonly ?string $crc = null,
    ) {
    }

    /**
     * @return array{p: string, s: int, m: int, t: string, c?: string}
     */
    public function toHeader(): array
    {
        $header = [
            'p' => $this->path,
            's' => $this->size,
            'm' => $this->mtime,
            't' => $this->type,
        ];

        if (null !== $this->crc) {
            $header['c'] = $this->crc;
        }

        return $header;
    }

    /**
     * @param array<string, mixed> $header
     */
    public static function fromHeader(array $header): self
    {
        $crc = isset($header['c']) ? (string) $header['c'] : null;

        return new self(
            (string) ($header['p'] ?? ''),
            (int) ($header['s'] ?? 0),
            (int) ($header['m'] ?? 0),
            (string) ($header['t'] ?? self::TYPE_FILE),
            $crc,
        );
    }

    public function isManifest(): bool
    {
        return self::TYPE_MANIFEST === $this->type;
    }
}
