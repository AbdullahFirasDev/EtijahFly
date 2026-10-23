<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class PassengerCounts
{
    public function __construct(
        public int $adults,
        public int $children = 0,
        public int $infants = 0,
    ) {}

    public function total(): int
    {
        return $this->adults + $this->children + $this->infants;
    }

    /** Seats required (lap infants do not occupy a seat). */
    public function seats(): int
    {
        return $this->adults + $this->children;
    }

    /** @param array{adults?: int, children?: int, infants?: int} $data */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['adults'] ?? 1), (int) ($data['children'] ?? 0), (int) ($data['infants'] ?? 0));
    }

    /** @return array{adults: int, children: int, infants: int} */
    public function toArray(): array
    {
        return ['adults' => $this->adults, 'children' => $this->children, 'infants' => $this->infants];
    }
}
