<?php

namespace App\Domain\Providers\Booking;

use App\Domain\Providers\Booking\Data\BookingRequest;
use App\Domain\Providers\Booking\Data\CancelResult;
use App\Domain\Providers\Booking\Data\PriceCheckResult;
use App\Domain\Providers\Booking\Data\ProviderBookingResult;
use App\Domain\Providers\Booking\Data\ProviderCapabilities;
use App\Domain\Providers\Booking\Data\RefundResult;
use App\Domain\Providers\Booking\Data\SearchCriteria;
use App\Domain\Providers\Booking\Data\SearchResult;
use App\Domain\Providers\Booking\Data\TicketingResult;
use App\Domain\Providers\Exceptions\UnsupportedCapabilityException;
use App\Domain\Providers\ProviderCallLogger;

/**
 * Decorator applied to every booking provider: capability enforcement + call logging.
 */
class InstrumentedFlightProvider implements FlightProviderInterface
{
    public function __construct(
        private readonly FlightProviderInterface $inner,
        private readonly ProviderCallLogger $logger,
    ) {}

    public function inner(): FlightProviderInterface
    {
        return $this->inner;
    }

    public function code(): string
    {
        return $this->inner->code();
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->inner->capabilities();
    }

    public function isSandbox(): bool
    {
        return $this->inner->isSandbox();
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        $this->require('search');

        return $this->logger->call($this->code(), 'search', fn () => $this->inner->search($criteria), $criteria->toArray());
    }

    public function priceCheck(string $providerOfferId): PriceCheckResult
    {
        $this->require('priceCheck');

        return $this->logger->call($this->code(), 'price_check', fn () => $this->inner->priceCheck($providerOfferId), ['offer' => $providerOfferId]);
    }

    public function createBooking(BookingRequest $request): ProviderBookingResult
    {
        return $this->logger->call($this->code(), 'create_booking', fn () => $this->inner->createBooking($request), [
            'offer' => $request->providerOfferId,
            'client_reference' => $request->clientReference,
            'passengers' => count($request->passengers),
        ]);
    }

    public function retrieveBooking(string $providerBookingId): ProviderBookingResult
    {
        $this->require('retrieve');

        return $this->logger->call($this->code(), 'retrieve', fn () => $this->inner->retrieveBooking($providerBookingId), ['booking' => $providerBookingId]);
    }

    public function findByClientReference(string $clientReference): ?ProviderBookingResult
    {
        $this->require('retrieve');

        return $this->logger->call($this->code(), 'find_by_reference', fn () => $this->inner->findByClientReference($clientReference), ['client_reference' => $clientReference]);
    }

    public function issueTickets(string $providerBookingId): TicketingResult
    {
        $this->require('ticketing');

        return $this->logger->call($this->code(), 'issue_tickets', fn () => $this->inner->issueTickets($providerBookingId), ['booking' => $providerBookingId]);
    }

    public function cancelBooking(string $providerBookingId): CancelResult
    {
        $this->require('cancel');

        return $this->logger->call($this->code(), 'cancel', fn () => $this->inner->cancelBooking($providerBookingId), ['booking' => $providerBookingId]);
    }

    public function refund(string $providerBookingId): RefundResult
    {
        $this->require('refund');

        return $this->logger->call($this->code(), 'refund', fn () => $this->inner->refund($providerBookingId), ['booking' => $providerBookingId]);
    }

    public function healthCheck(): bool
    {
        return $this->logger->call($this->code(), 'health_check', fn () => $this->inner->healthCheck());
    }

    private function require(string $capability): void
    {
        if (! ($this->inner->capabilities()->{$capability} ?? false)) {
            throw UnsupportedCapabilityException::for($this->code(), $capability);
        }
    }
}
