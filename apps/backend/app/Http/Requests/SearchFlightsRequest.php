<?php

namespace App\Http\Requests;

use App\Domain\FlightSearch\Enums\CabinClass;
use App\Domain\FlightSearch\Enums\TripType;
use App\Domain\Providers\Booking\Data\PassengerCounts;
use App\Domain\Providers\Booking\Data\SearchCriteria;
use App\Domain\Providers\Booking\Data\SearchSlice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SearchFlightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('flights.search') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $airport = Rule::exists('airports', 'iata_code')->where('is_active', true);

        return [
            'trip_type' => ['required', Rule::enum(TripType::class)],
            'slices' => ['required', 'array', 'min:1', 'max:6'],
            'slices.*.origin' => ['required', 'string', 'size:3', $airport],
            'slices.*.destination' => ['required', 'string', 'size:3', 'different:slices.*.origin', $airport],
            'slices.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:'.now()->addDays(360)->toDateString()],
            'passengers.adults' => ['required', 'integer', 'min:1', 'max:9'],
            'passengers.children' => ['nullable', 'integer', 'min:0', 'max:8'],
            'passengers.infants' => ['nullable', 'integer', 'min:0', 'max:9'],
            'cabin' => ['required', Rule::enum(CabinClass::class)],
            'max_stops' => ['nullable', 'integer', 'min:0', 'max:3'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $slices = collect($this->input('slices', []))->map(fn ($s) => is_array($s) ? [
            'origin' => strtoupper((string) ($s['origin'] ?? '')),
            'destination' => strtoupper((string) ($s['destination'] ?? '')),
            'date' => $s['date'] ?? null,
        ] : $s)->all();

        $this->merge(['slices' => $slices]);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $slices = $this->input('slices');
            $expected = match ($this->input('trip_type')) {
                TripType::OneWay->value => [1, 1],
                TripType::RoundTrip->value => [2, 2],
                default => [2, 6],
            };
            if (count($slices) < $expected[0] || count($slices) > $expected[1]) {
                $validator->errors()->add('slices', __('validation.size.array', ['attribute' => 'slices', 'size' => $expected[0]]));
            }

            for ($i = 1; $i < count($slices); $i++) {
                if ($slices[$i]['date'] < $slices[$i - 1]['date']) {
                    $validator->errors()->add("slices.{$i}.date", __('validation.after_or_equal', ['attribute' => 'date', 'date' => $slices[$i - 1]['date']]));
                }
            }

            $adults = (int) $this->input('passengers.adults');
            if ((int) $this->input('passengers.infants', 0) > $adults) {
                $validator->errors()->add('passengers.infants', __('errors.too_many_infants'));
            }
            if ($adults + (int) $this->input('passengers.children', 0) > 9) {
                $validator->errors()->add('passengers', __('validation.max.numeric', ['attribute' => 'passengers', 'max' => 9]));
            }
        }];
    }

    public function criteria(): SearchCriteria
    {
        return new SearchCriteria(
            tripType: TripType::from($this->input('trip_type')),
            slices: array_map(fn (array $s) => new SearchSlice($s['origin'], $s['destination'], $s['date']), $this->input('slices')),
            passengers: PassengerCounts::fromArray($this->input('passengers')),
            cabin: CabinClass::from($this->input('cabin')),
            maxStops: $this->filled('max_stops') ? (int) $this->input('max_stops') : null,
        );
    }
}
