<?php

namespace App\Http\Resources;

use App\Domain\Bookings\Models\Traveler;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Traveler */
class TravelerResource extends JsonResource
{
    /** Set true only for the single-traveller endpoint used to pre-fill a booking form. */
    public bool $revealDocument = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            'document_type' => $this->document_type,
            'document_number_masked' => $this->maskedDocument(),
            'document_number' => $this->when($this->revealDocument, fn () => $this->document_number),
            'document_expiry' => $this->document_expiry?->toDateString(),
            'document_country' => $this->document_country,
            'email' => $this->email,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
