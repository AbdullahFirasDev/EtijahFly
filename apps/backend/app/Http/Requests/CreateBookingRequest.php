<?php

namespace App\Http\Requests;

use App\Domain\Bookings\Enums\PassengerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBookingRequest extends FormRequest
{
    /** Airline systems accept Latin letters only in passenger names. */
    private const NAME = 'regex:/^[A-Za-z][A-Za-z \'\-]{0,59}$/';

    public function authorize(): bool
    {
        return $this->user()?->can('bookings.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'offer_id' => ['required', 'ulid'],
            'expected_total' => ['required', 'decimal:0,2', 'min:0'],
            'contact.email' => ['required', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'passengers' => ['required', 'array', 'min:1', 'max:9'],
            'passengers.*.traveler_id' => ['nullable', 'integer', Rule::exists('travelers', 'id')->where('tenant_id', $this->user()?->tenant_id)->whereNull('deleted_at')],
            'passengers.*.type' => ['required', Rule::enum(PassengerType::class)],
            'passengers.*.title' => ['nullable', Rule::in(['MR', 'MRS', 'MS', 'MISS', 'MSTR'])],
            'passengers.*.first_name' => ['required', 'string', self::NAME],
            'passengers.*.last_name' => ['required', 'string', self::NAME],
            'passengers.*.gender' => ['required', Rule::in(['M', 'F'])],
            'passengers.*.date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'passengers.*.nationality' => ['required', 'alpha', 'size:2'],
            'passengers.*.document_type' => ['nullable', Rule::in(['passport', 'national_id'])],
            'passengers.*.document_number' => ['required', 'alpha_num', 'min:5', 'max:20'],
            'passengers.*.document_expiry' => ['required', 'date_format:Y-m-d', 'after:today'],
            'passengers.*.document_country' => ['required', 'alpha', 'size:2'],
            'passengers.*.email' => ['nullable', 'email', 'max:255'],
            'passengers.*.phone' => ['nullable', 'string', 'max:32'],
            'save_travelers' => ['nullable', 'boolean'],
        ];
    }
}
