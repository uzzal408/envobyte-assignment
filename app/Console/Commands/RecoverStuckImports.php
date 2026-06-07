<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use App\Models\Account\ContactImportJob;

/**
 * Recovers contact imports left in `processing` after a crash. "Stuck" means the
 * job has made no progress (its `updated_at` hasn't moved — each chunk touches it)
 * for longer than the threshold, which distinguishes a hung import from a slow but
 * still-advancing one. Runs hourly (see Console\Kernel).
 *
 * Three cases:
 *  - batch was cancelled but status never settled → mark cancelled
 *  - batch actually finished (then/finally callback was lost) → reconcile from counters
 *  - batch is gone or hung with work outstanding → cancel it and mark failed
 *
 * We deliberately do not re-dispatch remaining chunks: contact creation is not
 * idempotent, so re-running a partially-done import would duplicate contacts. The
 * user re-uploads instead, and duplicate detection (file hash) short-circuits it.
 */
class RecoverStuckImports extends Command
{
    /**
     * @var string
     */
    protected $signature = 'imports:recover
                            {--minutes=15 : minutes without progress before an import is considered stuck}';

    /**
     * @var string
     */
    protected $description = 'Detects and recovers contact imports stuck in processing';

    /**
     * @return int
     */
    public function handle()
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $threshold = now()->subMinutes($minutes);

        $stuck = ContactImportJob::where('status', ContactImportJob::STATUS_PROCESSING)
            ->where('updated_at', '<', $threshold)
            ->get();

        $this->info("Found {$stuck->count()} stuck import(s) (no progress for >{$minutes}m).");

        foreach ($stuck as $importJob) {
            $action = $this->recover($importJob);
            $this->line("  import #{$importJob->id}: {$action}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  ContactImportJob  $importJob
     * @return string
     */
    private function recover(ContactImportJob $importJob): string
    {
        $batch = $importJob->batch_id !== null ? Bus::findBatch($importJob->batch_id) : null;

        if ($batch !== null && $batch->cancelled()) {
            $this->finalize($importJob, ContactImportJob::STATUS_CANCELLED);

            return 'cancelled (batch was cancelled)';
        }

        if ($batch !== null && $batch->finished()) {
            $outcome = $this->outcome($importJob);
            $this->finalize($importJob, $outcome);

            return "reconciled to {$outcome} (batch had finished)";
        }

        // Gone or hung with work outstanding: stop any remaining chunks, give up.
        optional($batch)->cancel();
        $this->finalize($importJob, ContactImportJob::STATUS_FAILED);

        return 'failed (stuck, no progress)';
    }

    /**
     * @param  ContactImportJob  $importJob
     * @return string
     */
    private function outcome(ContactImportJob $importJob): string
    {
        $allFailed = $importJob->total_rows > 0 && $importJob->failed_rows >= $importJob->total_rows;

        return $allFailed ? ContactImportJob::STATUS_FAILED : ContactImportJob::STATUS_COMPLETED;
    }

    /**
     * @param  ContactImportJob  $importJob
     * @param  string  $status
     * @return void
     */
    private function finalize(ContactImportJob $importJob, string $status): void
    {
        $importJob->update([
            'status' => $status,
            'completed_at' => $importJob->completed_at ?? now(),
        ]);
    }
}
