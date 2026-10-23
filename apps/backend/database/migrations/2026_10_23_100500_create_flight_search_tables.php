<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trip_type', 20);
            $table->jsonb('slices');
            $table->jsonb('passengers');
            $table->string('cabin', 20);
            $table->string('status', 20); // completed | partial | failed
            $table->unsignedInteger('result_count')->default(0);
            $table->jsonb('provider_errors')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('flight_offers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('flight_search_id')->constrained('flight_searches')->cascadeOnDelete();
            $table->string('provider_code', 40);
            $table->string('provider_offer_id', 512);
            $table->boolean('is_test')->default(false);
            $table->string('validating_airline', 3)->nullable();
            $table->jsonb('itinerary'); // slices -> segments
            $table->jsonb('baggage')->nullable();
            $table->jsonb('fare_rules')->nullable();
            $table->boolean('refundable')->default(false);
            $table->string('cabin', 20);
            $table->jsonb('passengers');
            // Supplier fare (what the provider charges the platform)
            $table->char('currency', 3);
            $table->decimal('supplier_base', 14, 2);
            $table->decimal('supplier_taxes', 14, 2);
            $table->decimal('supplier_total', 14, 2);
            // Priced at search time; re-priced before booking.
            $table->jsonb('pricing');
            $table->decimal('sale_total', 14, 2);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_offers');
        Schema::dropIfExists('flight_searches');
    }
};
