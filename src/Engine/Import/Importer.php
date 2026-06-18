<?php

declare(strict_types=1);

namespace Migrator\Engine\Import;

use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Reader;
use Migrator\Engine\Db\SearchReplace;
use Migrator\Engine\Db\SqlExecutor;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Transform\SerializedReplacer;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

/**
 * Restores an archive onto the current site.
 *
 * The order matters: read the manifest, capture *this* site's URLs and paths
 * BEFORE the import (the import overwrites wp_options with the source's values),
 * import the SQL, then rewrite the source's URLs/paths to this site's with a
 * serialization-safe pass. Files are extracted last.
 *
 * This is the straight-through (WP-CLI) importer. It never extracts over its own
 * plugin directory or the backups folder, so it cannot clobber the code that is
 * currently running.
 */
final class Importer
{
    private const PROTECTED_PREFIXES = [
        'wp-content/plugins/migrator/',
        'wp-content/plugins/migrator-pro/',
        'wp-content/migrator-backups/',
    ];

    public function __construct(
        private Workspace $workspace,
        private \wpdb $db,
    ) {
    }

    /**
     * @param callable(string):void|null $log
     *
     * @return array{tables: int, statements: int, replaced: int, files: int}
     */
    public function import(string $archivePath, bool $importFiles = true, ?callable $log = null): array
    {
        $log ??= static function (string $m): void {};

        $reader = new Reader($archivePath);

        $first = $reader->nextEntry();
        if (null === $first || ! $first->isManifest()) {
            throw new \RuntimeException('Migrator: archive has no manifest (is this a Migrator archive?).');
        }
        $manifest = Manifest::fromJson($reader->readContents());
        if (! $manifest->isSupported()) {
            throw new \RuntimeException('Migrator: this archive was made by a newer version of Migrator.');
        }

        // Capture the target's identity BEFORE the DB import overwrites it.
        $target = [
            'home'    => (string) get_option('home'),
            'siteurl' => (string) get_option('siteurl'),
            'content' => untrailingslashit((string) WP_CONTENT_DIR),
            'abspath' => untrailingslashit((string) ABSPATH),
        ];
        $source = [
            'home'    => (string) $manifest->get('homeUrl'),
            'siteurl' => (string) $manifest->get('siteUrl'),
            'content' => (string) $manifest->get('contentDir'),
            'abspath' => (string) $manifest->get('abspath'),
        ];

        $statements = 0;
        $replaced   = 0;
        $tablesRepl = 0;
        $files      = 0;

        while (($entry = $reader->nextEntry()) !== null) {
            if (Exporter::DB_ENTRY === $entry->path) {
                $statements = $this->importDatabase($reader, $log);

                [$from, $to] = $this->replacements($source, $target);
                if ([] !== $from) {
                    /** @var string[] $tables */
                    $tables   = array_map('strval', (array) $manifest->get('tables'));
                    $search   = new SearchReplace($this->db, new SerializedReplacer($from, $to));
                    $result   = $search->run($tables);
                    $replaced = $result['changes'];
                    $tablesRepl = $result['tables'];
                    $log(sprintf('Rewrote URLs/paths in %d rows across %d tables.', $replaced, $tablesRepl));
                }
            } elseif (str_starts_with($entry->path, 'wp-content/')) {
                if ($importFiles && $this->extract($entry->path, $reader)) {
                    $files++;
                } else {
                    $reader->skip();
                }
            } else {
                $reader->skip();
            }
        }

        $reader->close();
        wp_cache_flush();

        return [
            'tables'     => $tablesRepl,
            'statements' => $statements,
            'replaced'   => $replaced,
            'files'      => $files,
        ];
    }

    private function importDatabase(Reader $reader, callable $log): int
    {
        $tmp    = $this->workspace->path('import-' . wp_generate_password(8, false) . '.sql');
        $handle = fopen($tmp, 'wb');
        if (false === $handle) {
            throw new \RuntimeException('Migrator: cannot open temp file for SQL import.');
        }
        $reader->streamTo(static function (string $chunk) use ($handle): void {
            fwrite($handle, $chunk);
        });
        fclose($handle);

        $sql = (string) file_get_contents($tmp);
        wp_delete_file($tmp);

        $count = (new SqlExecutor($this->db))->run($sql);
        $log(sprintf('Imported database (%d statements).', $count));

        return $count;
    }

    /**
     * @return bool True if the file was written, false if it was skipped.
     */
    private function extract(string $archivePath, Reader $reader): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($archivePath, $prefix)) {
                return false;
            }
        }

        $relative = substr($archivePath, strlen('wp-content/'));
        $target   = untrailingslashit((string) WP_CONTENT_DIR) . '/' . $relative;

        $dir = dirname($target);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $handle = fopen($target, 'wb');
        if (false === $handle) {
            return false;
        }
        $reader->streamTo(static function (string $chunk) use ($handle): void {
            fwrite($handle, $chunk);
        });
        fclose($handle);

        return true;
    }

    /**
     * Build ordered from/to replacement pairs. Longer paths first so a parent
     * path never partially rewrites a child.
     *
     * @param array{home:string,siteurl:string,content:string,abspath:string} $source
     * @param array{home:string,siteurl:string,content:string,abspath:string} $target
     *
     * @return array{0: string[], 1: string[]}
     */
    private function replacements(array $source, array $target): array
    {
        $from = [];
        $to   = [];
        foreach (['home', 'siteurl', 'content', 'abspath'] as $key) {
            if ('' !== $source[$key] && $source[$key] !== $target[$key]) {
                $from[] = $source[$key];
                $to[]   = $target[$key];
            }
        }

        return [$from, $to];
    }
}
