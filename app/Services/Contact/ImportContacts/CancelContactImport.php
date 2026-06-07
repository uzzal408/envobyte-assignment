<?php

namespace App\Services\Contact\ImportContacts;

use App\Services\BaseService;
use Illuminate\Support\Facades\Bus;
use App\Models\Account\ContactImportJob;

/**
 * Cancels a running (or still-pending) import. Cancellation is cooperative:
 *  - the Laravel batch is cancelled, so queued chunks are skipped, and
 *  - the job status is set to `cancelled`, which each chunk re-checks before it
 *    starts (a chunk already running finishes its current slice, nothing new
 *    starts).
 * Already-finished imports (completed/failed/cancelled) are left untouched.
 */
class CancelContactImport extends BaseService
{
    /**
     * Get the validation rules that apply to the service.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'account_id' => 'required|integer|exists:accounts,id',
            'import_job_id' => 'required|integer',
        ];
    }

    /**
     * Cancel the import.
     *
     * @param  array  $data
     * @return ContactImportJob
     */
    public function execute(array $data): ContactImportJob
    {
        $this->validate($data);

        $importJob = ContactImportJob::where('account_id', $data['account_id'])
            ->findOrFail($data['import_job_id']);

        if (! $this->isCancellable($importJob)) {
            return $importJob;
        }

        if ($importJob->batch_id !== null) {
            optional(Bus::findBatch($importJob->batch_id))->cancel();
        }

        $importJob->update([
            'status' => ContactImportJob::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $importJob;
    }

    /**
     * Only pending or in-progress imports can be cancelled.
     *
     * @param  ContactImportJob  $importJob
     * @return bool
     */
    private function isCancellable(ContactImportJob $importJob): bool
    {
        return in_array($importJob->status, [
            ContactImportJob::STATUS_PENDING,
            ContactImportJob::STATUS_PROCESSING,
        ], true);
    }
}
