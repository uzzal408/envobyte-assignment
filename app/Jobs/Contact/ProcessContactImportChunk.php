<?php

namespace App\Jobs\Contact;

use Throwable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Account\ContactImportJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Validation\ValidationException;
use App\Services\Contact\ImportContacts\ContactImportDrivers;

/**
 * Processes one slice (chunk) of an import file. Many of these run in parallel
 * as part of a Bus::batch. Each row is isolated: a validation failure is
 * recorded against the row and skipped, the rest of the chunk continues. A
 * thrown (non-validation) error fails the job, which — because the batch is
 * dispatched with allowFailures(false) — cancels the remaining chunks.
 */
class ProcessContactImportChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of attempts before the job is marked failed.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Seconds to wait before retrying after a failure.
     *
     * @var int
     */
    public $backoff = 5;

    public function __construct(
        private int $importJobId,
        private int $offset,
        private int $limit
    ) {
    }

    /**
     * @return void
     */
    public function handle()
    {
        // Cooperative cancellation: stop if the batch was cancelled (a sibling
        // chunk failed) or the import was cancelled via the API (Phase 7).
        if ($this->batch() !== null && $this->batch()->cancelled()) {
            return;
        }

        $importJob = ContactImportJob::find($this->importJobId);
        if ($importJob === null || $importJob->status === ContactImportJob::STATUS_CANCELLED) {
            return;
        }

        $this->processChunk($importJob);
    }

    /**
     * @param  ContactImportJob  $importJob
     * @return void
     */
    private function processChunk(ContactImportJob $importJob): void
    {
        $driver = app(ContactImportDrivers::class)->make($importJob->format);
        $path = Storage::disk(config('filesystems.default'))->path($importJob->storage_path);
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($driver->rows($path, $this->offset, $this->limit) as [$number, $payload]) {
            $processed++;
            $error = $this->importRow($driver, $importJob, $payload);
            if ($error !== null) {
                $failed++;
                $errors[] = ['row' => $number, 'message' => $error];
            }
        }

        $this->record($importJob->id, $processed, $failed, $errors);
    }

    /**
     * Import a single row via the format driver. Returns null on success, or an
     * error message if the row was skipped.
     *
     * @param  \App\Services\Contact\ImportContacts\ContactImportDriver  $driver
     * @param  ContactImportJob  $importJob
     * @param  mixed  $payload
     * @return string|null
     */
    private function importRow($driver, ContactImportJob $importJob, $payload): ?string
    {
        try {
            $driver->importRow($importJob, $payload);

            return null;
        } catch (ValidationException $e) {
            return implode(' ', $e->validator->errors()->all());
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Atomically fold this chunk's progress into the import job. The row lock
     * serialises the read-modify-write of the counters and the errors array so
     * concurrent chunks don't clobber each other.
     *
     * @param  int  $importJobId
     * @param  int  $processed
     * @param  int  $failed
     * @param  array<int, array{row: int, message: string}>  $errors
     * @return void
     */
    private function record(int $importJobId, int $processed, int $failed, array $errors): void
    {
        DB::transaction(function () use ($importJobId, $processed, $failed, $errors) {
            $importJob = ContactImportJob::lockForUpdate()->find($importJobId);
            if ($importJob === null) {
                return;
            }

            $importJob->processed_rows += $processed;
            $importJob->failed_rows += $failed;
            if (! empty($errors)) {
                $importJob->errors = array_merge($importJob->errors ?? [], $errors);
            }
            $importJob->save();
        });
    }
}
