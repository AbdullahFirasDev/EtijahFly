<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $search = false,
        public bool $priceCheck = false,
        public bool $hold = false,
        public bool $retrieve = false,
        public bool $ticketing = false,
        public bool $cancel = false,
        public bool $refund = false,
        public bool $multiCity = false,
        public bool $roundTrip = false,
    ) {}

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'price_check' => $this->priceCheck,
            'hold' => $this->hold,
            'retrieve' => $this->retrieve,
            'ticketing' => $this->ticketing,
            'cancel' => $this->cancel,
            'refund' => $this->refund,
            'multi_city' => $this->multiCity,
            'round_trip' => $this->roundTrip,
        ];
    }
}
