<?php

namespace App\Services\Contact\ImportContacts;

/**
 * Resolves the right {@see ContactImportDriver} for an uploaded file. Detection
 * is by extension; everything that isn't a vCard is treated as CSV.
 */
class ContactImportDrivers
{
    public const FORMAT_CSV = 'csv';
    public const FORMAT_VCARD = 'vcard';

    /**
     * Map a file extension to a supported format.
     */
    public function detect(?string $extension): string
    {
        $ext = strtolower((string) $extension);

        return in_array($ext, ['vcf', 'vcard'], true) ? self::FORMAT_VCARD : self::FORMAT_CSV;
    }

    public function make(string $format): ContactImportDriver
    {
        if ($format === self::FORMAT_VCARD) {
            return app(VcardImportDriver::class);
        }

        return app(CsvImportDriver::class);
    }
}
