<?php

namespace Database\Factories;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' LLC',
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'status' => TenantStatus::Active,
            'country_code' => 'IQ',
            'default_currency' => 'USD',
            'contact_email' => fake()->unique()->companyEmail(),
            'contact_phone' => '+964770'.fake()->numerify('#######'),
            'timezone' => 'Asia/Baghdad',
            'locale' => 'ar',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Suspended, 'suspended_at' => now()]);
    }

    public function currency(string $currency): static
    {
        return $this->state(fn () => ['default_currency' => $currency]);
    }
}
