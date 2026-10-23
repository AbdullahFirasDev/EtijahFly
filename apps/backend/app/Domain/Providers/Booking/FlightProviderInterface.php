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
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\ProviderTimeoutException;
use App\Domain\Providers\Exceptions\UnsupportedCapabilityException;

/**
 * Contract between the platform and any flight booking source (GDS, NDC aggregator,
 * consolidator API, ...). The platform never assumes a capability: callers check
 * capabilities() first and every method may throw UnsupportedCapabilityException.
 *
 * Error semantics every implementation must respect:
 *  - ProviderTimeoutException: the outcome is UNKNOWN (the provider may have acted).
 *    Callers must reconcile via retrieveBooking() and never assume success or failure.
 *  - Other ProviderException subclasses: the provider did NOT perform the operation.
 */
interface FlightProviderInterface
{
    public function code(): string;

    public function capabilities(): ProviderCapabilities;

    /** Sandbox/test providers return data that must never be presented as real inventory. */
    public function isSandbox(): bool;

    /** @throws ProviderException */
    public function search(SearchCriteria $criteria): SearchResult;

    /**
     * Re-price and check availability of a previously returned offer.
     *
     * @throws ProviderException
     */
    public function priceCheck(string $providerOfferId): PriceCheckResult;

    /**
     * Create the booking at the provider. With the `hold` capability this places a
     * reservation without payment (status held + expiry); without it, it is an instant
     * purchase and must only be called after the agency has been charged.
     *
     * @throws ProviderTimeoutException outcome unknown
     * @throws ProviderException
     */
    public function createBooking(BookingRequest $request): ProviderBookingResult;

    /** @throws ProviderException */
    public function retrieveBooking(string $providerBookingId): ProviderBookingResult;

    /**
     * Find a booking by the platform's client reference (used for reconciliation after timeouts).
     *
     * @throws ProviderException
     */
    public function findByClientReference(string $clientReference): ?ProviderBookingResult;

    /**
     * @throws ProviderTimeoutException outcome unknown
     * @throws ProviderException
     */
    public function issueTickets(string $providerBookingId): TicketingResult;

    /** @throws ProviderException */
    public function cancelBooking(string $providerBookingId): CancelResult;

    /** @throws ProviderException */
    public function refund(string $providerBookingId): RefundResult;

    /**
     * @throws UnsupportedCapabilityException
     * @throws ProviderException
     */
    public function healthCheck(): bool;
}
