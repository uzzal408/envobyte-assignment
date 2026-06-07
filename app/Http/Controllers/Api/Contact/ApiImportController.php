<?php

namespace App\Http\Controllers\Api\Contact;

use Illuminate\Http\Request;
use function Safe\fopen;
use function Safe\fclose;
use function Safe\fputcsv;
use App\Models\Account\ContactImportJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Services\Contact\ImportContacts\CsvContactParser;
use App\Services\Contact\ImportContacts\ContactImportDrivers;
use App\Services\Contact\ImportContacts\InitiateContactImport;
use App\Services\Contact\ImportContacts\CancelContactImport;
use App\Http\Resources\Contact\ImportJob\ImportJob as ImportJobResource;
use App\Http\Resources\Contact\ImportJob\ImportJobDetail as ImportJobDetailResource;

class ApiImportController extends ApiController
{
    /**
     * List the account's recent imports, paginated, with status and progress.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $imports = auth()->user()->account->contactImportJobs()
            ->paginate($this->getLimitPerPage());

        return ImportJobResource::collection($imports);
    }

    /**
     * Get detailed status for a single import (progress, counts, ETA, errors).
     * Reads only the import_jobs row — never the contact rows — so it stays fast.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return ImportJobDetailResource|\Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        try {
            $importJob = ContactImportJob::where('account_id', auth()->user()->account_id)
                ->where('id', $id)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return $this->respondNotFound();
        }

        return new ImportJobDetailResource($importJob);
    }

    /**
     * Cancel a running import. Remaining chunks are skipped; an in-flight chunk
     * finishes its current slice. No-op if the import already finished.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return ImportJobDetailResource|\Illuminate\Http\JsonResponse
     */
    public function cancel(Request $request, $id)
    {
        try {
            $importJob = app(CancelContactImport::class)->execute([
                'account_id' => auth()->user()->account_id,
                'import_job_id' => $id,
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->respondNotFound();
        } catch (ValidationException $e) {
            return $this->respondValidatorFailed($e->validator);
        }

        return new ImportJobDetailResource($importJob);
    }

    /**
     * Paginated list of per-row errors ({row, message}) for an import. Reads the
     * errors straight off the import_jobs row (no contact scan).
     *
     * @param  Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function errors(Request $request, $id)
    {
        try {
            $importJob = ContactImportJob::where('account_id', auth()->user()->account_id)
                ->where('id', $id)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return $this->respondNotFound();
        }

        $errors = $importJob->errors ?? [];
        // getLimitPerPage() is 0 when no ?limit is passed; mirror how the
        // framework's paginate() falls back to the model's default page size.
        $perPage = (int) ($this->getLimitPerPage() ?: (new ContactImportJob)->getPerPage());
        $page = max(1, (int) $request->input('page', 1));
        $total = count($errors);

        return response()->json([
            'data' => array_values(array_slice($errors, ($page - 1) * $perPage, $perPage)),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * Download a CSV of the failed rows: the original columns plus an `error`
     * column. Reconstructed by re-reading the stored upload and matching rows by
     * number — streamed so memory stays flat regardless of file size.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function errorsCsv(Request $request, $id)
    {
        try {
            $importJob = ContactImportJob::where('account_id', auth()->user()->account_id)
                ->where('id', $id)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return $this->respondNotFound();
        }

        return $this->streamErrorCsv($importJob);
    }

    /**
     * @param  ContactImportJob  $importJob
     * @return StreamedResponse
     */
    private function streamErrorCsv(ContactImportJob $importJob): StreamedResponse
    {
        $messages = [];
        foreach ($importJob->errors ?? [] as $error) {
            $messages[$error['row']] = $error['message'];
        }

        // vCard rows don't map to CSV columns, so emit a simple row/error report.
        if ($importJob->format !== ContactImportDrivers::FORMAT_CSV) {
            return $this->streamSimpleErrorCsv($importJob, $messages);
        }

        $disk = Storage::disk(config('filesystems.default'));
        $hasFile = $importJob->storage_path !== null && $disk->exists($importJob->storage_path);
        $path = $hasFile ? $disk->path($importJob->storage_path) : null;
        $parser = app(CsvContactParser::class);
        $headers = $path !== null ? $parser->headers($path) : ['name', 'email', 'phone'];

        return response()->streamDownload(function () use ($parser, $path, $headers, $messages) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge($headers, ['error']));
            if ($path !== null) {
                foreach ($parser->rows($path) as [$number, $row]) {
                    if (isset($messages[$number])) {
                        fputcsv($out, array_merge(array_values($row), [$messages[$number]]));
                    }
                }
            }
            fclose($out);
        }, 'errors-'.$importJob->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Error report for non-CSV formats (e.g. vCard): just the row number and
     * message, since the original columns don't map to a CSV row.
     *
     * @param  ContactImportJob  $importJob
     * @param  array<int, string>  $messages
     * @return StreamedResponse
     */
    private function streamSimpleErrorCsv(ContactImportJob $importJob, array $messages): StreamedResponse
    {
        return response()->streamDownload(function () use ($messages) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row', 'error']);
            foreach ($messages as $row => $message) {
                fputcsv($out, [$row, $message]);
            }
            fclose($out);
        }, 'errors-'.$importJob->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Initiate a contact import from an uploaded CSV file. Validates and stores
     * the file and creates a pending import job; processing happens on the queue.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $importJob = app(InitiateContactImport::class)->execute([
                'account_id' => auth()->user()->account_id,
                'user_id' => auth()->user()->id,
                'file' => $request->file('file'),
            ]);
        } catch (ValidationException $e) {
            return $this->respondValidatorFailed($e->validator);
        } catch (QueryException $e) {
            return $this->respondInvalidQuery();
        }

        // A duplicate upload returns the existing job (200); a new one is 201.
        $statusCode = $importJob->wasRecentlyCreated ? 201 : 200;

        return (new ImportJobResource($importJob))
            ->response()
            ->setStatusCode($statusCode);
    }
}
