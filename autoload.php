<?php
/**
 * Autoloading: prefer Composer's vendor autoloader (ships the optimized
 * classmap). Fall back to a minimal PSR-4 autoloader so the plugin still boots
 * if vendor/ is somehow absent. Self-contained: no external runtime packages.
 *
 * @package Migrator
 */

declare(strict_types=1);

namespace Migrator;

defined('ABSPATH') || exit;

$migrator_composer = __DIR__ . '/vendor/autoload.php';
if (is_readable($migrator_composer)) {
    require_once $migrator_composer;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix  = 'Migrator\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = substr($class, $len);
    $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});
