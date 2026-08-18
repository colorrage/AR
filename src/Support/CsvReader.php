<?php

namespace ColorrageAR\Autoresponder\Support;

use Generator;
use RuntimeException;
use SplFileObject;

/**
 * Streams a CSV file as mapped rows.
 *
 * Reads no config and touches no database — the caller passes everything in, which is
 * what keeps this unit-testable in isolation.
 *
 * Four behaviours of SplFileObject are load-bearing here, each verified by running code
 * rather than read from documentation (see T5's technical plan, §Codebase findings):
 *
 *  1. The UTF-8 BOM is NOT stripped. With one present the first header key reads
 *     "\xEF\xBB\xBFemail", so a plain `email` lookup fails on exactly the files Excel
 *     produces.
 *  2. The iterator yields `false` at EOF, and blank lines yield `[null]` without
 *     SKIP_EMPTY. Neither is a data record; letting one through would inflate the row
 *     count and break the reconciliation identity that is this feature's acceptance proof.
 *  3. Line numbering needs the pure-iterator form AND a correction. Mixing `key()` with
 *     direct `fgetcsv()` calls reports garbage. But even in `foreach ($file as $key =>
 *     $row)` the key advances only once per parsed record, so it does NOT count newlines
 *     embedded inside quoted fields and drifts behind the true physical line. It does
 *     count blank lines that SKIP_EMPTY discards. So the true 1-based start line is
 *     `$key + <embedded newlines in all prior records> + 1`, which is what this class
 *     tracks. (T5's technical plan asserted the raw key was sufficient; it is not. Caught
 *     by CsvReaderTest::test_quoted_field_may_contain_a_newline_and_line_numbers_account_for_it.)
 *  4. `setCsvControl()` deprecates an implicit $escape. Passing "" both silences that and
 *     is the correct RFC 4180 semantic, since RFC 4180 has no backslash escape.
 */
class CsvReader
{
    /**
     * @param  string  $path  Path to the CSV file.
     * @param  array<string, string|null>  $columns  Field name => CSV header to look for.
     *                                               `email` is required; `name` and
     *                                               `locale` may be null to ignore them.
     * @param  string  $delimiter  Field delimiter. Semicolon is common in European exports.
     */
    public function __construct(
        private readonly string $path,
        private readonly array $columns,
        private readonly string $delimiter = ',',
    ) {}

    /**
     * Validate everything that makes the whole import impossible, before a caller writes
     * anything.
     *
     * These are import-level failures, not per-row rejections: an unreadable file, an
     * empty file, or a header row missing the mapped email column. Callers run this first
     * so such a file fails once, cleanly, rather than as a flood of invalid rows.
     *
     * @throws RuntimeException
     */
    public function preflight(): void
    {
        $this->resolveIndexes($this->readHeader());
    }

    /**
     * Stream one entry per data record, header excluded.
     *
     * Lazy by construction: the file is never materialised as an array, so memory does
     * not grow with file length.
     *
     * @return Generator<int, array{line: int, ordinal: int, fields: array<string, string|null>, columnCountMatches: bool, raw: array<int, string|null>}>
     *
     * @throws RuntimeException
     */
    public function rows(): Generator
    {
        $file = $this->open();

        $indexes = null;
        $headerCount = 0;
        $ordinal = 0;
        $embeddedNewlines = 0;

        foreach ($file as $key => $row) {
            if (! $this->isRecord($row)) {
                continue;
            }

            if ($indexes === null) {
                $row[0] = $this->stripBom((string) $row[0]);
                $indexes = $this->resolveIndexes($row);
                $headerCount = count($row);
                $embeddedNewlines += $this->countEmbeddedNewlines($row);

                continue;
            }

            $ordinal++;

            yield [
                // The physical line the record STARTS on, 1-based so it matches what a text
                // editor shows the operator. See finding 3 in the class docblock for why the
                // iterator key alone is not this number. It diverges from `ordinal` as soon
                // as a quoted field contains a newline — callers need both.
                'line' => $key + $embeddedNewlines + 1,
                'ordinal' => $ordinal,
                'fields' => $this->mapFields($row, $indexes),
                'columnCountMatches' => count($row) === $headerCount,
                'raw' => $row,
            ];

            $embeddedNewlines += $this->countEmbeddedNewlines($row);
        }

        if ($indexes === null) {
            throw new RuntimeException("CSV file has no header row: {$this->path}");
        }
    }

    // ── Internals ────────────────────────────────────────────────────

    private function open(): SplFileObject
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException("CSV file not found or not readable: {$this->path}");
        }

        $file = new SplFileObject($this->path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($this->delimiter, '"', '');

        return $file;
    }

    /**
     * @return array<int, string|null>
     */
    private function readHeader(): array
    {
        foreach ($this->open() as $row) {
            if (! $this->isRecord($row)) {
                continue;
            }

            $row[0] = $this->stripBom((string) $row[0]);

            return $row;
        }

        throw new RuntimeException("CSV file is empty or has no header row: {$this->path}");
    }

    /**
     * Map each configured field to its column index, matching header names
     * case-insensitively and whitespace-trimmed. Source columns that map to nothing are
     * ignored rather than treated as errors.
     *
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function resolveIndexes(array $header): array
    {
        $lookup = [];

        foreach ($header as $index => $name) {
            $lookup[$this->normalizeHeader((string) $name)] = $index;
        }

        $indexes = [];

        foreach ($this->columns as $field => $headerName) {
            if ($headerName === null || $headerName === '') {
                continue;
            }

            $key = $this->normalizeHeader($headerName);

            if (array_key_exists($key, $lookup)) {
                $indexes[$field] = $lookup[$key];
            }
        }

        if (! array_key_exists('email', $indexes)) {
            $expected = $this->columns['email'] ?? 'email';

            throw new RuntimeException(
                "CSV header does not contain the email column \"{$expected}\": {$this->path}"
            );
        }

        return $indexes;
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $indexes
     * @return array<string, string|null>
     */
    private function mapFields(array $row, array $indexes): array
    {
        $fields = [];

        foreach (array_keys($this->columns) as $field) {
            $fields[$field] = isset($indexes[$field]) ? ($row[$indexes[$field]] ?? null) : null;
        }

        return $fields;
    }

    /**
     * Reject the EOF sentinel and blank-line artefacts described in the class docblock.
     *
     * @param  mixed  $row
     */
    private function isRecord($row): bool
    {
        if (! is_array($row)) {
            return false;
        }

        if ($row === [] || $row === [null]) {
            return false;
        }

        // A line of only whitespace parses to a single empty-ish field.
        return ! (count($row) === 1 && trim((string) ($row[0] ?? '')) === '');
    }

    /**
     * Newlines inside quoted fields, which the iterator key does not count.
     *
     * @param  array<int, string|null>  $row
     */
    private function countEmbeddedNewlines(array $row): int
    {
        $total = 0;

        foreach ($row as $value) {
            $total += substr_count((string) $value, "\n");
        }

        return $total;
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    private function normalizeHeader(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
