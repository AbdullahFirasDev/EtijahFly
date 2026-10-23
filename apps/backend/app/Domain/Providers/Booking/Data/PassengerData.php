<?php

namespace App\Domain\Providers\Booking\Data;

use App\Domain\Bookings\Enums\PassengerType;

final readonly class PassengerData
{
    public function __construct(
        public PassengerType $type,
        public ?string $title,
        public string $firstName,
        public string $lastName,
        public string $gender,
        public string $dateOfBirth,
        public string $nationality,
        public string $documentType,
        public string $documentNumber,
        public string $documentExpiry,
        public string $documentCountry,
    ) {}
}
