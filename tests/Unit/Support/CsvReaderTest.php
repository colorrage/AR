<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Support;

use ColorrageAR\Autoresponder\Support\CsvReader;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Generator;
use RuntimeException;

class CsvReaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * Fixtures are written byte-for-byte at runtime rather than checked in, so the BOM and
     * embedded-newline cases cannot be silently normalised by an editor.
     */
    private function fixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ar_csv_') . '.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<string, string|null>  $columns
     * @return list<array<string, mixed>>
     */
    private function read(string $path, ?array $columns = null, string $delimiter = ','): array
    {
        $columns ??= ['email' => 'email', 'name' => 'name', 'locale' => 'locale'];

        return iterator_to_array((new CsvReader($path, $columns, $delimiter))->rows(), false);
    }

    // ── Quoting ──────────────────────────────────────────────────────

    public function test_quoted_field_may_contain_the_delimiter(): void
    {
        $path = $this->fixture("email,name\na@example.com,\"Doe, John\"\n");

        $rows = $this->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('Doe, John', $rows[0]['fields']['name']);
        $this->assertTrue($rows[0]['columnCountMatches']);
    }

    public function test_escaped_double_quote_reads_back_as_one_quote(): void
    {
        $path = $this->fixture("email,name\na@example.com,\"Say \"\"hi\"\"\"\n");

        $rows = $this->read($path);

        $this->assertSame('Say "hi"', $rows[0]['fields']['name']);
    }

    public function test_quoted_field_may_contain_a_newline_and_line_numbers_account_for_it(): void
    {
        $path = $this->fixture("email,name\na@example.com,\"Line1\nLine2\"\nb@example.com,Plain\n");

        $rows = $this->read($path);

        $this->assertCount(2, $rows);
        $this->assertSame("Line1\nLine2", $rows[0]['fields']['name']);

        // The first record starts on line 2 and spans lines 2-3, so the second record
        // starts on line 4. `ordinal` stays 1 and 2 throughout — the divergence between
        // the two numbers is exactly why both are reported.
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame(1, $rows[0]['ordinal']);
        $this->assertSame(4, $rows[1]['line']);
        $this->assertSame(2, $rows[1]['ordinal']);
    }

    public function test_line_numbers_survive_blank_lines_and_embedded_newlines_together(): void
    {
        // The interaction is the trap: the iterator key counts blank lines that SKIP_EMPTY
        // discards, but does NOT count newlines inside quoted fields. Only one of the two
        // needs correcting, and getting that backwards silently mis-numbers every
        // subsequent rejected row.
        //
        // line 1: header
        // line 2: record 1 opens, spans to line 3
        // line 4: blank
        // line 5: record 2
        $path = $this->fixture("email,name\na@example.com,\"Line1\nLine2\"\n\nb@example.com,Bob\n");

        $rows = $this->read($path);

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame(5, $rows[1]['line']);
        $this->assertSame([1, 2], array_column($rows, 'ordinal'));
    }

    public function test_line_numbers_account_for_multiple_embedded_newlines(): void
    {
        // line 2: record 1 opens, spans lines 2-4
        // line 5: record 2
        $path = $this->fixture("email,name\na@example.com,\"A\nB\nC\"\nb@example.com,Bob\n");

        $rows = $this->read($path);

        $this->assertSame("A\nB\nC", $rows[0]['fields']['name']);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame(5, $rows[1]['line']);
    }

    // ── Encoding and delimiters ──────────────────────────────────────

    public function test_utf8_bom_does_not_break_header_resolution(): void
    {
        $path = $this->fixture("\xEF\xBB\xBFemail,name\na@example.com,Ann\n");

        $rows = $this->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('a@example.com', $rows[0]['fields']['email']);
        $this->assertSame('Ann', $rows[0]['fields']['name']);
    }

    public function test_semicolon_delimiter_is_supported(): void
    {
        $path = $this->fixture("email;name\na@example.com;Ann\n");

        $rows = $this->read($path, null, ';');

        $this->assertSame('a@example.com', $rows[0]['fields']['email']);
        $this->assertSame('Ann', $rows[0]['fields']['name']);
    }

    public function test_bom_and_semicolon_together(): void
    {
        // The shape a European Excel export actually arrives in.
        $path = $this->fixture("\xEF\xBB\xBFe-mail;full name\na@example.com;Ann\n");

        $rows = $this->read($path, ['email' => 'e-mail', 'name' => 'full name', 'locale' => null], ';');

        $this->assertCount(1, $rows);
        $this->assertSame('a@example.com', $rows[0]['fields']['email']);
        $this->assertSame('Ann', $rows[0]['fields']['name']);
        $this->assertNull($rows[0]['fields']['locale']);
    }

    // ── Row accounting ───────────────────────────────────────────────

    public function test_blank_interior_line_is_skipped_and_not_counted(): void
    {
        $path = $this->fixture("email,name\na@example.com,Ann\n\nb@example.com,Bob\n");

        $rows = $this->read($path);

        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], array_column($rows, 'ordinal'));
    }

    public function test_trailing_newline_produces_no_phantom_record(): void
    {
        // The EOF sentinel would otherwise arrive as a spurious record and inflate the
        // row count, which is what breaks the reconciliation identity downstream.
        $withTrailing = $this->fixture("email,name\na@example.com,Ann\n");
        $withoutTrailing = $this->fixture("email,name\na@example.com,Ann");

        $this->assertCount(1, $this->read($withTrailing));
        $this->assertCount(1, $this->read($withoutTrailing));
    }

    public function test_multiple_trailing_newlines_produce_no_phantom_records(): void
    {
        $path = $this->fixture("email,name\na@example.com,Ann\n\n\n");

        $this->assertCount(1, $this->read($path));
    }

    public function test_header_only_file_yields_no_records(): void
    {
        $path = $this->fixture("email,name\n");

        $this->assertCount(0, $this->read($path));
    }

    public function test_column_count_mismatch_is_surfaced_not_padded(): void
    {
        $path = $this->fixture(
            "email,name,locale\na@example.com,Ann\nb@example.com,Bob,en\nc@example.com,Cid,en,extra\n"
        );

        $rows = $this->read($path);

        $this->assertCount(3, $rows);
        $this->assertFalse($rows[0]['columnCountMatches'], 'too few columns');
        $this->assertTrue($rows[1]['columnCountMatches']);
        $this->assertFalse($rows[2]['columnCountMatches'], 'too many columns');

        // A short row must not throw on the missing index either.
        $this->assertNull($rows[0]['fields']['locale']);
    }

    // ── Header mapping ───────────────────────────────────────────────

    public function test_headers_resolve_by_name_regardless_of_case_whitespace_or_position(): void
    {
        $path = $this->fixture("  Full Name , Language ,  E-Mail Address \nAnn,en,a@example.com\n");

        $rows = $this->read($path, [
            'email' => 'e-mail address',
            'name' => 'FULL NAME',
            'locale' => 'language',
        ]);

        $this->assertSame('a@example.com', $rows[0]['fields']['email']);
        $this->assertSame('Ann', $rows[0]['fields']['name']);
        $this->assertSame('en', $rows[0]['fields']['locale']);
    }

    public function test_unmapped_columns_are_ignored(): void
    {
        $path = $this->fixture("email,phone,notes\na@example.com,0700,whatever\n");

        $rows = $this->read($path, ['email' => 'email', 'name' => null, 'locale' => null]);

        $this->assertSame('a@example.com', $rows[0]['fields']['email']);
        $this->assertNull($rows[0]['fields']['name']);
        $this->assertArrayNotHasKey('phone', $rows[0]['fields']);
    }

    public function test_a_null_mapped_field_is_not_required_in_the_header(): void
    {
        $path = $this->fixture("email\na@example.com\n");

        $rows = $this->read($path, ['email' => 'email', 'name' => 'name', 'locale' => 'locale']);

        // name/locale headers are simply absent; that is not an error.
        $this->assertSame('a@example.com', $rows[0]['fields']['email']);
        $this->assertNull($rows[0]['fields']['name']);
    }

    // ── Import-level failures ────────────────────────────────────────

    public function test_missing_file_raises_on_preflight(): void
    {
        $reader = new CsvReader('/nonexistent/path/does-not-exist.csv', ['email' => 'email']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found or not readable');

        $reader->preflight();
    }

    public function test_empty_file_raises_on_preflight(): void
    {
        $reader = new CsvReader($this->fixture(''), ['email' => 'email']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty or has no header row');

        $reader->preflight();
    }

    public function test_missing_email_header_raises_on_preflight(): void
    {
        $reader = new CsvReader($this->fixture("name,locale\nAnn,en\n"), ['email' => 'email']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not contain the email column');

        $reader->preflight();
    }

    public function test_preflight_passes_for_a_valid_file(): void
    {
        $reader = new CsvReader($this->fixture("email,name\na@example.com,Ann\n"), ['email' => 'email']);

        $reader->preflight();

        $this->assertTrue(true, 'preflight did not raise');
    }

    // ── Streaming ────────────────────────────────────────────────────

    public function test_rows_returns_a_lazy_generator(): void
    {
        $lines = "email,name\n";
        for ($i = 0; $i < 20000; $i++) {
            $lines .= "u{$i}@example.com,Name {$i}\n";
        }
        $path = $this->fixture($lines);

        $reader = new CsvReader($path, ['email' => 'email', 'name' => 'name']);
        $rows = $reader->rows();

        $this->assertInstanceOf(Generator::class, $rows);

        $before = memory_get_usage();

        $seen = 0;
        foreach ($rows as $ignored) {
            $seen++;
            if ($seen === 3) {
                break;
            }
        }

        $growth = memory_get_usage() - $before;

        $this->assertSame(3, $seen);
        // Reading 3 of 20,000 records must not have pulled the file into memory. The file
        // itself is ~500KB; a materialising implementation would show growth of that order.
        $this->assertLessThan(200_000, $growth, "reading 3 rows grew memory by {$growth} bytes");
    }

    public function test_reading_emits_no_php_deprecations(): void
    {
        $path = $this->fixture("email,name\na@example.com,\"Doe, John\"\n");

        $deprecations = [];
        $previous = set_error_handler(
            function (int $_severity, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;

                return true;
            },
            E_DEPRECATED,
        );

        try {
            $this->read($path);
        } finally {
            set_error_handler($previous);
        }

        // setCsvControl() deprecates an implicit $escape on current PHP; the reader passes
        // it explicitly.
        $this->assertSame([], $deprecations);
    }
}
