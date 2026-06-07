<?php

namespace App\Services\Contact\ImportContacts;

use Generator;
use App\Models\Account\ContactImportJob;

/**
 * CSV import driver: streams rows via {@see CsvContactParser} and maps each one
 * to a contact via {@see CreateContactFromRow}.
 */
class CsvImportDriver implements ContactImportDriver
{
    public function __construct(
        private CsvContactParser $parser,
        private CreateContactFromRow $createContact
    ) {
    }

    public function countRows(string $path): int
    {
        return $this->parser->countRows($path);
    }

    public function rows(string $path, int $offset = 0, ?int $limit = null): Generator
    {
        return $this->parser->rows($path, $offset, $limit);
    }

    /**
     * @param  array<string, string>  $payload
     */
    public function importRow(ContactImportJob $importJob, $payload): void
    {
        $this->createContact->execute([
            'account_id' => $importJob->account_id,
            'user_id' => $importJob->user_id,
            'name' => $payload['name'] ?? '',
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
        ]);
    }
}
