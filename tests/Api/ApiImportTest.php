<?php

namespace Tests\Api;

use Tests\ApiTestCase;
use App\Models\User\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\Contact\Contact;
use App\Models\Account\ContactImportJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Covers the contact CSV import API. Runs under the sync queue (phpunit.xml), so
 * a dispatched batch — chunks and the completion callback — executes inline during
 * the request; an import is therefore `completed` by the time the POST returns.
 */
class ApiImportTest extends ApiTestCase
{
    use DatabaseTransactions;

    /**
     * Build a real UploadedFile (explicit text/csv mime) from CSV content so the
     * `mimes:csv,txt` rule passes deterministically.
     */
    private function csvUpload(string $content, string $name = 'contacts.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function vcardUpload(string $content, string $name = 'contacts.vcf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vcf');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/vcard', null, true);
    }

    private function sampleCsv(): string
    {
        return "name,email,phone\n"
            ."Alice Smith,alice@example.com,555-0001\n"
            ."Bob Jones,bob@example.com,555-0002\n";
    }

    /** @test */
    public function it_initiates_an_import_and_tracks_status(): void
    {
        Storage::fake(config('filesystems.default'));
        $user = $this->signIn();

        $response = $this->json('POST', '/api/import', [
            'file' => $this->csvUpload($this->sampleCsv()),
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['filename' => 'contacts.csv', 'total_rows' => 2]);

        $id = $response->json('data.id');
        $this->assertDatabaseHas('contact_import_jobs', [
            'id' => $id,
            'account_id' => $user->account_id,
            'total_rows' => 2,
        ]);

        // Sync queue ran the batch inline → it is completed with both rows imported.
        $this->json('GET', "/api/import/{$id}")
            ->assertStatus(200)
            ->assertJsonFragment([
                'status' => ContactImportJob::STATUS_COMPLETED,
                'processed_rows' => 2,
                'failed_rows' => 0,
                'progress_pct' => 100,
            ]);

        $this->assertEquals(2, Contact::where('account_id', $user->account_id)->count());
    }

    /** @test */
    public function it_imports_a_vcard_file_through_the_same_endpoint(): void
    {
        Storage::fake(config('filesystems.default'));
        $user = $this->signIn();

        $vcard = "BEGIN:VCARD\nVERSION:3.0\nN:Newton;Isaac;;;\nFN:Isaac Newton\n"
            ."EMAIL;TYPE=INTERNET:isaac@example.com\nEND:VCARD\n";

        $response = $this->json('POST', '/api/import', [
            'file' => $this->vcardUpload($vcard),
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['filename' => 'contacts.vcf', 'format' => 'vcard', 'total_rows' => 1]);

        $id = $response->json('data.id');
        $this->json('GET', "/api/import/{$id}")
            ->assertStatus(200)
            ->assertJsonFragment([
                'status' => ContactImportJob::STATUS_COMPLETED,
                'processed_rows' => 1,
                'failed_rows' => 0,
            ]);

        $this->assertEquals(1, Contact::where('account_id', $user->account_id)->where('first_name', 'Isaac')->count());
    }

    /** @test */
    public function it_isolates_per_row_errors_and_still_completes(): void
    {
        Storage::fake(config('filesystems.default'));
        $user = $this->signIn();

        $csv = "name,email,phone\n"
            ."Good One,good@example.com,555-1\n"
            ."Bad Email,not-an-email,555-2\n"
            .",noname@example.com,555-3\n";

        $id = $this->json('POST', '/api/import', ['file' => $this->csvUpload($csv)])
            ->assertStatus(201)
            ->json('data.id');

        $this->json('GET', "/api/import/{$id}")
            ->assertStatus(200)
            ->assertJsonFragment([
                'status' => ContactImportJob::STATUS_COMPLETED,
                'total_rows' => 3,
                'processed_rows' => 3,
                'failed_rows' => 2,
            ]);

        // Only the one valid row became a contact; the two bad rows were skipped.
        $this->assertEquals(1, Contact::where('account_id', $user->account_id)->count());

        $errors = $this->json('GET', "/api/import/{$id}/errors")->json('data');
        $this->assertEquals([2, 3], array_column($errors, 'row'));
    }

    /** @test */
    public function it_detects_duplicate_uploads(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->signIn();
        $csv = $this->sampleCsv();

        $first = $this->json('POST', '/api/import', ['file' => $this->csvUpload($csv)])
            ->assertStatus(201)
            ->json('data.id');

        // Same content → returns the existing import (200), not a new one.
        $this->json('POST', '/api/import', ['file' => $this->csvUpload($csv)])
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $first]);

        $this->assertEquals(1, ContactImportJob::count());
    }

    /** @test */
    public function it_validates_the_uploaded_file(): void
    {
        $this->signIn();

        $this->json('POST', '/api/import', [])->assertStatus(422);
    }

    /** @test */
    public function it_cancels_a_running_import(): void
    {
        $user = $this->signIn();
        $importJob = ContactImportJob::create([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
            'filename' => 'big.csv',
            'total_rows' => 1000,
            'processed_rows' => 50,
            'status' => ContactImportJob::STATUS_PROCESSING,
        ]);

        $this->json('POST', "/api/import/{$importJob->id}/cancel")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => ContactImportJob::STATUS_CANCELLED]);

        $this->assertDatabaseHas('contact_import_jobs', [
            'id' => $importJob->id,
            'status' => ContactImportJob::STATUS_CANCELLED,
        ]);
    }

    /** @test */
    public function it_generates_an_error_csv_reconstructing_original_rows(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->signIn();

        $csv = "name,email,phone\n"
            ."Good One,good@example.com,555-1\n"
            ."Bad Email,not-an-email,555-2\n";

        $id = $this->json('POST', '/api/import', ['file' => $this->csvUpload($csv)])
            ->assertStatus(201)
            ->json('data.id');

        $response = $this->get("/api/import/{$id}/errors.csv");
        $response->assertStatus(200);

        $body = $response->streamedContent();
        $this->assertStringContainsString('name,email,phone,error', $body);
        $this->assertStringContainsString('not-an-email', $body);
        $this->assertStringContainsString('email', strtolower($body));
        // The valid row must NOT appear in the error CSV.
        $this->assertStringNotContainsString('good@example.com', $body);
    }

    /** @test */
    public function it_lists_imports_for_the_account_only(): void
    {
        Storage::fake(config('filesystems.default'));
        $user = $this->signIn();
        $this->json('POST', '/api/import', ['file' => $this->csvUpload($this->sampleCsv())]);

        // Another account's import must not leak into this account's list.
        $otherUser = factory(User::class)->create();
        $other = ContactImportJob::create([
            'account_id' => $otherUser->account_id,
            'user_id' => $otherUser->id,
            'filename' => 'other.csv',
            'total_rows' => 5,
            'status' => ContactImportJob::STATUS_COMPLETED,
        ]);

        $response = $this->json('GET', '/api/import')->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');

        $this->assertNotContains($other->id, $ids);
        $this->assertCount(1, $ids);
    }
}
