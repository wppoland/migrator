<?php

declare(strict_types=1);

namespace Migrator\Engine\Db;

defined('ABSPATH') || exit;

/**
 * Streams a portable SQL dump of the site's database.
 *
 * Rows are read in bounded batches (never the whole table at once) and written
 * straight to a stream, so a large table never has to sit in memory. Values are
 * dumped verbatim — search-and-replace happens at import time so one archive can
 * be restored onto any domain.
 *
 * The SQL is plain mysqldump-style: DROP + CREATE per table, then batched
 * INSERTs, wrapped in FOREIGN_KEY_CHECKS=0 so import order never matters.
 */
final class Dumper
{
    private const MAX_INSERT_BYTES = 5_242_880; // Flush an INSERT once it reaches ~5 MiB.

    public function __construct(
        private \wpdb $db,
        private int $batchSize = 1000,
    ) {
    }

    /**
     * Tables belonging to this site (its prefix). Pass an explicit list to dump
     * a subset (selective backup).
     *
     * @return string[]
     */
    public function tables(): array
    {
        $like = $this->db->esc_like($this->db->prefix) . '%';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db->get_col($this->db->prepare('SHOW TABLES LIKE %s', $like));

        return array_map('strval', $rows);
    }

    /**
     * Dump every given table to a writable stream resource.
     *
     * @param string[] $tables
     * @param resource $handle
     */
    public function dumpAll(array $tables, $handle): void
    {
        $this->write($handle, "-- Migrator SQL dump\n");
        $this->write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $this->write($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $this->dumpTable((string) $table, $handle);
        }

        $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /**
     * Dump a single table: structure then data.
     *
     * @param resource $handle
     */
    public function dumpTable(string $table, $handle): void
    {
        $safe = '`' . str_replace('`', '``', $table) . '`';

        $this->write($handle, "DROP TABLE IF EXISTS {$safe};\n");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $create = $this->db->get_row("SHOW CREATE TABLE {$safe}", ARRAY_N);
        if (is_array($create) && isset($create[1])) {
            $this->write($handle, $create[1] . ";\n\n");
        }

        $this->dumpRows($table, $safe, $handle);
        $this->write($handle, "\n");
    }

    /**
     * Stream a table's rows as batched INSERT statements.
     *
     * @param resource $handle
     */
    private function dumpRows(string $table, string $safe, $handle): void
    {
        $offset  = 0;
        $insert  = '';
        $started = false;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->db->get_results(
                $this->db->prepare("SELECT * FROM {$safe} LIMIT %d OFFSET %d", $this->batchSize, $offset),
                ARRAY_A,
            );

            if (! is_array($rows) || [] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                /** @var array<string, scalar|null> $row */
                $values = '(' . $this->rowValues($row) . ')';

                if (! $started) {
                    $insert  = $this->insertPrefix($safe, array_keys($row)) . $values;
                    $started = true;
                } else {
                    $insert .= ',' . $values;
                }

                if (strlen($insert) >= self::MAX_INSERT_BYTES) {
                    $this->write($handle, $insert . ";\n");
                    $insert  = '';
                    $started = false;
                }
            }

            $offset += $this->batchSize;
        } while (count($rows) === $this->batchSize);

        if ($started && '' !== $insert) {
            $this->write($handle, $insert . ";\n");
        }
    }

    /**
     * @param string[] $columns
     */
    private function insertPrefix(string $safe, array $columns): string
    {
        $cols = array_map(
            static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`',
            $columns,
        );

        return "INSERT INTO {$safe} (" . implode(',', $cols) . ') VALUES ';
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private function rowValues(array $row): string
    {
        $out = [];
        foreach ($row as $value) {
            if (null === $value) {
                $out[] = 'NULL';
            } else {
                $out[] = "'" . $this->db->_real_escape((string) $value) . "'";
            }
        }

        return implode(',', $out);
    }

    /**
     * @param resource $handle
     */
    private function write($handle, string $sql): void
    {
        if (false === fwrite($handle, $sql)) {
            throw new \RuntimeException('Migrator: failed writing SQL dump (disk full?).');
        }
    }
}
