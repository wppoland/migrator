<?php

declare(strict_types=1);

namespace Migrator;

defined('ABSPATH') || exit;

/**
 * Minimal dependency-injection container: lazy singletons + binds.
 */
final class Container
{
    /** @var array<string, \Closure> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function singleton(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (! isset($this->factories[$id])) {
            throw new \RuntimeException(esc_html(sprintf('Service "%s" is not registered.', $id)));
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}
