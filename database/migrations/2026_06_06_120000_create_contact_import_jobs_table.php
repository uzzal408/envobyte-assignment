<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateContactImportJobsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('contact_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('user_id');
            $table->string('filename')->nullable();      // original uploaded name (for display)
            $table->string('storage_path')->nullable();   // path on disk (re-read by batches / error CSV)
            $table->string('format', 10)->default('csv'); // csv | vcard — selects the import driver

            // Progress counters — written by the batch jobs, read by the API.
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            // pending | processing | completed | failed | cancelled
            $table->string('status', 12)->default('pending');

            // Array of per-row errors: [{ "row": 42, "message": "..." }, ...]
            $table->json('errors')->nullable();

            // Idempotency / duplicate detection (ADR #3).
            $table->string('file_hash', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Laravel batch id, so we can relate/inspect the Bus::batch.
            $table->string('batch_id', 40)->nullable();

            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->datetime('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // List/detail queries are always scoped by account; monitoring scans by status+age.
            $table->index(['account_id', 'status']);
            $table->index(['status', 'started_at']);
            // Duplicate-upload lookup: where account_id = ? and file_hash = ?
            $table->index(['account_id', 'file_hash']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('contact_import_jobs');
    }
}
