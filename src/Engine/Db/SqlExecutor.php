<?php

declare(strict_types=1);

namespace Migrator\Engine\Db;

defined('ABSPATH') || exit;

/**
 * Executes a SQL dump statement by statement.
 *
 * Splitting on ";" naively would break on any value that contains a semicolon,
 * so this tokenises the SQL: it tracks whether it is inside a single-quoted
 * string (honouring backslash escapes) and only treats a ";" outside a string
 * as a statement boundary. That makes it safe for arbitrary serialized/binary
 * data in INSERTs.
 */
final class SqlExecutor
{
    public function __construct(private \wpdb $db)
    {
    }

    /**
     * Run every statement in $sql. Returns the number executed.
     */
    public function run(string $sql): int
    {
        $count = 0;
        foreach ($this->statements($sql) as $statement) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $result = $this->db->query($statement);
            if (false === $result) {
                throw new \RuntimeException(esc_html(sprintf(
                    'Migrator: SQL import failed near: %s',
                    substr($statement, 0, 120)
                )));
            }
            $count++;
        }

        return $count;
    }

    /**
     * Split a SQL string into individual statements.
     *
     * @return \Generator<string>
     */
    public function statements(string $sql): \Generator
    {
        $length = strlen($sql);
        $buffer = '';
        $inStr  = false;

        for ($i = 0; $i < $length; $i++) {
            $char    = $sql[$i];
            $buffer .= $char;

            if ($inStr) {
                if ('\\' === $char && $i + 1 < $length) {
                    // Escaped character — consume the next byte verbatim.
                    $buffer .= $sql[$i + 1];
                    $i++;
                } elseif ("'" === $char) {
                    $inStr = false;
                }
                continue;
            }

            if ("'" === $char) {
                $inStr = true;
            } elseif (';' === $char) {
                $statement = $this->clean($buffer);
                if ('' !== $statement) {
                    yield $statement;
                }
                $buffer = '';
            }
        }

        $statement = $this->clean($buffer);
        if ('' !== $statement) {
            yield $statement;
        }
    }

    /**
     * Trim a raw buffer into an executable statement, dropping the trailing
     * semicolon, blank lines and whole-line `--` comments.
     */
    private function clean(string $buffer): string
    {
        $lines = explode("\n", $buffer);
        $kept  = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '-- ')) {
                continue;
            }
            $kept[] = $line;
        }

        return rtrim(trim(implode("\n", $kept)), ';');
    }
}
