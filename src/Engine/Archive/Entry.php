<?php

declare(strict_types=1);

namespace Migrator\Engine\Archive;

defined('ABSPATH') || exit;

/**
 * Immutable description of one item inside an archive: its relative path, byte
 * size, modification time and kind. This is the decoded form of the per-entry
 * header the {@see Writer} and {@see Reader} exchange.
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
    ) {
    }

    /**
     * @return array{p: string, s: int, m: int, t: string}
     */
    public function toHeader(): array
    {
        return [
            'p' => $this->path,
            's' => $this->size,
            'm' => $this->mtime,
            't' => $this->type,
        ];
    }

    /**
     * @param array<string, mixed> $header
     */
    public static function fromHeader(array $header): self
    {
        return new self(
            (string) ($header['p'] ?? ''),
            (int) ($header['s'] ?? 0),
            (int) ($header['m'] ?? 0),
            (string) ($header['t'] ?? self::TYPE_FILE),
        );
    }

    public function isManifest(): bool
    {
        return self::TYPE_MANIFEST === $this->type;
    }
}
