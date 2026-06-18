<?php
/**
 * Service wiring. Returns a closure that registers every service in the
 * container. Keep factories thin: they construct, they do not do work.
 *
 * @package Migrator
 */

declare(strict_types=1);

use Migrator\Container;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

return static function (Container $c): void {
    $c->singleton(Workspace::class, static fn (): Workspace => new Workspace());
};
