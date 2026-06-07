<?php

namespace App\Http\Resources\Contact\ImportJob;

use App\Helpers\DateHelper;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detailed view of a single import: the base fields plus timing, ETA, and an
 * inline preview of the first errors. The full error list is paginated via its
 * own endpoint (Phase 8). All values come from the import_jobs row itself, so
 * this never queries contact rows.
 *
 * @extends JsonResource<\App\Models\Account\ContactImportJob>
 */
class ImportJobDetail extends JsonResource
{
    /** How many errors to inline before callers should page through /errors. */
    public const MAX_INLINE_ERRORS = 10;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return (new ImportJob($this->resource))->toArray($request) + [
            'errors' => array_slice($this->errors ?? [], 0, self::MAX_INLINE_ERRORS),
            'estimated_remaining_sec' => $this->estimated_remaining_sec,
            'started_at' => DateHelper::getTimestamp($this->started_at),
            'completed_at' => DateHelper::getTimestamp($this->completed_at),
            'url' => route('api.import.show', $this->id),
        ];
    }
}
