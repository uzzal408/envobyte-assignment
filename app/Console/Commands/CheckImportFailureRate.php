<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Account\ContactImportJob;

/**
 * Alerting rule from the brief: if the import failure rate (failed rows / total
 * rows) across all imports in the recent window exceeds a threshold, raise a
 * critical log event so the team is notified (route the `critical` channel to
 * Slack/PagerDuty/Sentry in config/logging.php). Runs hourly (see Console\Kernel).
 */
class CheckImportFailureRate extends Command
{
    /**
     * @var string
     */
    protected $signature = 'imports:check-failure-rate
                            {--minutes=60 : window to evaluate}
                            {--threshold=20 : alert if failure rate %% exceeds this}';

    /**
     * @var string
     */
    protected $description = 'Alerts when the contact-import failure rate exceeds a threshold';

    /**
     * @return int
     */
    public function handle()
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $threshold = (float) $this->option('threshold');

        $since = now()->subMinutes($minutes);
        $total = (int) ContactImportJob::where('created_at', '>=', $since)->sum('total_rows');
        $failed = (int) ContactImportJob::where('created_at', '>=', $since)->sum('failed_rows');
        $imports = ContactImportJob::where('created_at', '>=', $since)->count();
        $rate = $total > 0 ? round($failed / $total * 100, 2) : 0.0;

        $summary = "window={$minutes}m imports={$imports} rows={$total} failed={$failed} failure_rate={$rate}%";

        if ($total > 0 && $rate > $threshold) {
            Log::critical('contact_import.failure_rate_high', [
                'window_minutes' => $minutes,
                'threshold_pct' => $threshold,
                'failure_rate_pct' => $rate,
                'total_rows' => $total,
                'failed_rows' => $failed,
                'imports' => $imports,
            ]);
            $this->error("ALERT: {$summary} (> {$threshold}%)");

            return self::FAILURE;
        }

        $this->info("OK: {$summary}");

        return self::SUCCESS;
    }
}
