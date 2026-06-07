<?php

namespace App\Services\Contact\ImportContacts;

use Throwable;
use Illuminate\Bus\Batch;
use App\Services\BaseService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\Account\ContactImportJob;
use App\Jobs\Contact\ProcessContactImportChunk;

/**
 * Accepts an uploaded CSV file and creates a `pending` import job. Kept fast
 * (no row processing) so the HTTP request returns well under the 500ms budget;
 * the actual work is dispatched to the queue (see Phase 5). Streaming the file
 * only to count rows keeps memory flat on large uploads.
 */
class InitiateContactImport extends BaseService
{
    /** Rows processed per chunk job — small enough to bound memory, large enough to keep per-job overhead low (ADR #4). */
    public const BATCH_SIZE = 50;


    /**
     * Get the validation rules that apply to the service.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'account_id' => 'required|integer|exists:accounts,id',
            'user_id' => 'required|integer|exists:users,id',
            'file' => 'required|file|mimes:csv,txt,vcf,vcard|max:'.config('monica.max_upload_size'),
        ];
    }

    /**
     * Validate the upload, store it, and create the import job record.
     *
     * @param  array  $data
     * @return ContactImportJob
     */
    public function execute(array $data): ContactImportJob
    {
        $this->validate($data);

        /** @var UploadedFile $file */
        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());
        $size = $file->getSize();

        $existing = $this->findDuplicate($data['account_id'], $hash);
        if ($existing !== null) {
            return $existing;
        }

        $drivers = app(ContactImportDrivers::class);
        $format = $drivers->detect($file->getClientOriginalExtension());

        $disk = config('filesystems.default');
        $path = $file->store('imports', $disk);

        $importJob = ContactImportJob::create([
            'account_id' => $data['account_id'],
            'user_id' => $data['user_id'],
            'filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'format' => $format,
            'total_rows' => $drivers->make($format)->countRows(Storage::disk($disk)->path($path)),
            'processed_rows' => 0,
            'failed_rows' => 0,
            'file_hash' => $hash,
            'file_size' => $size,
            'status' => ContactImportJob::STATUS_PENDING,
        ]);

        $this->dispatchBatch($importJob);

        return $importJob;
    }

    /**
     * Split the import into fixed-size chunk jobs and dispatch them as a batch.
     * Kept fast (only builds the job list, no row work). allowFailures(false)
     * means one fatal chunk failure cancels the rest (spec requirement).
     *
     * @param  ContactImportJob  $importJob
     * @return void
     */
    private function dispatchBatch(ContactImportJob $importJob): void
    {
        if ($importJob->total_rows <= 0) {
            $importJob->update([
                'status' => ContactImportJob::STATUS_COMPLETED,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            return;
        }

        // Mark processing BEFORE dispatch: under the sync queue (tests) the chunks
        // and the `then` callback run inline during dispatch(), so this must already
        // be set or it would overwrite the `completed` status afterwards.
        $importJob->update([
            'status' => ContactImportJob::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        $chunks = [];
        for ($offset = 0; $offset < $importJob->total_rows; $offset += self::BATCH_SIZE) {
            $chunks[] = new ProcessContactImportChunk($importJob->id, $offset, self::BATCH_SIZE);
        }

        // Callbacks run on the worker, so they capture only the id (not $this).
        $id = $importJob->id;
        $batch = Bus::batch($chunks)
            ->name("contact-import:{$id}")
            ->allowFailures(false)
            ->onQueue('imports')
            ->then(function (Batch $batch) use ($id) {
                // All chunks succeeded — completed unless every row failed.
                $importJob = ContactImportJob::find($id);
                if ($importJob === null || $importJob->status === ContactImportJob::STATUS_CANCELLED) {
                    return;
                }
                $allFailed = $importJob->total_rows > 0 && $importJob->failed_rows >= $importJob->total_rows;
                $importJob->update([
                    'status' => $allFailed ? ContactImportJob::STATUS_FAILED : ContactImportJob::STATUS_COMPLETED,
                ]);
                self::logFinished($importJob->fresh());
            })
            ->catch(function (Batch $batch, Throwable $e) use ($id) {
                // A chunk threw a fatal error; the batch stopped the rest.
                ContactImportJob::whereKey($id)
                    ->where('status', '!=', ContactImportJob::STATUS_CANCELLED)
                    ->update(['status' => ContactImportJob::STATUS_FAILED]);
                Log::warning('contact_import.failed', ['import_job_id' => $id, 'reason' => $e->getMessage()]);
            })
            ->finally(function (Batch $batch) use ($id) {
                ContactImportJob::whereKey($id)->update(['completed_at' => now()]);
            })
            ->dispatch();

        // Only the batch id here — status was set above; under sync the row is
        // already `completed` by now, and update() writes just the dirty column.
        $importJob->update(['batch_id' => $batch->id]);

        Log::info('contact_import.started', [
            'import_job_id' => $importJob->id,
            'account_id' => $importJob->account_id,
            'total_rows' => $importJob->total_rows,
            'batch_id' => $batch->id,
        ]);
    }

    /**
     * Emit a structured event with the metrics worth tracking (duration,
     * throughput, failure rate) when an import finishes. A log aggregator turns
     * these into the imports-completed / avg-ms-per-row / failure-rate metrics.
     *
     * @param  ContactImportJob  $importJob
     * @return void
     */
    private static function logFinished(ContactImportJob $importJob): void
    {
        $durationMs = $importJob->started_at !== null
            ? $importJob->started_at->diffInMilliseconds(now())
            : null;
        $msPerRow = ($durationMs !== null && $importJob->processed_rows > 0)
            ? round($durationMs / $importJob->processed_rows, 2)
            : null;
        $failureRate = $importJob->total_rows > 0
            ? round($importJob->failed_rows / $importJob->total_rows, 4)
            : 0.0;

        Log::info('contact_import.completed', [
            'import_job_id' => $importJob->id,
            'account_id' => $importJob->account_id,
            'status' => $importJob->status,
            'total_rows' => $importJob->total_rows,
            'processed_rows' => $importJob->processed_rows,
            'failed_rows' => $importJob->failed_rows,
            'duration_ms' => $durationMs,
            'ms_per_row' => $msPerRow,
            'failure_rate' => $failureRate,
        ]);
    }

    /**
     * Return an existing import with the same content hash for this account, or
     * null. Gated by config so the behaviour can be turned off (ADR #3).
     *
     * @param  int  $accountId
     * @param  string  $hash
     * @return ContactImportJob|null
     */
    private function findDuplicate(int $accountId, string $hash): ?ContactImportJob
    {
        if (! config('monica.contact_import_detect_duplicates')) {
            return null;
        }

        return ContactImportJob::where('account_id', $accountId)
            ->where('file_hash', $hash)
            ->latest('id')
            ->first();
    }
}
