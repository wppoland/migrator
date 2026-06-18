<?php
/**
 * Uninstall cleanup. Removes Migrator's options and deletes its private working
 * directory (archives and any in-progress job state). Nothing is left behind.
 *
 * @package Migrator
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('migrator_settings');
delete_option('migrator_db_version');

// Remove the backups directory, recursively. Defined inline so uninstall has no
// dependency on the (already-unloaded) plugin autoloader.
$migrator_dir = rtrim((string) WP_CONTENT_DIR, '/') . '/migrator-backups';

if (is_dir($migrator_dir)) {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($migrator_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($migrator_dir);
}
