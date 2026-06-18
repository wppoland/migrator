<?php

declare(strict_types=1);

namespace Migrator\Engine\Db;

use Migrator\Engine\Transform\SerializedReplacer;

defined('ABSPATH') || exit;

/**
 * Runs a serialization-safe search-and-replace across every row of every table,
 * used after an import to rewrite the source site's URLs and paths to the
 * target's. Each cell goes through {@see SerializedReplacer}, so serialized
 * values keep correct byte lengths.
 *
 * Rows are updated by primary key in bounded batches. Tables without a single
 * primary key column are skipped (and reported) rather than risk an ambiguous
 * UPDATE.
 */
final class SearchReplace
{
    public function __construct(
        private \wpdb $db,
        private SerializedReplacer $replacer,
        private int $batchSize = 500,
    ) {
    }

    /**
     * @param string[] $tables
     *
     * @return array{tables: int, rows: int, changes: int, skipped: string[]}
     */
    public function run(array $tables): array
    {
        $rowsSeen = 0;
        $changes  = 0;
        $skipped  = [];

        foreach ($tables as $table) {
            $table = (string) $table;
            $pk    = $this->primaryKey($table);
            if (null === $pk) {
                $skipped[] = $table;
                continue;
            }

            $safe   = '`' . str_replace('`', '``', $table) . '`';
            $pkSafe = '`' . str_replace('`', '``', $pk) . '`';
            $offset = 0;

            do {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
                $rows = $this->db->get_results(
                    $this->db->prepare("SELECT * FROM {$safe} ORDER BY {$pkSafe} LIMIT %d OFFSET %d", $this->batchSize, $offset),
                    ARRAY_A
                );
                if (! is_array($rows) || [] === $rows) {
                    break;
                }

                foreach ($rows as $row) {
                    $rowsSeen++;
                    /** @var array<string, scalar|null> $row */
                    $update = [];
                    foreach ($row as $column => $value) {
                        if (! is_string($value) || $column === $pk) {
                            continue;
                        }
                        $before = $this->replacer->replacements();
                        $new    = $this->replacer->replace($value);
                        if ($this->replacer->replacements() > $before && $new !== $value) {
                            $update[$column] = (string) $new;
                        }
                    }

                    if ([] !== $update) {
                        $this->db->update($table, $update, [ $pk => $row[$pk] ]);
                        $changes++;
                    }
                }

                $offset += $this->batchSize;
            } while (count($rows) === $this->batchSize);
        }

        return [
            'tables'  => count($tables) - count($skipped),
            'rows'    => $rowsSeen,
            'changes' => $changes,
            'skipped' => $skipped,
        ];
    }

    private function primaryKey(string $table): ?string
    {
        $safe = '`' . str_replace('`', '``', $table) . '`';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $keys = $this->db->get_results("SHOW KEYS FROM {$safe} WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (! is_array($keys) || count($keys) !== 1) {
            return null; // No PK, or composite — skip to stay safe.
        }

        $column = $keys[0]['Column_name'] ?? null;

        return is_string($column) ? $column : null;
    }
}
