<?php
/**
 * Verifies the most recent export archive by reading it back with the engine.
 * Run inside wp-env:  wp eval-file wp-content/plugins/migrator/tests/verify-export.php
 *
 * @package Migrator
 */

use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Reader;

$dir   = rtrim((string) WP_CONTENT_DIR, '/') . '/migrator-backups';
$files = glob($dir . '/*.migrator') ?: [];
if ([] === $files) {
    echo "FAIL: no archive found\n";
    return;
}
usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
$archive = $files[0];
echo "Archive: {$archive}\n";

$fail = 0;
$check = static function (string $label, bool $cond) use (&$fail): void {
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (! $cond) {
        $fail++;
    }
};

$reader = new Reader($archive);

$manifestJson = null;
$sql          = '';
$fileEntries  = 0;
$dbSeen       = false;
$probeRel     = 'wp-content/plugins/woocommerce/woocommerce.php';
$probeBytes   = null;

while (($entry = $reader->nextEntry()) !== null) {
    if ($entry->isManifest()) {
        $manifestJson = $reader->readContents();
    } elseif ('database.sql' === $entry->path) {
        $dbSeen = true;
        $sql    = $reader->readContents();
    } elseif ($entry->path === $probeRel) {
        $probeBytes = $reader->readContents();
        $fileEntries++;
    } else {
        $fileEntries++;
        $reader->skip();
    }
}
$reader->close();

// Manifest.
$check('manifest present', is_string($manifestJson));
$manifest = Manifest::fromJson((string) $manifestJson);
$check('manifest is a supported format', $manifest->isSupported());
$check('manifest siteUrl matches this site', $manifest->get('homeUrl') === get_option('home'));
$check('manifest records table list', is_array($manifest->get('tables')) && count($manifest->get('tables')) === 46);
$check('manifest records WooCommerce active', true === $manifest->get('wooActive'));

// Database dump.
$check('database.sql present', $dbSeen);
$check('SQL has dump header', str_contains($sql, '-- Migrator SQL dump'));
$check('SQL creates wp_options', str_contains($sql, 'CREATE TABLE `wp_options`') || str_contains($sql, 'CREATE TABLE wp_options'));
$check('SQL creates wp_posts', (bool) preg_match('/CREATE TABLE `?wp_posts`?/', $sql));
$check('SQL inserts into wp_options', (bool) preg_match('/INSERT INTO `wp_options`/', $sql));
$check('SQL contains the site home URL (for import-time replace)', str_contains($sql, (string) get_option('home')));
$check('SQL ends re-enabling FK checks', str_contains($sql, 'SET FOREIGN_KEY_CHECKS=1;'));

// Files.
$check('file entry count matches export (10540)', $fileEntries === 10540);
$check('probe file (woocommerce.php) extracted', is_string($probeBytes));
if (is_string($probeBytes)) {
    $original = (string) WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
    $check('probe file bytes identical to source', md5($probeBytes) === md5_file($original));
}

echo $fail === 0 ? "\nALL VERIFIED\n" : "\n{$fail} CHECKS FAILED\n";
