<?php

namespace App\Services\Contact\ImportContacts;

use function Safe\preg_split;
use App\Services\BaseService;
use App\Models\Contact\Contact;
use App\Models\Contact\ContactFieldType;
use App\Services\Contact\Contact\CreateContact;
use App\Services\Contact\ContactField\CreateContactField;

/**
 * Maps a single parsed CSV row to a contact, reusing the existing CreateContact
 * / CreateContactField services. Validation failures throw a ValidationException
 * which the chunk job turns into a per-row error (the row is skipped, the batch
 * continues).
 */
class CreateContactFromRow extends BaseService
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
            'user_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
        ];
    }

    /**
     * Create a contact from one CSV row.
     *
     * @param  array  $data
     * @return Contact
     */
    public function execute(array $data): Contact
    {
        $this->validate($data);

        [$firstName, $lastName] = $this->splitName($data['name']);

        $contact = app(CreateContact::class)->execute([
            'account_id' => $data['account_id'],
            'author_id' => $data['user_id'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $this->nullOrValue($data, 'email'),
            'is_birthdate_known' => false,
            'is_deceased' => false,
            'is_deceased_date_known' => false,
        ]);

        $this->addPhone($data, $contact);

        return $contact;
    }

    /**
     * Split a single name field into first/last name on the first space.
     *
     * @param  string  $name
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * Add the phone number as a contact field, mirroring how CreateContact
     * handles email. No-op if the account has no phone field type or no phone.
     *
     * @param  array  $data
     * @param  Contact  $contact
     * @return void
     */
    private function addPhone(array $data, Contact $contact): void
    {
        $phone = $this->nullOrValue($data, 'phone');
        if (is_null($phone)) {
            return;
        }

        $contactFieldType = ContactFieldType::where([
            'account_id' => $data['account_id'],
            'type' => ContactFieldType::PHONE,
        ])->first();

        if (is_null($contactFieldType)) {
            return;
        }

        app(CreateContactField::class)->execute([
            'account_id' => $data['account_id'],
            'contact_id' => $contact->id,
            'contact_field_type_id' => $contactFieldType->id,
            'data' => $phone,
        ]);
    }
}
