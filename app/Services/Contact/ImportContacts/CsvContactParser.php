<?php

namespace App\Services\Contact\ImportContacts;

use Generator;
use function Safe\fopen;
use function Safe\fclose;
use function Safe\preg_replace;

/**
 * Streams contacts out of an uploaded CSV file without ever loading the whole
 * file into memory. The first line is treated as the header row; data rows are
 * numbered 1-based (the header is not counted), which is the row number surfaced
 * in import errors and used to reconstruct the error CSV.
 *
 * Headers are normalised (trimmed + lower-cased) so a row is addressable as
 * $data['name'] / $data['email'] / $data['phone'] regardless of header casing.
 * Unknown columns are preserved in $data, keyed by their normalised header, so
 * the original row can be rebuilt later.
 */
class CsvContactParser
{
    /** Canonical fields a contact row maps to. */
    public const FIELDS = ['name', 'email', 'phone'];

    /** Headers that must be present for the file to be importable. */
    public const REQUIRED_HEADERS = ['name'];

    /**
     * The original (un-normalised) header row, BOM stripped.
     *
     * @param  string  $path
     * @return array<int, string>
     */
    public function headers(string $path): array
    {
        $handle = $this->open($path);
        $header = $this->readHeader($handle);
        fclose($handle);

        return $header;
    }

    /**
     * Count the data rows (blank lines and the header excluded). Streams the
     * file, so memory stays flat on large uploads.
     *
     * @param  string  $path
     * @return int
     */
    public function countRows(string $path): int
    {
        $count = 0;
        foreach ($this->streamRows($path) as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * Yield a slice of data rows as [int $rowNumber, array $data], where $data
     * is keyed by the normalised header. $offset skips that many data rows;
     * $limit caps how many are yielded (null = to the end). This is what a batch
     * job calls to process its own chunk.
     *
     * @param  string  $path
     * @param  int  $offset
     * @param  int|null  $limit
     * @return Generator<int, array{0: int, 1: array<string, string>}>
     */
    public function rows(string $path, int $offset = 0, ?int $limit = null): Generator
    {
        $keys = $this->normaliseHeaders($this->headers($path));
        $end = $limit === null ? null : $offset + $limit;

        foreach ($this->streamRows($path) as [$number, $values]) {
            if ($number <= $offset) {
                continue;
            }
            if ($end !== null && $number > $end) {
                break;
            }

            yield [$number, $this->combine($keys, $values)];
        }
    }

    /**
     * Stream every non-blank data row as [int $rowNumber, array $values], with
     * values aligned to the header width. Shared by countRows() and rows() so
     * row numbering stays identical between them.
     *
     * @param  string  $path
     * @return Generator<int, array{0: int, 1: array<int, string>}>
     */
    private function streamRows(string $path): Generator
    {
        $handle = $this->open($path);
        $width = count($this->readHeader($handle));
        $number = 0;

        while (($line = fgetcsv($handle)) !== false) { /** @phpstan-ignore-line */
            if ($this->isBlank($line)) {
                continue;
            }
            $number++;
            yield [$number, $this->align($line, $width)];
        }

        fclose($handle);
    }

    /**
     * @param  string  $path
     * @return resource
     */
    private function open(string $path)
    {
        // Safe\fopen throws Safe\Exceptions\FilesystemException if the file
        // cannot be opened, instead of returning false.
        return fopen($path, 'r');
    }

    /**
     * Read the header row and strip a leading UTF-8 BOM from the first column.
     *
     * @param  resource  $handle
     * @return array<int, string>
     */
    private function readHeader($handle): array
    {
        $header = fgetcsv($handle); /** @phpstan-ignore-line */
        if ($header === false || $this->isBlank($header)) {
            return [];
        }
        $header[0] = $this->stripBom((string) $header[0]);

        return array_map(fn ($value) => trim((string) $value), $header);
    }

    /**
     * @param  array<int, string>  $header
     * @return array<int, string>
     */
    private function normaliseHeaders(array $header): array
    {
        return array_map(fn ($value) => strtolower(trim((string) $value)), $header);
    }

    /**
     * Combine normalised keys with a row's values into an associative array.
     *
     * @param  array<int, string>  $keys
     * @param  array<int, string>  $values
     * @return array<string, string>
     */
    private function combine(array $keys, array $values): array
    {
        return array_combine($keys, $values);
    }

    /**
     * Pad/truncate a data line to the header width so keys and values always
     * line up, and trim each value.
     *
     * @param  array<int, string|null>  $line
     * @param  int  $width
     * @return array<int, string>
     */
    private function align(array $line, int $width): array
    {
        $line = array_slice(array_pad($line, $width, ''), 0, $width);

        return array_map(fn ($value) => trim((string) $value), $line);
    }

    /**
     * @param  array<int, string|null>  $line
     * @return bool
     */
    private function isBlank(array $line): bool
    {
        $nonEmpty = array_filter($line, fn ($value) => $value !== null && trim((string) $value) !== '');

        return count($nonEmpty) === 0;
    }

    private function stripBom(string $value): string
    {
        return preg_replace('/^\x{FEFF}/u', '', $value);
    }
}
