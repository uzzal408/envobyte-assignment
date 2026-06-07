<?php

namespace App\Models\Account;

use App\Models\User\User;
use App\Models\ModelBindingHasher as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A background job that imports contacts from an uploaded CSV file. The row is
 * created synchronously on upload (status `pending`) and then advanced by the
 * queued batch jobs, which is what the progress API reads.
 *
 * @property int $id
 * @property int $account_id
 * @property int $user_id
 * @property string|null $filename
 * @property string|null $storage_path
 * @property string $format
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $failed_rows
 * @property string $status
 * @property array|null $errors
 * @property string|null $file_hash
 * @property int|null $file_size
 * @property string|null $batch_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property int $progress_pct
 */
class ContactImportJob extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    protected $table = 'contact_import_jobs';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'failed_rows' => 'integer',
        'file_size' => 'integer',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Imports that have been "processing" longer than $minutes — the monitoring
     * definition of stuck (time-based, unlike the recovery command which keys off
     * lack of progress). Used for the "stuck imports" dashboard query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $minutes
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStuck($query, int $minutes = 30)
    {
        return $query->where('status', self::STATUS_PROCESSING)
            ->where('started_at', '<', now()->subMinutes($minutes));
    }

    /**
     * Get the account record associated with the import job.
     *
     * @return BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the user record that initiated the import job.
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Progress as an integer percentage (0-100), derived from the counters so
     * the API never has to count contact rows.
     *
     * @return int
     */
    public function getProgressPctAttribute(): int
    {
        if ($this->total_rows <= 0) {
            return 0;
        }

        return (int) floor($this->processed_rows * 100 / $this->total_rows);
    }

    /**
     * Rough ETA in seconds for a running import, extrapolated from the rate so
     * far (elapsed × remaining / processed). Null unless actively processing
     * with measurable progress. Derived from this row's own fields — no query.
     *
     * @return int|null
     */
    public function getEstimatedRemainingSecAttribute(): ?int
    {
        if ($this->status !== self::STATUS_PROCESSING || $this->processed_rows <= 0 || $this->started_at === null) {
            return null;
        }

        $elapsed = max(1, $this->started_at->diffInSeconds(now()));
        $remaining = max(0, $this->total_rows - $this->processed_rows);

        return (int) ceil($remaining * $elapsed / $this->processed_rows);
    }
}
