<?php

declare(strict_types=1);

namespace Migrator\Engine\Transform;

defined('ABSPATH') || exit;

/**
 * Search-and-replace that is safe across PHP-serialized data.
 *
 * A naive `str_replace` on a database dump corrupts serialized values: changing
 * "https://old" to "https://new.example" leaves the byte-length prefix
 * (`s:10:"https://old"`) pointing at the wrong length, and WordPress then fails
 * to unserialize the value. This walks the *decoded* structure instead —
 * unserialize, replace inside, re-serialize — so lengths are always recomputed
 * correctly. It never rewrites raw serialized strings with a regex.
 *
 * Safety rules:
 *  - Serialized blobs containing an object of an *unknown* class
 *    (`__PHP_Incomplete_Class`) are left completely untouched, because
 *    re-serializing one would corrupt it. We skip the replacement rather than
 *    risk the data.
 *  - JSON that decodes to an array/object is recursed (WooCommerce stores
 *    serialized strings inside JSON meta), but only re-encoded when something
 *    inside actually changed — untouched JSON is returned byte-for-byte.
 *
 * Usage:
 *   $r   = new SerializedReplacer($fromUrl, $toUrl);          // or arrays
 *   $new = $r->replace($cellValue);
 *   $n   = $r->replacements();  // count, for dry-run reporting
 */
final class SerializedReplacer
{
    /** @var string[] */
    private array $from;

    /** @var string[] */
    private array $to;

    private int $count = 0;

    /**
     * @param string|string[] $from
     * @param string|string[] $to
     */
    public function __construct(string|array $from, string|array $to)
    {
        $this->from = array_values((array) $from);
        $this->to   = array_values((array) $to);
    }

    /**
     * Apply the replacement to one value (typically a database cell), returning
     * the transformed value with serialized lengths kept consistent.
     */
    public function replace(mixed $data): mixed
    {
        return $this->process($data, false);
    }

    /**
     * Number of individual string replacements performed since construction.
     * Use it to drive a dry-run report or to decide whether a row changed.
     */
    public function replacements(): int
    {
        return $this->count;
    }

    public function resetCount(): void
    {
        $this->count = 0;
    }

    /**
     * @param bool $serialised When true, the caller unwrapped a serialized
     *                         string to reach this value, so the result is
     *                         re-serialized before returning.
     */
    private function process(mixed $data, bool $serialised): mixed
    {
        if (is_string($data) && '' !== $data && $this->isSerialized($data) && false !== ($un = @unserialize($data))) {
            if (! $this->hasIncompleteClass($un)) {
                $data = $this->process($un, true);
            }
            // Incomplete class present: leave the original serialized string as-is.
        } elseif (is_array($data)) {
            $tmp = [];
            foreach ($data as $key => $value) {
                $tmp[$key] = $this->process($value, false);
            }
            $data = $tmp;
        } elseif (is_object($data)) {
            if (! ($data instanceof \__PHP_Incomplete_Class)) {
                $clone = clone $data;
                foreach (get_object_vars($data) as $key => $value) {
                    $clone->$key = $this->process($value, false);
                }
                $data = $clone;
            }
        } elseif (is_string($data)) {
            $data = $this->replaceInString($data);
        }

        if ($serialised) {
            return serialize($data);
        }

        return $data;
    }

    /**
     * Replace inside a plain string. If the string is JSON that decodes to an
     * array/object, recurse it (to fix any serialized strings nested inside) and
     * re-encode only when a replacement actually happened.
     */
    private function replaceInString(string $data): string
    {
        $trimmed = ltrim($data);
        if ('' !== $trimmed && ('{' === $trimmed[0] || '[' === $trimmed[0])) {
            $decoded = json_decode($data, true);
            if (is_array($decoded) && JSON_ERROR_NONE === json_last_error()) {
                $before    = $this->count;
                $processed = $this->process($decoded, false);
                if ($this->count > $before) {
                    $encoded = wp_json_encode($processed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (is_string($encoded)) {
                        return $encoded;
                    }
                }
                // Nothing changed inside (count unchanged): the plain replace
                // below would also find nothing, so the string is returned intact.
            }
        }

        $replaced     = 0;
        $out          = str_replace($this->from, $this->to, $data, $replaced);
        $this->count += $replaced;

        return $out;
    }

    private function hasIncompleteClass(mixed $data): bool
    {
        if ($data instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if (is_array($data)) {
            foreach ($data as $value) {
                if ($this->hasIncompleteClass($value)) {
                    return true;
                }
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $value) {
                if ($this->hasIncompleteClass($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a string is PHP-serialized data. Faithful port of WordPress core's
     * is_serialized(), so the engine stays portable outside WordPress (tests,
     * future package extraction) without depending on WP being loaded.
     */
    private function isSerialized(string $data): bool
    {
        $data = trim($data);

        if ('N;' === $data) {
            return true;
        }
        if (strlen($data) < 4) {
            return false;
        }
        if (':' !== $data[1]) {
            return false;
        }

        $semicolon = strpos($data, ';');
        $brace     = strpos($data, '}');
        if (false === $semicolon && false === $brace) {
            return false;
        }
        if (false !== $semicolon && $semicolon < 3) {
            return false;
        }
        if (false !== $brace && $brace < 4) {
            return false;
        }

        $token = $data[0];
        switch ($token) {
            case 's':
                if ('"' !== substr($data, -2, 1)) {
                    return false;
                }
                // fall through.
            case 'a':
            case 'O':
            case 'E':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;/", $data);
        }

        return false;
    }
}
