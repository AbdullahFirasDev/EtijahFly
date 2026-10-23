<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travelers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 10)->nullable();
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('gender', 1)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->char('nationality', 2)->nullable();
            $table->string('document_type', 20)->default('passport');
            $table->text('document_number')->nullable(); // encrypted at rest (Eloquent cast)
            $table->date('document_expiry')->nullable();
            $table->char('document_country', 2)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'last_name']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('reference', 20)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ulid('flight_offer_id')->nullable();
            $table->string('provider_code', 40);
            $table->boolean('is_test')->default(false);
            $table->string('provider_booking_id')->nullable();
            $table->string('pnr', 20)->nullable();
            $table->string('status', 20)->index();
            $table->string('ticketing_status', 30)->nullable();
            $table->string('trip_type', 20);
            $table->string('cabin', 20);
            $table->jsonb('itinerary');
            $table->jsonb('baggage')->nullable();
            $table->jsonb('fare_rules')->nullable();
            $table->boolean('refundable')->default(false);
            $table->jsonb('passenger_counts');
            $table->string('contact_email');
            $table->string('contact_phone', 32)->nullable();

            // Pricing, in the offer currency.
            $table->char('currency', 3);
            $table->decimal('supplier_base', 14, 2);
            $table->decimal('supplier_taxes', 14, 2);
            $table->decimal('supplier_total', 14, 2);
            $table->decimal('markup_amount', 14, 2);
            $table->decimal('sale_total', 14, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->decimal('agency_net', 14, 2);
            $table->decimal('platform_profit', 14, 2);
            $table->jsonb('pricing');

            // Settlement, in the agency account currency (set when charged).
            $table->char('account_currency', 3);
            $table->decimal('fx_rate', 18, 6)->nullable();
            $table->decimal('charged_amount', 16, 2)->nullable();
            $table->decimal('commission_credited', 16, 2)->nullable();
            $table->foreignId('charge_transaction_id')->nullable()->constrained('ledger_transactions')->nullOnDelete();

            $table->timestamp('hold_expires_at')->nullable()->index();
            $table->timestamp('ticketing_deadline')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ticketed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_reason', 1000)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['provider_code', 'provider_booking_id']);
        });

        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('traveler_id')->nullable()->constrained('travelers')->nullOnDelete();
            $table->string('type', 3); // ADT | CHD | INF
            $table->string('title', 10)->nullable();
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('gender', 1);
            $table->date('date_of_birth');
            $table->char('nationality', 2);
            $table->string('document_type', 20)->default('passport');
            $table->text('document_number'); // encrypted
            $table->date('document_expiry');
            $table->char('document_country', 2);
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('ticket_number', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('type', 60);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('message', 1000);
            $table->jsonb('data')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 20)->default('user'); // user | system | provider
            $table->timestamp('created_at')->useCurrent();

            $table->index(['booking_id', 'id']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('booking_passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->string('ticket_number', 20);
            $table->string('airline_code', 3)->nullable();
            $table->string('status', 20)->default('issued'); // issued | void | refunded
            $table->boolean('is_test')->default(false);
            $table->timestamp('issued_at');
            $table->jsonb('provider_data')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'ticket_number']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('status', 20); // requested | pending | completed | rejected | failed
            $table->string('reason', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->nullable(); // provider | manual
            $table->char('currency', 3);
            $table->decimal('supplier_refund_amount', 14, 2)->nullable();
            $table->decimal('penalty_amount', 14, 2)->nullable();
            $table->decimal('refund_fee', 14, 2)->default(0);
            $table->char('account_currency', 3);
            $table->decimal('agency_refund_amount', 16, 2)->nullable();
            $table->decimal('commission_clawback', 16, 2)->nullable();
            $table->string('provider_refund_id')->nullable();
            $table->string('confirmation_reference')->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained('ledger_transactions')->nullOnDelete();
            $table->string('failure_reason', 1000)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('booking_events');
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('travelers');
    }
};
