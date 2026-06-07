<?php

namespace Tests\Unit\Services\Contact\ImportContacts;

use Tests\TestCase;
use App\Services\Contact\ImportContacts\CsvContactParser;

class CsvContactParserTest extends TestCase
{
    private function fixture(): string
    {
        return base_path('tests/Fixtures/Services/Contact/ImportContacts/contacts.csv');
    }

    /** @test */
    public function it_returns_the_original_headers(): void
    {
        $parser = new CsvContactParser;

        $this->assertEquals(['name', 'email', 'phone'], $parser->headers($this->fixture()));
    }

    /** @test */
    public function it_counts_data_rows_excluding_header_and_blank_lines(): void
    {
        $parser = new CsvContactParser;

        // 4 data rows: John, Jane, Bob, "Quoted, Name" — the blank line is skipped.
        $this->assertEquals(4, $parser->countRows($this->fixture()));
    }

    /** @test */
    public function it_yields_a_slice_with_one_based_row_numbers(): void
    {
        $parser = new CsvContactParser;

        $slice = iterator_to_array($parser->rows($this->fixture(), 0, 2), false);

        $this->assertCount(2, $slice);
        $this->assertSame(1, $slice[0][0]);
        $this->assertSame('John Doe', $slice[0][1]['name']);
        $this->assertSame(2, $slice[1][0]);
        $this->assertSame('Jane Smith', $slice[1][1]['name']);
    }

    /** @test */
    public function it_offsets_into_the_file_and_respects_the_limit(): void
    {
        $parser = new CsvContactParser;

        $slice = iterator_to_array($parser->rows($this->fixture(), 2, 2), false);

        $this->assertCount(2, $slice);
        $this->assertSame(3, $slice[0][0]);
        $this->assertSame('Bob', $slice[0][1]['name']);
        $this->assertSame(4, $slice[1][0]);
        $this->assertSame('Quoted, Name', $slice[1][1]['name']);
    }

    /** @test */
    public function it_reads_to_the_end_when_no_limit_is_given(): void
    {
        $parser = new CsvContactParser;

        $slice = iterator_to_array($parser->rows($this->fixture(), 2), false);

        $this->assertCount(2, $slice);
        $this->assertSame([3, 4], [$slice[0][0], $slice[1][0]]);
    }

    /** @test */
    public function it_aligns_short_and_overlong_rows_to_the_header_width(): void
    {
        $parser = new CsvContactParser;

        $rows = [];
        foreach ($parser->rows($this->fixture()) as [$number, $data]) {
            $rows[$number] = $data;
        }

        // Jane has no phone column -> padded to empty string, keys still aligned.
        $this->assertSame(['name', 'email', 'phone'], array_keys($rows[2]));
        $this->assertSame('', $rows[2]['phone']);

        // Bob has an extra trailing column -> truncated to the header width.
        $this->assertCount(3, $rows[3]);
        $this->assertSame('555-0003', $rows[3]['phone']);
    }

    /** @test */
    public function it_strips_a_utf8_bom_from_the_first_header(): void
    {
        $parser = new CsvContactParser;
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "\xEF\xBB\xBFname,email\nJohn,john@example.com\n");

        try {
            $this->assertSame(['name', 'email'], $parser->headers($path));
            $slice = iterator_to_array($parser->rows($path), false);
            $this->assertSame('John', $slice[0][1]['name']);
        } finally {
            @unlink($path);
        }
    }
}
