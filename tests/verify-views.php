<?php
/**
 * Checks the latest archive includes views and that CRC verification passes on a
 * full read. Run: wp eval-file wp-content/plugins/migrator/tests/verify-views.php
 *
 * @package Migrator
 */

use Migrator\Engine\Archive\Reader;
use Migrator\Engine\Export\Exporter;

$dir   = rtrim((string) WP_CONTENT_DIR, '/') . '/migrator-backups';
$files = array_merge(glob($dir . '/*.migrator') ?: [], glob('/var/www/html/*.migrator') ?: []);
if ([] === $files) {
    echo "FAIL: no archive\n";
    return;
}
usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
$archive = $files[0];
echo "Archive: {$archive}\n";

$fail = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$sql = '';
$crcOk = true;
try {
    $reader = new Reader($archive);
    while (($entry = $reader->nextEntry()) !== null) {
        if (Exporter::DB_ENTRY === $entry->path) {
            $sql = $reader->readContents(); // CRC auto-verified here
        } else {
            $reader->skip();
        }
    }
    $reader->close();
} catch (\RuntimeException $e) {
    $crcOk = false;
    echo '  (exception: ' . $e->getMessage() . ")\n";
}

$check('full read passed CRC verification', $crcOk);
$check('dump contains DROP VIEW for wp_migrator_view', str_contains($sql, 'DROP VIEW IF EXISTS `wp_migrator_view`'));
$check('dump contains CREATE ... VIEW `wp_migrator_view`', (bool) preg_match('/CREATE.*VIEW `wp_migrator_view`/s', $sql));
$check('DEFINER stripped from view', ! str_contains($sql, 'DEFINER='));

echo $fail === 0 ? "\nALL VERIFIED\n" : "\n{$fail} FAILED\n";
