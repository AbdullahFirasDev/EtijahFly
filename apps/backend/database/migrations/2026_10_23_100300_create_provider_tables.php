<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registry of integrations. Credentials are NOT stored here: they live in the server
        // environment (config/providers.php) and are only reported as configured / missing.
        Schema::create('flight_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('kind', 20); // booking | airline_data
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_sandbox')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->jsonb('settings')->nullable();
            $table->string('health_status', 20)->default('unknown');
            $table->string('health_message')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code', 40);
            $table->string('operation', 40);
            $table->boolean('success');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 1000)->nullable();
            // Sanitised request summary only - never headers or credentials.
            $table->jsonb('context')->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider_code', 'created_at']);
            $table->index(['success', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_api_logs');
        Schema::dropIfExists('flight_providers');
    }
};
