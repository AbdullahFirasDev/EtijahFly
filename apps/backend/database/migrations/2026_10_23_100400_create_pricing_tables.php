<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: a new rate supersedes the previous one from effective_at onwards.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 18, 6); // 1 base = rate quote
            $table->timestamp('effective_at');
            $table->string('source', 40)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['base_currency', 'quote_currency', 'effective_at']);
        });

        // One table for both markup (platform margin added to supplier price) and commission
        // (amount credited to the agency). Exactly one rule of each type applies per offer.
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20); // markup | commission
            $table->string('calculation', 20); // percent | fixed
            $table->decimal('value', 12, 4);
            $table->char('currency', 3)->nullable(); // required for fixed
            $table->string('basis', 20)->default('supplier_total'); // supplier_total | base_fare
            $table->decimal('min_amount', 14, 2)->nullable();
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->jsonb('airline_codes')->nullable();
            $table->string('cabin', 20)->nullable();
            $table->string('trip_type', 20)->nullable();
            $table->char('origin', 3)->nullable();
            $table->char('destination', 3)->nullable();
            $table->string('provider_code', 40)->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('exchange_rates');
    }
};
