<?php

declare(strict_types=1);

namespace Migrator\Cli;

use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Export\Exporter;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

/**
 * WP-CLI commands for Migrator. The CLI path has no web-request timeout, so it
 * is the reliable way to back up or move large sites.
 */
final class Command
{
    /**
     * Export the whole site (database + wp-content) into a single archive.
     *
     * ## OPTIONS
     *
     * [--output=<file>]
     * : Where to write the archive. Defaults to a dated file in the backups folder.
     *
     * ## EXAMPLES
     *
     *     wp migrator export
     *     wp migrator export --output=/tmp/my-site.migrator
     *
     * @param array<int, string>    $args       Positional args (unused).
     * @param array<string, string> $assoc_args Flags.
     */
    public function export(array $args, array $assoc_args): void
    {
        global $wpdb;

        $workspace = new Workspace();
        $workspace->ensure();
        $exporter = new Exporter($workspace, new Dumper($wpdb));

        $destination = $assoc_args['output'] ?? $exporter->defaultDestination();

        \WP_CLI::log('Exporting site…');
        $result = $exporter->export($destination, static function (string $message): void {
            \WP_CLI::log('  ' . $message);
        });

        \WP_CLI::success(sprintf(
            'Exported %d tables and %d files to %s (%s).',
            $result['tables'],
            $result['files'],
            $result['path'],
            size_format($result['bytes']),
        ));
    }
}
