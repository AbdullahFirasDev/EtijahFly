<?php

namespace Database\Factories;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $departure = now()->addDays(20)->setTime(10, 0);

        return [
            'tenant_id' => Tenant::factory(),
            'reference' => 'EF-'.Str::upper(Str::random(6)),
            'provider_code' => 'mock',
            'is_test' => true,
            'status' => BookingStatus::Pending,
            'trip_type' => 'one_way',
            'cabin' => 'economy',
            'itinerary' => [[
                'origin' => 'BGW', 'destination' => 'DXB',
                'departure_at' => $departure->toIso8601String(), 'arrival_at' => $departure->copy()->addMinutes(170)->toIso8601String(),
                'duration_minutes' => 170, 'stops' => 0,
                'segments' => [[
                    'marketing_carrier' => 'FZ', 'operating_carrier' => 'FZ', 'flight_number' => 'FZ9214',
                    'origin' => 'BGW', 'destination' => 'DXB',
                    'departure_at' => $departure->toIso8601String(), 'arrival_at' => $departure->copy()->addMinutes(170)->toIso8601String(),
                    'duration_minutes' => 170, 'aircraft' => 'B38M', 'booking_class' => 'Y',
                ]],
            ]],
            'baggage' => ['checked' => '20KG', 'cabin' => '7KG'],
            'fare_rules' => ['refundable' => true],
            'refundable' => true,
            'passenger_counts' => ['adults' => 1, 'children' => 0, 'infants' => 0],
            'contact_email' => fake()->safeEmail(),
            'currency' => 'USD',
            'supplier_base' => '200.00',
            'supplier_taxes' => '61.00',
            'supplier_total' => '261.00',
            'markup_amount' => '13.05',
            'sale_total' => '274.05',
            'commission_amount' => '4.00',
            'agency_net' => '270.05',
            'platform_profit' => '9.05',
            'pricing' => ['provider_offer_id' => 'MOCK-FACTORY'],
            'account_currency' => 'USD',
        ];
    }
}
