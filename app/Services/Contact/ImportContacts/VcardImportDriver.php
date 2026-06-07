<?php

namespace App\Services\Contact\ImportContacts;

use Generator;
use Throwable;
use RuntimeException;
use Sabre\VObject\Reader;
use function Safe\fopen;
use function Safe\fclose;
use App\Services\VCard\ImportVCard;
use App\Models\Account\ContactImportJob;
use Sabre\VObject\Splitter\VCard as VCardReader;

/**
 * vCard import driver: streams VCARD entries with Sabre and imports each via the
 * existing {@see ImportVCard} service. Each chunk re-opens the file and skips to
 * its offset (entries are read sequentially) — fine for typical address books;
 * for very large vCard files a single-pass strategy would be preferable.
 */
class VcardImportDriver implements ContactImportDriver
{
    public function __construct(private ImportVCard $importVCard)
    {
    }

    public function countRows(string $path): int
    {
        $count = 0;
        foreach ($this->entries($path) as $ignored) {
            $count++;
        }

        return $count;
    }

    public function rows(string $path, int $offset = 0, ?int $limit = null): Generator
    {
        $end = $limit === null ? null : $offset + $limit;

        foreach ($this->entries($path) as [$number, $entry]) {
            if ($number <= $offset) {
                continue;
            }
            if ($end !== null && $number > $end) {
                break;
            }

            yield [$number, $entry];
        }
    }

    /**
     * @param  \Sabre\VObject\Component  $payload  a VCard entry from the splitter
     */
    public function importRow(ContactImportJob $importJob, $payload): void
    {
        $result = $this->importVCard->execute([
            'account_id' => $importJob->account_id,
            'user_id' => $importJob->user_id,
            'entry' => $payload,
            'behaviour' => ImportVCard::BEHAVIOUR_ADD,
        ]);

        if (! empty($result['error'] ?? null)) {
            throw new RuntimeException($result['reason'] ?? 'vCard entry could not be imported.');
        }
    }

    /**
     * Yield each vCard entry as [int $number, VCard component].
     *
     * @return Generator<int, array{0: int, 1: \Sabre\VObject\Component}>
     */
    private function entries(string $path): Generator
    {
        $stream = fopen($path, 'r');
        $reader = new VCardReader($stream, Reader::OPTION_FORGIVING + Reader::OPTION_IGNORE_INVALID_LINES);
        $number = 0;

        while (true) {
            try {
                $entry = $reader->getNext();
            } catch (Throwable $e) {
                break;
            }
            if ($entry === null) {
                break;
            }
            $number++;
            yield [$number, $entry];
        }

        fclose($stream);
    }
}
