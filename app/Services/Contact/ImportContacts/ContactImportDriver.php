<?php

namespace App\Services\Contact\ImportContacts;

use Generator;
use App\Models\Account\ContactImportJob;

/**
 * A format-specific strategy for a background contact import. One driver knows
 * how to stream rows out of a file and how to turn a single row into a contact,
 * so the chunk job and the initiation service stay format-agnostic. Add a new
 * file format by implementing this interface and registering it in
 * {@see ContactImportDrivers}.
 */
interface ContactImportDriver
{
    /**
     * Count the importable rows/entries in the file (header/blank lines excluded).
     */
    public function countRows(string $path): int;

    /**
     * Stream a slice of rows as [int $rowNumber, mixed $payload]. The payload is
     * opaque to the caller and handed straight back to importRow().
     *
     * @return Generator<int, array{0: int, 1: mixed}>
     */
    public function rows(string $path, int $offset = 0, ?int $limit = null): Generator;

    /**
     * Create a contact from one row's payload. Throws on per-row failure so the
     * chunk job can record it and skip the row.
     *
     * @param  mixed  $payload
     */
    public function importRow(ContactImportJob $importJob, $payload): void;
}
